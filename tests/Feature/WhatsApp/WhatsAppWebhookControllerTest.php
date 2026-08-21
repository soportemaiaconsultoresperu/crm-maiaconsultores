<?php

declare(strict_types=1);

namespace Tests\Feature\WhatsApp;

use App\Events\V2\WhatsAppInboundEvent;
use App\Models\WhatsApp\WhatsAppAccount;
use App\Models\WhatsApp\WhatsAppConversation;
use App\Models\WhatsApp\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * B14 Pasada B-3 — WhatsAppWebhookController inbound webhook tests.
 *
 * Covers signature verification (defense in depth: provider + B11
 * MetaSignatureVerifier), inbound message persistence (conversation upsert
 * + WhatsAppMessage row + WhatsAppInboundEvent dispatch), delivery
 * confirmation status update, and Meta verification challenge handling.
 *
 * Spec: docs/v2/01-roadmap.md §2.4 (schema) + §7 decisions 12a/13a-e/15a-c.
 */
class WhatsAppWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Shared webhook secret used by both the account attribute bag and the
     * test request. The B14 Pasada B-1 schema does NOT add a `webhook_secret`
     * column to `whatsapp_accounts`; the controller reads it from the in-memory
     * attribute via the same reflection trick that the provider test uses.
     */
    private string $secret = 'shhh-whatsapp-test-secret';

    public function test_missing_signature_returns_403(): void
    {
        $account = $this->makeAccount($this->secret);

        $body = json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [],
        ], JSON_THROW_ON_ERROR);

        $response = $this->call('POST', '/webhooks/whatsapp/'.$account->getKey(), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $body);

        $response->assertStatus(403);
        $this->assertSame(0, WhatsAppConversation::query()->count());
        $this->assertSame(0, WhatsAppMessage::query()->count());
    }

    public function test_invalid_signature_returns_403(): void
    {
        $account = $this->makeAccount($this->secret);

        $body = json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [],
        ], JSON_THROW_ON_ERROR);

        $bogus = 'sha256='.hash_hmac('sha256', $body, 'wrong-secret');

        $response = $this->call('POST', '/webhooks/whatsapp/'.$account->getKey(), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $bogus,
        ], $body);

        $response->assertStatus(403);
        $this->assertSame(0, WhatsAppConversation::query()->count());
        $this->assertSame(0, WhatsAppMessage::query()->count());
    }

    public function test_valid_signature_with_inbound_message_persists_conversation_and_message(): void
    {
        $account = $this->makeAccount($this->secret);

        $phone = '+15559999999';
        $msgId = 'wamid.INBOUND123';
        $body = json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '12345',
                'changes' => [[
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => '+15551234567',
                            'phone_number_id' => '1234567890',
                        ],
                        'messages' => [[
                            'from' => $phone,
                            'id' => $msgId,
                            'timestamp' => '1700000000',
                            'text' => ['body' => 'Hola desde el cliente!'],
                            'type' => 'text',
                        ]],
                    ],
                    'field' => 'messages',
                ]],
            ]],
        ], JSON_THROW_ON_ERROR);

        $signature = 'sha256='.hash_hmac('sha256', $body, $this->secret);

        $response = $this->call('POST', '/webhooks/whatsapp/'.$account->getKey(), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $body);

        $response->assertOk();
        $response->assertJson(['ok' => true]);

        // Conversation upsert: one row, account_id matches, phone matches,
        // status=open, consent_at populated (Meta sends a contact first;
        // we record opt-in by default per D-13a).
        $this->assertSame(1, WhatsAppConversation::query()->count());
        $conversation = WhatsAppConversation::query()->first();
        $this->assertSame((int) $account->getKey(), (int) $conversation->account_id);
        $this->assertSame($phone, $conversation->phone_number);
        $this->assertSame(WhatsAppConversation::STATUS_OPEN, $conversation->status);
        $this->assertNotNull($conversation->consent_at);
        $this->assertSame(WhatsAppConversation::DIRECTION_INBOUND, $conversation->last_direction);
        $this->assertNotNull($conversation->last_message_at);

        // Inbound message: wamid + provider_message_id match, direction=inbound,
        // type=text, body matches, status=received.
        $this->assertSame(1, WhatsAppMessage::query()->count());
        $message = WhatsAppMessage::query()->first();
        $this->assertSame($msgId, $message->provider_message_id);
        $this->assertSame($msgId, $message->wamid);
        $this->assertSame(WhatsAppMessage::DIRECTION_INBOUND, $message->direction);
        $this->assertSame('text', $message->type);
        $this->assertSame('Hola desde el cliente!', $message->body);
        $this->assertSame('received', $message->status);
        $this->assertSame((int) $conversation->getKey(), (int) $message->conversation_id);

        $this->assertDatabaseHas('whatsapp_conversations', [
            'id' => $conversation->id,
            'account_id' => $account->id,
            'phone_number' => $phone,
            'status' => WhatsAppConversation::STATUS_OPEN,
        ]);
        $this->assertDatabaseHas('whatsapp_messages', [
            'id' => $message->id,
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'status' => 'received',
            'wamid' => $msgId,
        ]);
    }

    public function test_valid_signature_with_delivery_confirmation_updates_message_status(): void
    {
        $account = $this->makeAccount($this->secret);

        // Existing outbound message whose wamid will receive a delivery ack.
        $conversation = WhatsAppConversation::create([
            'account_id' => $account->getKey(),
            'phone_number' => '+15559999999',
            'status' => WhatsAppConversation::STATUS_OPEN,
            'last_direction' => WhatsAppConversation::DIRECTION_OUTBOUND,
            'last_message_at' => now(),
        ]);

        $msgId = 'wamid.OUTBOUND456';
        $message = WhatsAppMessage::create([
            'conversation_id' => $conversation->getKey(),
            'direction' => WhatsAppMessage::DIRECTION_OUTBOUND,
            'type' => 'text',
            'provider_message_id' => $msgId,
            'wamid' => $msgId,
            'body' => 'Outbound',
            'status' => WhatsAppMessage::STATUS_SENT,
        ]);

        $body = json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '12345',
                'changes' => [[
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => '+15551234567',
                            'phone_number_id' => '1234567890',
                        ],
                        'statuses' => [[
                            'id' => $msgId,
                            'status' => 'delivered',
                            'timestamp' => '1700000000',
                            'recipient_id' => '+15559999999',
                        ]],
                    ],
                    'field' => 'messages',
                ]],
            ]],
        ], JSON_THROW_ON_ERROR);

        $signature = 'sha256='.hash_hmac('sha256', $body, $this->secret);

        $response = $this->call('POST', '/webhooks/whatsapp/'.$account->getKey(), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $body);

        $response->assertOk();
        $response->assertJson(['ok' => true]);

        $message->refresh();
        $this->assertSame(WhatsAppMessage::STATUS_DELIVERED, $message->status);
        $this->assertNotNull($message->delivered_at);

        // Now simulate a "read" status update — read_at should be set.
        $bodyRead = json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '12345',
                'changes' => [[
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => '+15551234567',
                            'phone_number_id' => '1234567890',
                        ],
                        'statuses' => [[
                            'id' => $msgId,
                            'status' => 'read',
                            'timestamp' => '1700000005',
                            'recipient_id' => '+15559999999',
                        ]],
                    ],
                    'field' => 'messages',
                ]],
            ]],
        ], JSON_THROW_ON_ERROR);

        $signatureRead = 'sha256='.hash_hmac('sha256', $bodyRead, $this->secret);

        $this->call('POST', '/webhooks/whatsapp/'.$account->getKey(), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signatureRead,
        ], $bodyRead)->assertOk();

        $message->refresh();
        $this->assertSame(WhatsAppMessage::STATUS_READ, $message->status);
        $this->assertNotNull($message->read_at);
    }

    public function test_meta_verification_challenge_returns_200_ignored(): void
    {
        $account = $this->makeAccount($this->secret);

        // Meta's verification handshake sends `hub.mode / hub.verify_token /
        // hub.challenge` — NO `entry[]` field.
        $body = json_encode([
            'hub' => [
                'mode' => 'subscribe',
                'verify_token' => 'whatever',
                'challenge' => '1234567890',
            ],
        ], JSON_THROW_ON_ERROR);

        $signature = 'sha256='.hash_hmac('sha256', $body, $this->secret);

        $response = $this->call('POST', '/webhooks/whatsapp/'.$account->getKey(), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $body);

        $response->assertOk();
        $response->assertJson(['ok' => true, 'ignored' => true]);
        $this->assertSame(0, WhatsAppConversation::query()->count());
        $this->assertSame(0, WhatsAppMessage::query()->count());
    }

    public function test_inbound_message_dispatches_whatsapp_inbound_event(): void
    {
        Event::fake([WhatsAppInboundEvent::class]);

        $account = $this->makeAccount($this->secret);

        $phone = '+15558888888';
        $msgId = 'wamid.EVENT789';
        $body = json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '12345',
                'changes' => [[
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => '+15551234567',
                            'phone_number_id' => '1234567890',
                        ],
                        'messages' => [[
                            'from' => $phone,
                            'id' => $msgId,
                            'timestamp' => '1700000000',
                            'text' => ['body' => 'Mensaje con evento'],
                            'type' => 'text',
                        ]],
                    ],
                    'field' => 'messages',
                ]],
            ]],
        ], JSON_THROW_ON_ERROR);

        $signature = 'sha256='.hash_hmac('sha256', $body, $this->secret);

        $this->call('POST', '/webhooks/whatsapp/'.$account->getKey(), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $body)->assertOk();

        Event::assertDispatched(
            WhatsAppInboundEvent::class,
            fn (WhatsAppInboundEvent $event): bool => $event->message->direction === WhatsAppMessage::DIRECTION_INBOUND
                && $event->message->wamid === $msgId,
        );
    }

    /**
     * Build and persist a WhatsAppAccount, and expose the webhook secret
     * through `config('integrations.whatsapp.webhook_secret')` — the same
     * fallback {@see MetaWhatsAppProvider::resolveWebhookSecret()} reads.
     *
     * The v1 `whatsapp_accounts` schema does NOT have a `webhook_secret`
     * column, so the secret cannot live on the model row. The provider
     * checks the in-memory attribute bag first and falls back to config
     * (see {@see MetaWhatsAppProviderTest} for the same pattern).
     */
    private function makeAccount(string $webhookSecret): WhatsAppAccount
    {
        config(['integrations.whatsapp.webhook_secret' => $webhookSecret]);

        $account = new WhatsAppAccount([
            'phone_number' => '+15551234567',
            'phone_number_id' => '1234567890',
            'business_id' => 'fake-business-id',
            'display_name' => 'Test WhatsApp',
            'status' => WhatsAppAccount::STATUS_VERIFIED,
        ]);

        $account->save();

        return $account;
    }
}
