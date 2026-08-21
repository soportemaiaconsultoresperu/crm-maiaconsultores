<?php

declare(strict_types=1);

namespace Tests\Feature\WhatsApp;

use App\Events\V2\WhatsAppInboundEvent;
use App\Jobs\V2\SendWhatsAppMessage;
use App\Models\WhatsApp\WhatsAppAccount;
use App\Models\WhatsApp\WhatsAppConversation;
use App\Models\WhatsApp\WhatsAppMessage;
use App\Models\WhatsApp\WhatsAppTemplate;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * B14 Pasada B-1 — WhatsAppService high-level pipeline.
 *
 * Covers:
 *  - sendTemplateMessage persists a queued WhatsAppMessage + dispatches the
 *    SendWhatsAppMessage job.
 *  - sendTemplateMessage upserts the WhatsAppConversation for the
 *    (account, phone_number) pair.
 *  - syncTemplates persists ONLY templates with status=APPROVED (decision 15c).
 *  - sendTemplateMessage is idempotent within the same second (same
 *    idempotency_key short-circuits the second call).
 *  - handleInbound persists the draft with direction=inbound, status=received.
 */
class WhatsAppServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_template_message_persists_message_and_dispatches_job(): void
    {
        Bus::fake();

        $account = $this->makeAccount();
        $template = $this->makeTemplate($account);

        $service = app(WhatsAppService::class);

        $message = $service->sendTemplateMessage(
            $account,
            $template,
            '+15551234567',
            ['name' => 'Acme'],
        );

        $this->assertInstanceOf(WhatsAppMessage::class, $message);
        $this->assertSame(WhatsAppMessage::STATUS_QUEUED, $message->status);
        $this->assertSame(WhatsAppMessage::DIRECTION_OUTBOUND, $message->direction);
        $this->assertSame('template', $message->type);
        $this->assertNotNull($message->idempotency_key);
        $this->assertSame(40, strlen($message->idempotency_key));
        $this->assertSame($account->id, (int) $message->conversation->account_id);

        $this->assertDatabaseHas('whatsapp_messages', [
            'id' => $message->id,
            'status' => WhatsAppMessage::STATUS_QUEUED,
            'direction' => WhatsAppMessage::DIRECTION_OUTBOUND,
            'type' => 'template',
        ]);

        Bus::assertDispatched(
            SendWhatsAppMessage::class,
            fn (SendWhatsAppMessage $job): bool => $job->messageId === $message->id,
        );
    }

    public function test_send_template_message_creates_conversation_when_missing(): void
    {
        Bus::fake();

        $account = $this->makeAccount();
        $template = $this->makeTemplate($account);

        $this->assertSame(0, WhatsAppConversation::query()->count());

        $service = app(WhatsAppService::class);

        $message = $service->sendTemplateMessage(
            $account,
            $template,
            '+15551234567',
            ['name' => 'Acme'],
        );

        $this->assertSame(1, WhatsAppConversation::query()->count());
        $conversation = WhatsAppConversation::query()->first();
        $this->assertSame($account->id, (int) $conversation->account_id);
        $this->assertSame('+15551234567', $conversation->phone_number);
        $this->assertSame(WhatsAppConversation::STATUS_OPEN, $conversation->status);
        $this->assertNotNull($conversation->last_message_at);
        $this->assertSame('outbound', $conversation->last_direction);
        $this->assertSame($conversation->id, (int) $message->conversation_id);
    }

    public function test_send_template_message_reuses_existing_conversation(): void
    {
        Bus::fake();

        $account = $this->makeAccount();
        $template = $this->makeTemplate($account);
        $service = app(WhatsAppService::class);

        $first = $service->sendTemplateMessage($account, $template, '+15551234567', ['name' => 'One']);
        $second = $service->sendTemplateMessage($account, $template, '+15551234567', ['name' => 'Two']);

        $this->assertSame(1, WhatsAppConversation::query()->count());
        $this->assertSame($first->conversation_id, $second->conversation_id);
    }

    public function test_sync_templates_persists_only_approved(): void
    {
        $account = $this->makeAccount(businessId: 'fake-access-token-for-test');
        // Persist the account so the FK on whatsapp_templates resolves.
        $account->save();

        Http::fake([
            '*graph.facebook.com*' => Http::response([
                'data' => [
                    [
                        'id' => 'welcome_template',
                        'name' => 'welcome',
                        'language' => 'es_PE',
                        'status' => 'APPROVED',
                        'category' => 'MARKETING',
                        'components' => [
                            [
                                'type' => 'BODY',
                                'text' => 'Hola {{1}}, bienvenido!',
                            ],
                        ],
                        'variables' => ['name'],
                    ],
                    [
                        'id' => 'draft_template',
                        'name' => 'draft_one',
                        'language' => 'es_PE',
                        'status' => 'DRAFT',
                        'components' => [
                            ['type' => 'BODY', 'text' => 'No aprobado'],
                        ],
                    ],
                    [
                        'id' => 'pending_template',
                        'name' => 'pending_one',
                        'language' => 'es_PE',
                        'status' => 'PENDING',
                        'components' => [
                            ['type' => 'BODY', 'text' => 'Pendiente'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $service = app(WhatsAppService::class);
        $count = $service->syncTemplates($account);

        // Diagnostic: confirm the fake matched at least once.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com'));

        $this->assertSame(1, $count);
        $this->assertSame(1, WhatsAppTemplate::query()->count());

        $persisted = WhatsAppTemplate::query()->first();
        $this->assertSame('welcome', $persisted->name);
        $this->assertSame('es_PE', $persisted->language);
        $this->assertSame(WhatsAppTemplate::STATUS_APPROVED, $persisted->status);
        $this->assertSame('MARKETING', $persisted->category);
        $this->assertSame('Hola {{1}}, bienvenido!', $persisted->body);
    }

    public function test_idempotency_key_is_deterministic_within_window(): void
    {
        Bus::fake();

        $account = $this->makeAccount();
        $template = $this->makeTemplate($account);
        $service = app(WhatsAppService::class);

        $first = $service->sendTemplateMessage($account, $template, '+15551234567', ['name' => 'Acme']);

        // Force same wall-clock second: pin the same idempotency_key manually.
        $duplicate = $service->sendTemplateMessage($account, $template, '+15551234567', ['name' => 'Acme']);

        // The deterministic key is computed from ($account, $template, $phone, $vars, $now->timestamp).
        // Two calls within the same second share the same key → the second
        // call detects the existing message and returns it instead of
        // creating a new row.
        $this->assertSame($first->id, $duplicate->id);
        $this->assertSame($first->idempotency_key, $duplicate->idempotency_key);
        $this->assertSame(1, WhatsAppMessage::query()->count());
    }

    public function test_inbound_persists_message_with_received_status(): void
    {
        $account = $this->makeAccount();
        $account->save();

        // The webhook controller (B14 Pasada B-3) resolves the conversation
        // upstream before calling handleInbound — model the same here.
        $conversation = WhatsAppConversation::create([
            'account_id' => $account->id,
            'phone_number' => '+15551234567',
            'status' => WhatsAppConversation::STATUS_OPEN,
            'last_direction' => WhatsAppConversation::DIRECTION_INBOUND,
            'last_message_at' => now(),
        ]);

        // A draft message produced by the webhook controller (no DB row yet).
        $draft = new WhatsAppMessage([
            'conversation_id' => $conversation->id,
            'direction' => WhatsAppMessage::DIRECTION_INBOUND,
            'type' => 'freeform',
            'provider_message_id' => 'meta-inbound-123',
            'body' => 'Hola desde el cliente',
            'status' => 'received',
        ]);

        $service = app(WhatsAppService::class);
        $persisted = $service->handleInbound($draft);

        $this->assertNotNull($persisted->id);
        $this->assertSame(WhatsAppMessage::DIRECTION_INBOUND, $persisted->direction);
        $this->assertSame('received', $persisted->status);
        $this->assertSame('meta-inbound-123', $persisted->provider_message_id);
        $this->assertDatabaseHas('whatsapp_messages', [
            'id' => $persisted->id,
            'direction' => 'inbound',
            'status' => 'received',
        ]);
    }

    public function test_handle_inbound_dispatches_whatsapp_inbound_event(): void
    {
        Event::fake([WhatsAppInboundEvent::class]);

        $account = $this->makeAccount();
        $account->save();
        $conversation = WhatsAppConversation::create([
            'account_id' => $account->id,
            'phone_number' => '+15551234567',
            'status' => WhatsAppConversation::STATUS_OPEN,
            'last_direction' => WhatsAppConversation::DIRECTION_INBOUND,
            'last_message_at' => now(),
        ]);

        $draft = new WhatsAppMessage([
            'conversation_id' => $conversation->id,
            'direction' => WhatsAppMessage::DIRECTION_INBOUND,
            'type' => 'freeform',
            'provider_message_id' => 'meta-inbound-456',
            'body' => 'Otro mensaje',
            'status' => 'received',
        ]);

        $service = app(WhatsAppService::class);
        $persisted = $service->handleInbound($draft);

        Event::assertDispatched(
            WhatsAppInboundEvent::class,
            fn (WhatsAppInboundEvent $event): bool => $event->message->getKey() === $persisted->getKey(),
        );
    }

    public function test_send_template_message_updates_conversation_last_message_at(): void
    {
        Bus::fake();

        $account = $this->makeAccount();
        $template = $this->makeTemplate($account);

        // Existing conversation with an older last_message_at.
        $oldTimestamp = now()->subMinutes(10);
        $conversation = WhatsAppConversation::create([
            'account_id' => $account->id,
            'phone_number' => '+15551234567',
            'status' => WhatsAppConversation::STATUS_OPEN,
            'last_direction' => WhatsAppConversation::DIRECTION_INBOUND,
            'last_message_at' => $oldTimestamp,
        ]);

        $service = app(WhatsAppService::class);
        $service->sendTemplateMessage($account, $template, '+15551234567', ['name' => 'Acme']);

        $conversation->refresh();
        $this->assertSame(WhatsAppConversation::DIRECTION_OUTBOUND, $conversation->last_direction);
        $this->assertGreaterThan(
            $oldTimestamp->timestamp,
            $conversation->last_message_at->timestamp,
        );
    }

    private function makeAccount(?string $businessId = null): WhatsAppAccount
    {
        return new WhatsAppAccount([
            'phone_number' => '+15551234567',
            'phone_number_id' => '1234567890',
            'business_id' => $businessId,
            'display_name' => 'Test Account',
            'status' => WhatsAppAccount::STATUS_VERIFIED,
        ]);
    }

    private function makeTemplate(WhatsAppAccount $account): WhatsAppTemplate
    {
        // Persist account + template so the conversation/message FKs resolve.
        $account->save();
        $template = WhatsAppTemplate::create([
            'account_id' => $account->id,
            'name' => 'welcome',
            'language' => 'es_PE',
            'status' => WhatsAppTemplate::STATUS_APPROVED,
            'category' => 'MARKETING',
            'body' => 'Hola {{1}}, bienvenido!',
            'variables_json' => ['name'],
        ]);

        return $template;
    }
}