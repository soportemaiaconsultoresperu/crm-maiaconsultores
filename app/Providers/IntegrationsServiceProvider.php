<?php

declare(strict_types=1);

namespace App\Providers;

use App\Integrations\Contracts\CalendarProvider;
use App\Integrations\Contracts\EmailProvider;
use App\Integrations\Contracts\WhatsAppProvider;
use App\Integrations\Services\AdapterFactory;
use App\Integrations\Services\CredentialCipher;
use App\Integrations\Services\IdempotencyStore;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

/**
 * V2 Integrations service provider — B11.
 *
 * Responsibilities:
 *   - register the cross-cutting V2 services as singletons;
 *   - publish config/integrations.php so installations can override it;
 *   - defer the heavy work (class instantiation) to first resolution.
 *
 * Per docs/v2/01-roadmap.md C-05 the Livewire package stays in composer
 * for B12..B17 to use; this provider does NOT register Livewire itself
 * (that is handled by LivewireServiceProvider auto-discovery).
 */
class IntegrationsServiceProvider extends ServiceProvider
{
    /**
     * Register V2 integration services. Bindings are lazy.
     */
    public function register(): void
    {
        // Cross-cutting helpers ------------------------------------------------
        $this->app->singleton(CredentialCipher::class, fn () => new CredentialCipher());

        $this->app->singleton(
            IdempotencyStore::class,
            fn ($app) => new IdempotencyStore($app->make(CacheFactory::class)),
        );

        $this->app->singleton(AdapterFactory::class, fn () => new AdapterFactory());

        // Contract-to-implementation bindings for IDE/static analysis. The
        // real resolution happens in AdapterFactory which honours the
        // config('integrations.providers') registry.
        $this->app->bind(EmailProvider::class, function ($app, array $params = []) {
            $provider = $params['provider'] ?? 'smtp';
            $account = $params['account'] ?? null;

            return $app->make(AdapterFactory::class)->email(
                (string) $provider,
                $account,
            );
        });

        $this->app->bind(WhatsAppProvider::class, function ($app, array $params = []) {
            $provider = $params['provider'] ?? 'meta';
            $account = $params['account'] ?? null;

            return $app->make(AdapterFactory::class)->whatsapp(
                (string) $provider,
                $account,
            );
        });

        $this->app->bind(CalendarProvider::class, function ($app, array $params = []) {
            $provider = $params['provider'] ?? 'google';
            $account = $params['account'] ?? null;

            return $app->make(AdapterFactory::class)->calendar(
                (string) $provider,
                $account,
            );
        });
    }

    /**
     * Bootstrap V2 integrations. We don't register the PII processor
     * here because Laravel's logging system reads `tap` from the
     * channel config (see config/logging.php); doing it here would
     * double-register.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                $this->configPath() => config_path('integrations.php'),
            ], 'integrations-config');
        }

        // Sanity-check the integration config at boot so misconfigured
        // installations fail fast (no silent "disabled" surprises).
        if (config('integrations.enabled.email') === null) {
            Log::warning('IntegrationsServiceProvider: integrations.enabled.email is unset.');
        }
    }

    private function configPath(): string
    {
        return __DIR__.'/../../config/integrations.php';
    }
}