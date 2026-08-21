<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Exceptions\Integrations\ProviderDisabledException;
use App\Integrations\Contracts\CalendarProvider;
use App\Integrations\Contracts\EmailProvider;
use App\Integrations\Contracts\WhatsAppProvider;
use App\Integrations\Contracts\WebhookVerifier;
use App\Models\IntegrationAccount;
use InvalidArgumentException;

/**
 * Resolves provider adapters from {@see config('integrations.providers')}.
 *
 * The factory is intentionally channel-oriented (email / whatsapp /
 * calendar / webforms) instead of provider-oriented: the `channel`
 * argument decides whether the call is even allowed (feature flag),
 * the `provider` argument picks the concrete class.
 *
 * Returns null (instead of throwing) when:
 *   - the channel is disabled;
 *   - the provider slug is not registered;
 *   - no concrete class is configured (yet).
 *
 * Throws {@see ProviderDisabledException} when the caller explicitly
 * opts-in to a hard failure via the `mustBeEnabled(): bool` argument.
 */
class AdapterFactory
{
    /**
     * Resolve an email adapter.
     *
     * `$account` is optional; when omitted, the factory returns the
     * adapter bound to the supplied provider slug with no account
     * context (used by health checks and tests).
     *
     * @return EmailProvider|null
     */
    public function email(string $provider, ?IntegrationAccount $account = null, bool $mustBeEnabled = false): ?EmailProvider
    {
        return $this->resolve('email', $provider, $account, $mustBeEnabled, EmailProvider::class);
    }

    /**
     * @return WhatsAppProvider|null
     */
    public function whatsapp(string $provider, ?IntegrationAccount $account = null, bool $mustBeEnabled = false): ?WhatsAppProvider
    {
        return $this->resolve('whatsapp', $provider, $account, $mustBeEnabled, WhatsAppProvider::class);
    }

    /**
     * @return CalendarProvider|null
     */
    public function calendar(string $provider, ?IntegrationAccount $account = null, bool $mustBeEnabled = false): ?CalendarProvider
    {
        return $this->resolve('calendar', $provider, $account, $mustBeEnabled, CalendarProvider::class);
    }

    /**
     * Resolve a webhook signature verifier for the named provider.
     *
     * Returns null when no verifier is registered (Outlook in B11).
     * The middleware uses null to mean "skip signature check, fall
     * back to token-based verification at the controller level".
     *
     * @return WebhookVerifier|null
     */
    public function webhookVerifier(string $provider): ?WebhookVerifier
    {
        $map = config('integrations.webhook_verifiers', []);

        $class = $map[$provider] ?? null;

        if ($class === null) {
            return null;
        }

        if (! class_exists($class)) {
            return null;
        }

        $instance = app($class);

        if (! $instance instanceof WebhookVerifier) {
            return null;
        }

        return $instance;
    }

    /**
     * @template T
     *
     * @param  class-string<T>  $contract
     * @return T|null
     */
    private function resolve(
        string $channel,
        string $provider,
        ?IntegrationAccount $account,
        bool $mustBeEnabled,
        string $contract,
    ): ?object {
        if (! $this->isChannelEnabled($channel)) {
            if ($mustBeEnabled) {
                throw new ProviderDisabledException($channel, $provider);
            }

            return null;
        }

        $registry = config("integrations.providers.{$channel}", []);

        $entry = $registry[$provider] ?? null;

        if ($entry === null) {
            return null;
        }

        $class = $entry['class'] ?? null;

        if ($class === null || ! class_exists($class)) {
            return null;
        }

        $instance = app($class);

        if (! $instance instanceof $contract) {
            throw new InvalidArgumentException(sprintf(
                'Adapter %s does not implement the expected contract %s.',
                $class,
                $contract,
            ));
        }

        if (method_exists($instance, 'bindAccount') && $account !== null) {
            $instance->bindAccount($account);
        }

        return $instance;
    }

    private function isChannelEnabled(string $channel): bool
    {
        $flags = (array) config('integrations.enabled', []);

        return (bool) ($flags[$channel] ?? false);
    }
}