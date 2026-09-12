<?php

declare(strict_types=1);

namespace Tests\Unit\Notification;

use App\Jobs\V2\SendOutboundDelivery;
use App\Models\IntegrationAccount;
use App\Models\Notification\OutboundDelivery;
use App\Models\WhatsApp\WhatsAppAccount;
use App\Services\Notification\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * E-4 — the delivery ledger only asserted itself: no test ever exercised
 * {@see SendOutboundDelivery}, so the placeholder body fabricated in the job
 * and the unconditional `markSent()` on the WhatsApp branch both shipped.
 *
 * These tests run the job for real (the sync queue executes it inline, and
 * each case re-runs it explicitly so the assertions do not depend on the
 * queue driver) and assert WHAT THE RECIPIENT RECEIVES and WHAT THE LEDGER
 * CLAIMS:
 *   - mail/webhook/database content is the content the listener built;
 *   - content survives a re-dispatch that only knows the delivery id;
 *   - only the delivery content is persisted, never the rest of the payload;
 *   - the WhatsApp branch reports delivered only when the provider accepted.
 *
 * `tests/Unit/Notification` already hosts the B17 pipeline coverage
 * (`NotificationServiceTest` runs with `RefreshDatabase` too).
 */
class SendOutboundDeliveryJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_mail_delivery_carries_the_content_the_listener_built_instead_of_a_placeholder(): void
    {
        $delivery = app(NotificationService::class)->dispatch([
            'channel' => OutboundDelivery::CHANNEL_MAIL,
            'recipient_ref' => 'admin@example.com',
            'related_entity_type' => 'IntegrationAccount',
            'related_entity_id' => 42,
            'account_id' => null,
            'payload' => [
                'subject' => 'Integration failed permanently',
                'body' => 'Account #42 failed permanently. Error: AuthError — token revoked',
            ],
            'bucket' => 'D-21a',
        ]);

        Mail::getSymfonyTransport()->flush();
        $this->runJobAgain($delivery);

        $message = Mail::getSymfonyTransport()->messages()->last()?->getOriginalMessage();

        $this->assertNotNull($message, 'No mail was sent for the delivery.');
        $this->assertSame('Integration failed permanently', $message->getSubject());

        $body = (string) $message->getTextBody();
        $this->assertStringContainsString('Account #42 failed permanently. Error: AuthError — token revoked', $body);
        $this->assertStringNotContainsString('Delivery #'.$delivery->id, $body);
    }

    public function test_notification_content_still_reaches_the_recipient_when_the_job_re_runs_by_id(): void
    {
        $delivery = app(NotificationService::class)->dispatch([
            'channel' => OutboundDelivery::CHANNEL_MAIL,
            'recipient_ref' => 'admin@example.com',
            'related_entity_type' => 'IntegrationAccount',
            'related_entity_id' => 42,
            'account_id' => null,
            'payload' => [
                'subject' => 'Integration failed permanently',
                'body' => 'Account #42 failed permanently. Error: AuthError — token revoked',
            ],
            'bucket' => 'D-21a',
        ]);

        $transport = Mail::getSymfonyTransport();
        $transport->flush();

        // What the queue retry and the admin "Reintentar" button do: re-dispatch
        // by id with no payload in hand.
        $this->runJobAgain($delivery);

        $message = $transport->messages()->last()?->getOriginalMessage();

        $this->assertNotNull($message, 'No mail was sent on the re-dispatch.');
        $body = (string) $message->getTextBody();
        $this->assertStringContainsString('Account #42 failed permanently. Error: AuthError — token revoked', $body);
        $this->assertStringNotContainsString('has no stored content', $body);
    }

    public function test_dispatch_persists_only_the_content_and_never_secrets_from_the_payload(): void
    {
        $delivery = app(NotificationService::class)->dispatch([
            'channel' => OutboundDelivery::CHANNEL_MAIL,
            'recipient_ref' => 'admin@example.com',
            'related_entity_type' => 'IntegrationAccount',
            'related_entity_id' => 42,
            'account_id' => null,
            'payload' => [
                'subject' => 'Integration failed',
                'body' => 'Account #42 failed permanently.',
                'access_token' => 'super-secret-token',
                'credentials' => ['password' => 'hunter2'],
            ],
            'bucket' => 'D-21a',
        ]);

        $persisted = $delivery->fresh()->payload;

        $this->assertSame(
            ['subject' => 'Integration failed', 'body' => 'Account #42 failed permanently.'],
            $persisted,
        );

        $raw = (string) json_encode($persisted);
        $this->assertStringNotContainsString('super-secret-token', $raw);
        $this->assertStringNotContainsString('hunter2', $raw);
    }

    public function test_whatsapp_delivery_sends_the_real_content_and_is_only_delivered_on_acceptance(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.NOTIF-OK']],
            ], 200),
        ]);

        $account = $this->makeWhatsAppAccount(configured: true);

        $delivery = app(NotificationService::class)->dispatch([
            'channel' => OutboundDelivery::CHANNEL_WHATSAPP,
            'recipient_ref' => '+15559998888',
            'related_entity_type' => 'IntegrationAccount',
            'related_entity_id' => 42,
            'account_id' => $account->id,
            'payload' => [
                'subject' => 'Integration failed permanently',
                'body' => 'Account #42 failed permanently. Error: AuthError — token revoked',
            ],
            'bucket' => 'D-21a',
        ]);

        $this->runJobAgain($delivery);

        $delivery->refresh();
        $this->assertSame(OutboundDelivery::STATUS_DELIVERED, $delivery->status);
        $this->assertSame(200, (int) $delivery->last_response_code);

        Http::assertSent(fn ($request) => $request['type'] === 'text'
            && $request['to'] === '+15559998888'
            && $request['text']['body'] === 'Account #42 failed permanently. Error: AuthError — token revoked');
    }

    public function test_whatsapp_delivery_records_the_provider_rejection_instead_of_marking_delivered(): void
    {
        // Credentials are not configured, so the provider returns the canonical
        // NotImplementedException envelope: the send did NOT happen.
        $account = $this->makeWhatsAppAccount(configured: false);
        $delivery = $this->makeWhatsAppDelivery($account);

        $thrown = null;
        try {
            (new SendOutboundDelivery($delivery->id))->handle(app(NotificationService::class));
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'A rejected WhatsApp send must not be reported to the ledger as delivered.');
        $this->assertStringContainsString('NotImplementedException', $thrown->getMessage());

        $delivery->refresh();
        $this->assertNotSame(OutboundDelivery::STATUS_DELIVERED, $delivery->status);
        $this->assertNotSame(OutboundDelivery::STATUS_SENT, $delivery->status);
        $this->assertNull($delivery->last_response_code);
        $this->assertStringContainsString('NotImplementedException', (string) $delivery->last_error);
        $this->assertStringContainsString('credentials not configured', (string) $delivery->last_error);
    }

    public function test_rejected_whatsapp_delivery_ends_failed_and_never_delivered(): void
    {
        $account = $this->makeWhatsAppAccount(configured: false);
        $delivery = $this->makeWhatsAppDelivery($account);

        // The queue worker's retry boundary: handle() throws, the worker
        // re-delivers until the tries budget is exhausted.
        for ($attempt = 0; $attempt < OutboundDelivery::MAX_ATTEMPTS + 1; $attempt++) {
            try {
                (new SendOutboundDelivery($delivery->id))->handle(app(NotificationService::class));
            } catch (\Throwable) {
                // retry boundary
            }
        }

        $delivery->refresh();

        $this->assertSame(OutboundDelivery::STATUS_FAILED, $delivery->status);
        $this->assertGreaterThanOrEqual(OutboundDelivery::MAX_ATTEMPTS, (int) $delivery->attempts);
        $this->assertNull($delivery->last_response_code);
        $this->assertStringContainsString('credentials not configured', (string) $delivery->last_error);
    }

    /**
     * Executes the job explicitly against a reset ledger row.
     *
     * The suite runs the sync queue, so the dispatch inside
     * `NotificationService::dispatch()` already executed the job (and the
     * in-memory model this test holds is stale); the reset therefore goes
     * straight to the row, and `handle()` is called explicitly so the
     * assertions do not depend on the queue driver.
     */
    private function runJobAgain(OutboundDelivery $delivery): void
    {
        OutboundDelivery::query()->whereKey($delivery->getKey())->update([
            'status' => OutboundDelivery::STATUS_QUEUED,
            'attempts' => 0,
            'next_attempt_at' => null,
            'last_response_code' => null,
            'last_error' => null,
        ]);

        (new SendOutboundDelivery($delivery->id))->handle(app(NotificationService::class));

        $delivery->refresh();
    }

    /**
     * `outbound_deliveries.account_id` is a foreign key to
     * `integration_accounts`, while `SendOutboundDelivery::sendWhatsApp()`
     * resolves it against `whatsapp_accounts`; both rows therefore have to
     * share the id for the branch to reach the provider.
     */
    private function makeWhatsAppAccount(bool $configured): WhatsAppAccount
    {
        $integrationAccount = IntegrationAccount::query()->create([
            'provider' => 'whatsapp',
            'label' => 'WhatsApp (test)',
            'is_active' => true,
            'test_mode' => true,
        ]);

        $account = new WhatsAppAccount([
            'phone_number' => '+15551230000',
            'phone_number_id' => '1234567890',
            'business_id' => $configured ? 'access-token' : null,
            'display_name' => 'WhatsApp test',
            'status' => WhatsAppAccount::STATUS_VERIFIED,
        ]);
        $account->id = $integrationAccount->id;
        $account->save();

        return $account;
    }

    private function makeWhatsAppDelivery(WhatsAppAccount $account): OutboundDelivery
    {
        return OutboundDelivery::query()->create([
            'channel' => OutboundDelivery::CHANNEL_WHATSAPP,
            'recipient_ref' => '+15559998888',
            'account_id' => $account->id,
            'status' => OutboundDelivery::STATUS_QUEUED,
            'attempts' => 0,
            'idempotency_key' => hash('sha256', 'whatsapp-delivery-'.uniqid('', true)),
        ]);
    }
}
