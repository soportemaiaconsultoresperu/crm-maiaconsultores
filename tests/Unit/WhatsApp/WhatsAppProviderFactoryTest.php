<?php

declare(strict_types=1);

namespace Tests\Unit\WhatsApp;

use App\Contracts\WhatsApp\WhatsAppProvider;
use App\Contracts\WhatsApp\WhatsAppProviderFactory;
use App\Models\WhatsApp\WhatsAppAccount;
use App\Services\WhatsApp\MetaWhatsAppProvider;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * B14 Pasada B-1 — Unit test for the WhatsApp provider factory.
 *
 * The factory exists so a future BSP (Twilio, MessageBird, ...) can drop in
 * without changing call sites (decision 12b — contract swap-ready). Today
 * only the Meta Cloud API is supported; unknown providers MUST raise so
 * that misconfigurations surface loudly instead of silently degrading to
 * a no-op adapter.
 */
class WhatsAppProviderFactoryTest extends TestCase
{
    public function test_factory_for_meta_account_returns_meta_provider(): void
    {
        $factory = new WhatsAppProviderFactory();

        $account = new WhatsAppAccount([
            'phone_number' => '+15551234567',
            'phone_number_id' => '1234567890',
            'business_id' => 'fake-access-token-for-test',
            'status' => WhatsAppAccount::STATUS_VERIFIED,
        ]);

        $provider = $factory->for($account);

        $this->assertInstanceOf(WhatsAppProvider::class, $provider);
        $this->assertInstanceOf(MetaWhatsAppProvider::class, $provider);
    }

    public function test_factory_make_with_known_provider_returns_concrete_instance(): void
    {
        $factory = new WhatsAppProviderFactory();

        $provider = $factory->make('meta');

        $this->assertInstanceOf(MetaWhatsAppProvider::class, $provider);
        $this->assertInstanceOf(WhatsAppProvider::class, $provider);
    }

    public function test_factory_throws_for_unknown_provider(): void
    {
        $factory = new WhatsAppProviderFactory();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unknown whatsapp provider/i');

        $factory->make('twilio');
    }
}