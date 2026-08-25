<?php

declare(strict_types=1);

namespace App\Services\Google;

use App\Models\IntegrationAccount;
use App\Models\OAuthState;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class GoogleOAuthService
{
    private const PROVIDER = 'google';

    /** @var list<string> */
    private const BASE_SCOPES = ['openid', 'email', 'profile'];

    /** @var array<string, string> */
    private const SERVICE_SCOPES = [
        'gmail' => 'https://www.googleapis.com/auth/gmail.send',
        'calendar' => 'https://www.googleapis.com/auth/calendar.events',
    ];

    public function accountFor(User $user): ?IntegrationAccount
    {
        return IntegrationAccount::query()
            ->active()
            ->where('provider', self::PROVIDER)
            ->where('owner_id', $user->getKey())
            ->first();
    }

    /**
     * @return array{account: IntegrationAccount|null, status: string, services: array{gmail: bool, calendar: bool}, email: string|null, scopes: list<string>}
     */
    public function statusFor(User $user): array
    {
        $account = $this->accountFor($user);
        $config = (array) ($account?->config_json ?? []);
        $services = (array) ($config['services'] ?? []);

        return [
            'account' => $account,
            'status' => (string) ($config['status'] ?? ($account === null ? 'not_connected' : 'connected')),
            'services' => [
                'gmail' => (bool) ($services['gmail'] ?? false),
                'calendar' => (bool) ($services['calendar'] ?? false),
            ],
            'email' => isset($config['google_account_email']) ? (string) $config['google_account_email'] : null,
            'scopes' => array_values(array_filter((array) ($account?->scopes ?? []), 'is_string')),
        ];
    }

    public function authorizationRedirect(User $user, string $service): string
    {
        $this->assertKnownService($service);

        $scopes = $this->scopesFor($service);
        $state = Str::random(64);
        $account = $this->accountFor($user);
        $credentials = (array) ($account?->credentials_encrypted ?? []);

        OAuthState::query()->create([
            'provider' => self::PROVIDER,
            'state' => $state,
            'redirect_after' => route('account.integrations.index'),
            'payload_json' => [
                'user_id' => (int) $user->getKey(),
                'service' => $service,
                'scopes' => $scopes,
            ],
            'expires_at' => now()->addMinutes(10),
        ]);

        $params = [
            'client_id' => (string) config('integrations.google_oauth.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', $scopes),
            'state' => $state,
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
        ];

        if (empty($credentials['refresh_token'])) {
            $params['prompt'] = 'consent';
        }

        return (string) config('integrations.google_oauth.auth_uri').'?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public function handleCallback(User $user, string $state, string $code): IntegrationAccount
    {
        $oauthState = $this->consumeState($user, $state);
        $payload = (array) $oauthState->payload_json;
        $service = (string) ($payload['service'] ?? '');
        $this->assertKnownService($service);

        $token = $this->exchangeCode($code);
        $accessToken = (string) ($token['access_token'] ?? '');
        if ($accessToken === '') {
            throw new RuntimeException('Google did not return an access token.');
        }

        $profile = $this->fetchUserInfo($accessToken);
        $googleEmail = (string) ($profile['email'] ?? '');
        if ($googleEmail === '') {
            throw new RuntimeException('Google did not return an account email.');
        }

        return DB::transaction(function () use ($user, $service, $token, $accessToken, $googleEmail, $profile, $payload): IntegrationAccount {
            /** @var IntegrationAccount|null $account */
            $account = IntegrationAccount::withTrashed()
                ->where('provider', self::PROVIDER)
                ->where('owner_id', $user->getKey())
                ->whereNull('deleted_at')
                ->first();

            if ($account === null) {
                $account = new IntegrationAccount([
                    'provider' => self::PROVIDER,
                    'owner_id' => $user->getKey(),
                    'label' => 'Google Workspace — '.$googleEmail,
                ]);
            }

            $existingCredentials = (array) ($account->credentials_encrypted ?? []);
            $refreshToken = (string) ($token['refresh_token'] ?? ($existingCredentials['refresh_token'] ?? ''));
            if ($refreshToken === '') {
                throw new RuntimeException('Google did not return a refresh token for this connection.');
            }

            $expiresAt = now()->addSeconds((int) ($token['expires_in'] ?? 3600));
            $existingConfig = (array) ($account->config_json ?? []);
            $services = (array) ($existingConfig['services'] ?? []);
            $services[$service] = true;

            $authorizedScopes = $this->mergeScopes(
                (array) ($account->scopes ?? []),
                (array) ($payload['scopes'] ?? []),
                isset($token['scope']) ? explode(' ', (string) $token['scope']) : []
            );

            $account->forceFill([
                'provider' => self::PROVIDER,
                'label' => 'Google Workspace — '.$googleEmail,
                'owner_id' => $user->getKey(),
                'team_id' => null,
                'is_shared' => false,
                'is_active' => true,
                'test_mode' => false,
                'config_json' => array_merge($existingConfig, [
                    'google_account_email' => $googleEmail,
                    'google_account_name' => $profile['name'] ?? null,
                    'services' => [
                        'gmail' => (bool) ($services['gmail'] ?? false),
                        'calendar' => (bool) ($services['calendar'] ?? false),
                    ],
                    'status' => 'connected',
                    'disconnected_at' => null,
                    'reconnection_required_at' => null,
                ]),
                'credentials_encrypted' => [
                    'access_token' => $accessToken,
                    'refresh_token' => $refreshToken,
                    'token_type' => (string) ($token['token_type'] ?? 'Bearer'),
                    'expires_at' => $expiresAt->toIso8601String(),
                ],
                'scopes' => $authorizedScopes,
                'expires_at' => $expiresAt,
                'last_refresh_at' => now(),
                'error_class' => null,
                'error_message' => null,
            ]);
            $account->save();

            return $account->refresh();
        });
    }

    public function accessTokenFor(IntegrationAccount $account): string
    {
        $credentials = (array) ($account->credentials_encrypted ?? []);
        $accessToken = (string) ($credentials['access_token'] ?? '');
        $refreshToken = (string) ($credentials['refresh_token'] ?? '');
        $expiresAt = $account->expires_at;

        if ($accessToken !== '' && ($expiresAt === null || $expiresAt->greaterThan(now()->addMinute()))) {
            return $accessToken;
        }

        if ($refreshToken === '') {
            throw new RuntimeException('Google refresh token is not available.');
        }

        $token = $this->refreshAccessToken($refreshToken);
        $accessToken = (string) ($token['access_token'] ?? '');
        if ($accessToken === '') {
            throw new RuntimeException('Google did not return an access token during refresh.');
        }

        $newExpiresAt = now()->addSeconds((int) ($token['expires_in'] ?? 3600));
        $account->forceFill([
            'credentials_encrypted' => array_merge($credentials, [
                'access_token' => $accessToken,
                'token_type' => (string) ($token['token_type'] ?? ($credentials['token_type'] ?? 'Bearer')),
                'expires_at' => $newExpiresAt->toIso8601String(),
            ]),
            'expires_at' => $newExpiresAt,
            'last_refresh_at' => now(),
            'error_class' => null,
            'error_message' => null,
        ])->save();

        return $accessToken;
    }

    public function disableService(User $user, string $service): IntegrationAccount
    {
        $this->assertKnownService($service);
        $account = $this->requireAccount($user);
        $config = (array) ($account->config_json ?? []);
        $services = (array) ($config['services'] ?? []);
        $services[$service] = false;

        $account->forceFill([
            'config_json' => array_merge($config, [
                'services' => [
                    'gmail' => (bool) ($services['gmail'] ?? false),
                    'calendar' => (bool) ($services['calendar'] ?? false),
                ],
                'status' => 'connected',
            ]),
        ])->save();

        return $account->refresh();
    }

    public function disconnect(User $user): IntegrationAccount
    {
        $account = $this->requireAccount($user);
        $credentials = (array) ($account->credentials_encrypted ?? []);
        $token = (string) ($credentials['refresh_token'] ?? ($credentials['access_token'] ?? ''));

        if ($token !== '') {
            try {
                Http::asForm()->post((string) config('integrations.google_oauth.revoke_uri'), [
                    'token' => $token,
                ]);
            } catch (ConnectionException) {
                // Local disconnect must still succeed; external revocation
                // failures are reported through the account error fields.
                $account->forceFill([
                    'error_class' => 'GoogleRevokeConnectionException',
                    'error_message' => 'Google token revocation could not be confirmed.',
                ]);
            }
        }

        $config = (array) ($account->config_json ?? []);
        $account->forceFill([
            'is_active' => false,
            'google_active_owner_id' => null,
            'credentials_encrypted' => null,
            'config_json' => array_merge($config, [
                'services' => ['gmail' => false, 'calendar' => false],
                'status' => 'disconnected',
                'disconnected_at' => now()->toIso8601String(),
            ]),
            'expires_at' => null,
        ])->save();

        return $account->refresh();
    }

    private function requireAccount(User $user): IntegrationAccount
    {
        $account = $this->accountFor($user);
        if ($account === null) {
            throw new RuntimeException('Google is not connected for this user.');
        }

        return $account;
    }

    private function consumeState(User $user, string $state): OAuthState
    {
        return DB::transaction(function () use ($user, $state): OAuthState {
            /** @var OAuthState|null $oauthState */
            $oauthState = OAuthState::query()
                ->where('provider', self::PROVIDER)
                ->where('state', $state)
                ->unconsumed()
                ->valid()
                ->lockForUpdate()
                ->first();

            if ($oauthState === null) {
                throw new RuntimeException('OAuth state is invalid, expired, or already consumed.');
            }

            $payload = (array) $oauthState->payload_json;
            if ((int) ($payload['user_id'] ?? 0) !== (int) $user->getKey()) {
                throw new RuntimeException('OAuth state does not belong to this user.');
            }

            $updated = OAuthState::query()
                ->whereKey($oauthState->getKey())
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            if ($updated !== 1) {
                throw new RuntimeException('OAuth state was already consumed.');
            }

            return $oauthState->refresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function exchangeCode(string $code): array
    {
        $response = Http::asForm()->post((string) config('integrations.google_oauth.token_uri'), [
            'client_id' => (string) config('integrations.google_oauth.client_id'),
            'client_secret' => (string) config('integrations.google_oauth.client_secret'),
            'redirect_uri' => $this->redirectUri(),
            'grant_type' => 'authorization_code',
            'code' => $code,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Google token exchange failed.');
        }

        return $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    private function refreshAccessToken(string $refreshToken): array
    {
        $response = Http::asForm()->post((string) config('integrations.google_oauth.token_uri'), [
            'client_id' => (string) config('integrations.google_oauth.client_id'),
            'client_secret' => (string) config('integrations.google_oauth.client_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Google token refresh failed.');
        }

        return $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchUserInfo(string $accessToken): array
    {
        $response = Http::withToken($accessToken)->get((string) config('integrations.google_oauth.userinfo_uri'));

        if (! $response->successful()) {
            throw new RuntimeException('Google userinfo request failed.');
        }

        return $response->json();
    }

    /**
     * @return list<string>
     */
    private function scopesFor(string $service): array
    {
        $this->assertKnownService($service);

        return array_values(array_unique([...self::BASE_SCOPES, self::SERVICE_SCOPES[$service]]));
    }

    private function assertKnownService(string $service): void
    {
        if (! array_key_exists($service, self::SERVICE_SCOPES)) {
            throw new InvalidArgumentException('Unknown Google service.');
        }
    }

    /**
     * @param  array<int, mixed>  ...$groups
     * @return list<string>
     */
    private function mergeScopes(array ...$groups): array
    {
        $scopes = [];
        foreach ($groups as $group) {
            foreach ($group as $scope) {
                if (is_string($scope) && $scope !== '') {
                    $scopes[] = $scope;
                }
            }
        }

        return array_values(array_unique($scopes));
    }

    private function redirectUri(): string
    {
        $configured = (string) config('integrations.google_oauth.redirect_uri');

        return $configured !== '' ? $configured : URL::route('account.integrations.google.callback');
    }
}
