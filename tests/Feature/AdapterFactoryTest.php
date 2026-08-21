<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\Integrations\ProviderDisabledException;
use App\Integrations\Contracts\EmailProvider;
use App\Integrations\Contracts\WebhookVerifier;
use App\Integrations\Services\AdapterFactory;
use App\Integrations\Verification\MetaSignatureVerifier;
use Tests\Support\DummyEmailAdapter;
use Tests\TestCase;

/**
 * AdapterFactory — disabled provider returns null; enabled provider
 * returns the contract implementation; mustBeEnabled throws.
 *
 * Concrete adapters land in B13..B17; this test verifies the factory's
 * registry resolution using a test-only {@see DummyEmailAdapter}.
 */
class AdapterFactoryTest extends TestCase
{
    private AdapterFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        // The verifier constructor requires an appSecret; provide one so
        // the container can resolve MetaSignatureVerifier when the factory
        // asks for it.
        $this->app->bind(
            MetaSignatureVerifier::class,
            fn () => new MetaSignatureVerifier('test-app-secret'),
        );
        $this->factory = app(AdapterFactory::class);
    }

    public function test_disabled_channel_returns_null(): void
    {
        config(['integrations.enabled.email' => false]);
        config(['integrations.providers.email.smtp.class' => DummyEmailAdapter::class]);

        $this->assertNull($this->factory->email('smtp'));
    }

    public function test_disabled_channel_with_must_be_enabled_throws(): void
    {
        config(['integrations.enabled.email' => false]);
        config(['integrations.providers.email.smtp.class' => DummyEmailAdapter::class]);

        $this->expectException(ProviderDisabledException::class);
        $this->factory->email('smtp', mustBeEnabled: true);
    }

    public function test_enabled_channel_returns_implementation(): void
    {
        config(['integrations.enabled.email' => true]);
        config(['integrations.providers.email.smtp.class' => DummyEmailAdapter::class]);

        $adapter = $this->factory->email('smtp');

        $this->assertInstanceOf(EmailProvider::class, $adapter);
        $this->assertInstanceOf(DummyEmailAdapter::class, $adapter);
    }

    public function test_unknown_provider_slug_returns_null(): void
    {
        config(['integrations.enabled.email' => true]);

        $this->assertNull($this->factory->email('nonexistent'));
    }

    public function test_unconfigured_class_returns_null(): void
    {
        config(['integrations.enabled.email' => true]);
        config(['integrations.providers.email.smtp.class' => null]);

        $this->assertNull($this->factory->email('smtp'));
    }

    public function test_webhook_verifier_returns_registered_class(): void
    {
        config(['integrations.webhook_verifiers.meta' => MetaSignatureVerifier::class]);

        $verifier = $this->factory->webhookVerifier('meta');

        $this->assertInstanceOf(WebhookVerifier::class, $verifier);
    }

    public function test_webhook_verifier_returns_null_when_unregistered(): void
    {
        config(['integrations.webhook_verifiers.outlook' => null]);

        $this->assertNull($this->factory->webhookVerifier('outlook'));
    }
}