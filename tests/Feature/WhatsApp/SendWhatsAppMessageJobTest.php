<?php

declare(strict_types=1);

namespace Tests\Feature\WhatsApp;

use App\Contracts\WhatsApp\WhatsAppProviderFactory;
use App\Jobs\V2\SendWhatsAppMessage;
use App\Models\WhatsApp\WhatsAppAccount;
use App\Models\WhatsApp\WhatsAppConversation;
use App\Models\WhatsApp\WhatsAppMessage;
use App\Models\WhatsApp\WhatsAppTemplate;
use App\Services\WhatsApp\Exceptions\NotImplementedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * E-3 — the outbound WhatsApp job had ZERO execution coverage: every test
 * asserted `queued` + `Bus::assertDispatched`, i.e. exactly where the defect
 * started, and the free-form branch marked every inbox reply as failed with
 * `NoTemplate` without ever calling the provider.
 *
 * These tests run the job for real and assert WHERE THE MESSAGE ENDED UP:
 *   - free-form → the provider's free-form path, `sent` + wamid on success;
 *   - provider rejection → `failed` with the provider's reason persisted;
 *   - tracked closed window / opted-out conversation → blocked with an
 *     explicit reason and no API call;
 *   - template messages, the already-sent short-circuit and disabled accounts
 *     keep their existing behaviour (approval coverage).
 */
class SendWhatsAppMessageJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_freeform_message_is_delivered_through_the_provider_free_form_path(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.FREEFORM-OK']],
            ], 200),
        ]);

        $account = $this->makeAccount(businessId: 'access-token');
        $conversation = $this->makeConversation($account);
        $message = $this->makeFreeFormMessage($conversation, 'Hola, ¿en qué te ayudo?');

        (new SendWhatsAppMessage($message->id))->handle(app(WhatsAppProviderFactory::class));

        $message->refresh();

        $this->assertSame(WhatsAppMessage::STATUS_SENT, $message->status);
        $this->assertSame('wamid.FREEFORM-OK', $message->wamid);
        $this->assertNotNull($message->sent_at);
        $this->assertNull($message->error_class);
        $this->assertNull($message->error_message);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/messages')
            && $request['messaging_product'] === 'whatsapp'
            && $request['to'] === $conversation->phone_number
            && $request['type'] === 'text'
            && $request['text']['body'] === 'Hola, ¿en qué te ayudo?');
    }

    public function test_freeform_message_records_the_provider_rejection_as_an_honest_failure(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => [
                    'message' => 'Message failed to send because more than 24 hours have passed since the customer last replied',
                    'code' => 131047,
                ],
            ], 400),
        ]);

        $account = $this->makeAccount(businessId: 'access-token');
        $conversation = $this->makeConversation($account);
        $message = $this->makeFreeFormMessage($conversation, 'Hola!');

        (new SendWhatsAppMessage($message->id))->handle(app(WhatsAppProviderFactory::class));

        $message->refresh();

        $this->assertSame(WhatsAppMessage::STATUS_FAILED, $message->status);
        $this->assertSame(\RuntimeException::class, $message->error_class);
        $this->assertStringContainsString('returned 400', (string) $message->error_message);
        $this->assertStringContainsString('more than 24 hours', (string) $message->error_message);
        $this->assertNull($message->wamid);
        $this->assertNull($message->sent_at);
    }

    public function test_freeform_message_without_configured_credentials_is_failed_with_the_provider_reason(): void
    {
        // No Graph API credentials => the provider returns the canonical
        // NotImplementedException envelope. The message must NOT be reported
        // as sent, and the provider's reason must be persisted.
        Http::fake();

        $account = $this->makeAccount(businessId: null);
        $conversation = $this->makeConversation($account);
        $message = $this->makeFreeFormMessage($conversation, 'Hola!');

        (new SendWhatsAppMessage($message->id))->handle(app(WhatsAppProviderFactory::class));

        $message->refresh();

        $this->assertSame(WhatsAppMessage::STATUS_FAILED, $message->status);
        $this->assertSame(NotImplementedException::class, $message->error_class);
        $this->assertStringContainsString('credentials not configured', (string) $message->error_message);
        $this->assertNull($message->sent_at);
        Http::assertNothingSent();
    }

    public function test_freeform_message_outside_the_customer_service_window_is_not_sent(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.SHOULD-NEVER-HAPPEN']],
            ], 200),
        ]);

        $account = $this->makeAccount(businessId: 'access-token');
        $conversation = $this->makeConversation($account, [
            'window_opens_at' => now()->subHours(30),
            'window_closes_at' => now()->subHours(6),
        ]);
        $message = $this->makeFreeFormMessage($conversation, 'Hola!');

        (new SendWhatsAppMessage($message->id))->handle(app(WhatsAppProviderFactory::class));

        $message->refresh();

        $this->assertSame(WhatsAppMessage::STATUS_FAILED, $message->status);
        $this->assertSame('FreeFormWindowClosed', $message->error_class);
        $this->assertStringContainsString('template', (string) $message->error_message);
        $this->assertNull($message->sent_at);
        Http::assertNothingSent();
    }

    public function test_freeform_message_on_an_opted_out_conversation_is_not_sent(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.SHOULD-NEVER-HAPPEN']],
            ], 200),
        ]);

        $account = $this->makeAccount(businessId: 'access-token');
        $conversation = $this->makeConversation($account, [
            'opt_out_at' => now()->subDay(),
        ]);
        $message = $this->makeFreeFormMessage($conversation, 'Hola!');

        (new SendWhatsAppMessage($message->id))->handle(app(WhatsAppProviderFactory::class));

        $message->refresh();

        $this->assertSame(WhatsAppMessage::STATUS_FAILED, $message->status);
        $this->assertSame('ConversationOptedOut', $message->error_class);
        $this->assertNull($message->sent_at);
        Http::assertNothingSent();
    }

    public function test_template_message_still_uses_the_template_path(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.TEMPLATE-OK']],
            ], 200),
        ]);

        $account = $this->makeAccount(businessId: 'access-token');
        $conversation = $this->makeConversation($account);
        $template = WhatsAppTemplate::create([
            'account_id' => $account->id,
            'name' => 'bienvenida',
            'language' => 'es_PE',
            'status' => WhatsAppTemplate::STATUS_APPROVED,
            'body' => 'Hola {{1}}',
        ]);

        $message = WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'template_id' => $template->id,
            'direction' => WhatsAppMessage::DIRECTION_OUTBOUND,
            'type' => 'template',
            'body' => 'Hola cliente',
            'status' => WhatsAppMessage::STATUS_QUEUED,
            'provider_message_id' => 'live-template-1',
        ]);

        (new SendWhatsAppMessage($message->id))->handle(app(WhatsAppProviderFactory::class));

        $message->refresh();

        $this->assertSame(WhatsAppMessage::STATUS_SENT, $message->status);
        $this->assertSame('wamid.TEMPLATE-OK', $message->wamid);

        Http::assertSent(fn ($request) => $request['type'] === 'template'
            && $request['template']['name'] === 'bienvenida');
    }

    public function test_already_sent_message_is_not_re_sent(): void
    {
        Http::fake();

        $account = $this->makeAccount(businessId: 'access-token');
        $conversation = $this->makeConversation($account);

        $message = WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => WhatsAppMessage::DIRECTION_OUTBOUND,
            'type' => 'freeform',
            'body' => 'Ya enviado',
            'status' => WhatsAppMessage::STATUS_SENT,
            'wamid' => 'wamid.ALREADY-SENT',
            'provider_message_id' => 'live-already-1',
        ]);

        (new SendWhatsAppMessage($message->id))->handle(app(WhatsAppProviderFactory::class));

        $message->refresh();

        $this->assertSame(WhatsAppMessage::STATUS_SENT, $message->status);
        $this->assertSame('wamid.ALREADY-SENT', $message->wamid);
        Http::assertNothingSent();
    }

    public function test_disabled_account_is_failed_without_calling_the_provider(): void
    {
        Http::fake();

        $account = $this->makeAccount(businessId: 'access-token', status: WhatsAppAccount::STATUS_DISABLED);
        $conversation = $this->makeConversation($account);
        $message = $this->makeFreeFormMessage($conversation, 'Hola!');

        (new SendWhatsAppMessage($message->id))->handle(app(WhatsAppProviderFactory::class));

        $message->refresh();

        $this->assertSame(WhatsAppMessage::STATUS_FAILED, $message->status);
        $this->assertSame('AccountDisabled', $message->error_class);
        Http::assertNothingSent();
    }

    private function makeAccount(?string $businessId = null, string $status = WhatsAppAccount::STATUS_VERIFIED): WhatsAppAccount
    {
        $account = new WhatsAppAccount([
            'phone_number' => '+15551234567',
            'phone_number_id' => '1234567890',
            'business_id' => $businessId,
            'display_name' => 'Test Account',
            'status' => $status,
        ]);
        $account->save();

        return $account;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeConversation(WhatsAppAccount $account, array $overrides = []): WhatsAppConversation
    {
        $conversation = new WhatsAppConversation(array_merge([
            'account_id' => $account->id,
            'phone_number' => '+15550000000',
            'contact_name' => 'Contacto de prueba',
            'status' => WhatsAppConversation::STATUS_OPEN,
            'last_direction' => WhatsAppConversation::DIRECTION_INBOUND,
            'last_message_at' => now(),
        ], $overrides));
        $conversation->save();

        return $conversation;
    }

    private function makeFreeFormMessage(WhatsAppConversation $conversation, string $body): WhatsAppMessage
    {
        return WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => WhatsAppMessage::DIRECTION_OUTBOUND,
            'type' => 'freeform',
            'body' => $body,
            'status' => WhatsAppMessage::STATUS_QUEUED,
            'provider_message_id' => 'live-'.$conversation->id.'-'.bin2hex(random_bytes(6)),
        ]);
    }
}
