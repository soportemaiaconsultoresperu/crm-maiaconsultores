<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\WhatsApp\WhatsAppProviderFactory;
use App\Integrations\Contracts\VerificationResult;
use App\Integrations\Verification\MetaSignatureVerifier;
use App\Models\WhatsApp\WhatsAppAccount;
use App\Models\WhatsApp\WhatsAppConversation;
use App\Models\WhatsApp\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * B14 Pasada B-3 — Inbound webhook endpoint for Meta WhatsApp Cloud API.
 *
 * No auth middleware by design: the HMAC signature is the gate. Signature
 * verification is **defense in depth** — both
 * {@see \App\Services\WhatsApp\MetaWhatsAppProvider::verifyWebhookSignature()}
 * (B14 Pasada B-1) AND {@see MetaSignatureVerifier} (B11) must pass. If
 * either fails, the request is refused with 403.
 *
 * Supported payloads (Meta Cloud API docs):
 *   - entry[].changes[].value.messages[] → inbound user messages → upsert
 *     {@see WhatsAppConversation} + persist {@see WhatsAppMessage} +
 *     dispatch {@see \App\Events\V2\WhatsAppInboundEvent}.
 *   - entry[].changes[].value.statuses[] → delivery / read confirmations →
 *     update the existing WhatsAppMessage row by `wamid`.
 *
 * The Meta `hub.challenge` verification handshake lands with NO `entry[]`
 * field; we acknowledge it with `{ok: true, ignored: true}` so Meta can
 * complete the handshake.
 *
 * Webhook URL pattern: POST /webhooks/whatsapp/{account}
 * (route registered OUTSIDE the auth group; `account` is the
 * WhatsAppAccount primary key via route-model binding).
 *
 * Spec: docs/v2/01-roadmap.md §2.4 (schema) + §7 decisions 12a/13a-e/15a-c.
 */
class WhatsAppWebhookController extends Controller
{
    public function __construct(
        public readonly WhatsAppProviderFactory $factory,
        public readonly WhatsAppService $service,
    ) {
    }

    public function verify(Request $request, WhatsAppAccount $account): JsonResponse
    {
        $provider = $this->factory->for($account);

        if (! $provider->verifyWebhookSignature($request)) {
            return $this->signatureFailed('provider');
        }

        $secret = $this->resolveWebhookSecret($account);
        if ($secret === null || $secret === '') {
            return $this->signatureFailed('no_secret');
        }

        $verifier = new MetaSignatureVerifier($secret);
        $signature = (string) $request->header('X-Hub-Signature-256', '');
        $result = $verifier->verify($signature, (string) $request->getContent(), null);

        if ($result !== VerificationResult::VERIFIED) {
            return $this->signatureFailed('b11_'.$result->value);
        }

        // Meta verification handshake — `hub.challenge` payload has NO
        // `entry[]` field; acknowledge and bail.
        $payload = $request->json()->all();
        if (! isset($payload['entry']) || ! is_array($payload['entry'])) {
            return response()->json(['ok' => true, 'ignored' => true]);
        }

        $inboundCount = 0;
        $statusCount = 0;

        foreach ($payload['entry'] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $changes = $entry['changes'] ?? [];
            if (! is_array($changes)) {
                continue;
            }
            foreach ($changes as $change) {
                if (! is_array($change)) {
                    continue;
                }
                $value = $change['value'] ?? [];
                if (! is_array($value)) {
                    continue;
                }

                $messages = $value['messages'] ?? [];
                if (is_array($messages)) {
                    foreach ($messages as $message) {
                        if (is_array($message) && $this->handleInboundMessage($account, $message)) {
                            $inboundCount++;
                        }
                    }
                }

                $statuses = $value['statuses'] ?? [];
                if (is_array($statuses)) {
                    foreach ($statuses as $status) {
                        if (is_array($status) && $this->handleStatusUpdate($status)) {
                            $statusCount++;
                        }
                    }
                }
            }
        }

        return response()->json([
            'ok' => true,
            'inbound' => $inboundCount,
            'statuses' => $statusCount,
        ]);
    }

    /**
     * Persist an inbound message and dispatch the WhatsAppInboundEvent.
     * Returns true when a new row is created (duplicate wamids are skipped
     * because `provider_message_id` is UNIQUE in the schema).
     *
     * @param  array<string, mixed>  $message
     */
    private function handleInboundMessage(WhatsAppAccount $account, array $message): bool
    {
        $from = (string) ($message['from'] ?? '');
        $wamid = (string) ($message['id'] ?? '');
        $type = (string) ($message['type'] ?? 'unknown');
        if ($from === '' || $wamid === '') {
            return false;
        }

        $body = '';
        if ($type === 'text' && isset($message['text']['body']) && is_string($message['text']['body'])) {
            $body = $message['text']['body'];
        }

        $now = now();

        $conversation = WhatsAppConversation::query()
            ->where('account_id', $account->getKey())
            ->where('phone_number', $from)
            ->first();

        if ($conversation === null) {
            // First contact from this number — create the conversation with
            // an opt-in recorded by default (per D-13a). The customer can
            // opt out via an inbound STOP message or via the admin UI; the
            // opt-out flag is updated by the consent log entry.
            $conversation = new WhatsAppConversation([
                'account_id' => $account->getKey(),
                'phone_number' => $from,
                'status' => WhatsAppConversation::STATUS_OPEN,
                'last_direction' => WhatsAppConversation::DIRECTION_INBOUND,
                'last_message_at' => $now,
                'consent_at' => $now,
            ]);
            $conversation->save();
        } else {
            $conversation->forceFill([
                'last_message_at' => $now,
                'last_direction' => WhatsAppConversation::DIRECTION_INBOUND,
            ])->save();
        }

        // Deduplicate: provider_message_id is UNIQUE. If Meta redelivers the
        // same wamid (network retry), short-circuit and skip.
        $existing = WhatsAppMessage::query()
            ->where('provider_message_id', $wamid)
            ->first();
        if ($existing !== null) {
            return false;
        }

        $draft = new WhatsAppMessage([
            'conversation_id' => $conversation->getKey(),
            'direction' => WhatsAppMessage::DIRECTION_INBOUND,
            'type' => $type === 'text' ? 'text' : $type,
            'provider_message_id' => $wamid,
            'wamid' => $wamid,
            'body' => $body,
            'status' => 'received',
        ]);

        $this->service->handleInbound($draft);

        return true;
    }

    /**
     * Update the matching outbound WhatsAppMessage row with the new
     * delivery / read status + timestamp. Returns true when a row was
     * updated; false when the wamid is unknown (status update ignored).
     *
     * @param  array<string, mixed>  $status
     */
    private function handleStatusUpdate(array $status): bool
    {
        $wamid = (string) ($status['id'] ?? '');
        $newStatus = (string) ($status['status'] ?? '');
        if ($wamid === '' || $newStatus === '') {
            return false;
        }

        $message = WhatsAppMessage::query()
            ->where('wamid', $wamid)
            ->first();
        if ($message === null) {
            return false;
        }

        $now = now();
        $updates = ['status' => $newStatus];
        if ($newStatus === WhatsAppMessage::STATUS_DELIVERED) {
            $updates['delivered_at'] = $now;
        } elseif ($newStatus === WhatsAppMessage::STATUS_READ) {
            $updates['read_at'] = $now;
        }
        $message->forceFill($updates)->save();

        return true;
    }

    /**
     * Resolve the webhook secret for the account — mirrors
     * {@see MetaWhatsAppProvider::resolveWebhookSecret()} so the
     * controller's B11 verifier sees the same secret the provider saw.
     */
    private function resolveWebhookSecret(WhatsAppAccount $account): ?string
    {
        $attribute = $account->getAttributes()['webhook_secret'] ?? null;
        if (is_string($attribute) && $attribute !== '') {
            return $attribute;
        }

        return config('integrations.whatsapp.webhook_secret')
            ?: env('INTEGRATIONS_WHATSAPP_WEBHOOK_SECRET');
    }

    private function signatureFailed(string $class): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error_class' => 'InvalidSignature:'.$class,
            'error_message' => 'Webhook signature verification failed.',
        ], 403);
    }
}
