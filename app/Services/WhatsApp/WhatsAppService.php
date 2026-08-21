<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use App\Contracts\WhatsApp\WhatsAppProviderFactory;
use App\Events\V2\WhatsAppInboundEvent;
use App\Jobs\V2\SendWhatsAppMessage;
use App\Models\User;
use App\Models\WhatsApp\WhatsAppAccount;
use App\Models\WhatsApp\WhatsAppConversation;
use App\Models\WhatsApp\WhatsAppMessage;
use App\Models\WhatsApp\WhatsAppTemplate;
use Illuminate\Support\Facades\DB;

/**
 * B14 Pasada B-1 — High-level WhatsApp pipeline.
 *
 * `sendTemplateMessage()`:
 *   - finds or creates the {@see WhatsAppConversation} for the
 *     (account, phone_number) pair;
 *   - persists an outbound `WhatsAppMessage` row in a single transaction,
 *     with `status='queued'` and a deterministic `idempotency_key`;
 *   - dispatches {@see SendWhatsAppMessage} for async delivery through
 *     {@see \App\Contracts\WhatsApp\WhatsAppProvider}.
 *
 * `handleInbound()`:
 *   - persists an inbound `WhatsAppMessage` row that the webhook controller
 *     produced;
 *   - fires {@see WhatsAppInboundEvent} so future listeners can react
 *     (B14.x — lead creation, assignment, tagging). Pasada B-1 ships only
 *     the event dispatch.
 *
 * `syncTemplates()`:
 *   - fetches templates from Meta via {@see WhatsAppProvider::fetchTemplates()};
 *   - upserts into `whatsapp_templates`, keeping ONLY `approved` templates
 *     per decision 15c;
 *   - returns the count persisted.
 *
 * Wrapped in `DB::transaction` so partial writes never leave the schema
 * half-built.
 */
class WhatsAppService
{
    public function __construct(
        public readonly WhatsAppProviderFactory $factory,
    ) {
    }

    /**
     * Send a pre-approved template to a phone number.
     *
     * @param  array<string, string|int>  $vars
     * @param  array{
     *     skip_dispatch?: bool,
     *     idempotency_window_seconds?: int,
     * }  $options
     */
    public function sendTemplateMessage(
        WhatsAppAccount $account,
        WhatsAppTemplate $template,
        string $phoneNumber,
        array $vars = [],
        ?User $actor = null,
        array $options = [],
    ): WhatsAppMessage {
        $now = now();
        $windowSeconds = (int) ($options['idempotency_window_seconds'] ?? 1);
        $idempotencyKey = $this->buildIdempotencyKey(
            $account,
            $template,
            $phoneNumber,
            $vars,
            $now->timestamp - ($now->timestamp % max($windowSeconds, 1)),
        );

        $message = DB::transaction(function () use (
            $account,
            $template,
            $phoneNumber,
            $idempotencyKey,
            $now,
        ): WhatsAppMessage {
            $conversation = WhatsAppConversation::query()
                ->where('account_id', $account->getKey())
                ->where('phone_number', $phoneNumber)
                ->first();

            if ($conversation === null) {
                $conversation = new WhatsAppConversation([
                    'account_id' => $account->getKey(),
                    'phone_number' => $phoneNumber,
                    'status' => WhatsAppConversation::STATUS_OPEN,
                    'last_direction' => WhatsAppConversation::DIRECTION_OUTBOUND,
                    'last_message_at' => $now,
                ]);
                $conversation->save();
            }

            // Idempotency short-circuit: if a queued/sent/delivered/read row
            // already exists with the same idempotency_key, return it.
            $existing = WhatsAppMessage::query()
                ->where('idempotency_key', $idempotencyKey)
                ->where('conversation_id', $conversation->getKey())
                ->whereIn('status', [
                    WhatsAppMessage::STATUS_QUEUED,
                    WhatsAppMessage::STATUS_SENT,
                    WhatsAppMessage::STATUS_DELIVERED,
                    WhatsAppMessage::STATUS_READ,
                ])
                ->first();

            if ($existing !== null) {
                return $existing->fresh(['conversation']);
            }

            $message = new WhatsAppMessage([
                'conversation_id' => $conversation->getKey(),
                'direction' => WhatsAppMessage::DIRECTION_OUTBOUND,
                'type' => 'template',
                'template_id' => $template->getKey(),
                'status' => WhatsAppMessage::STATUS_QUEUED,
                'idempotency_key' => $idempotencyKey,
                'provider_message_id' => 'pending-'.$conversation->getKey().'-'.$idempotencyKey,
            ]);
            $message->save();

            $conversation->forceFill([
                'last_message_at' => $now,
                'last_direction' => WhatsAppConversation::DIRECTION_OUTBOUND,
            ])->save();

            return $message->fresh(['conversation']);
        });

        if (($options['skip_dispatch'] ?? false) === false) {
            SendWhatsAppMessage::dispatch($message->getKey());
        }

        return $message;
    }

    /**
     * Persist an inbound draft returned by the webhook controller and
     * dispatch {@see WhatsAppInboundEvent} so future listeners can react.
     */
    public function handleInbound(WhatsAppMessage $draft): WhatsAppMessage
    {
        $persisted = DB::transaction(function () use ($draft): WhatsAppMessage {
            $draft->direction = WhatsAppMessage::DIRECTION_INBOUND;
            $draft->status = $draft->status ?: WhatsAppMessage::STATUS_QUEUED;
            $draft->save();

            return $draft->fresh();
        });

        WhatsAppInboundEvent::dispatch($persisted);

        return $persisted;
    }

    /**
     * Sync templates from Meta into the local catalogue. Only templates
     * with `status === 'APPROVED'` are persisted (decision 15c).
     *
     * Returns the count of templates persisted.
     */
    public function syncTemplates(WhatsAppAccount $account): int
    {
        $provider = $this->factory->for($account);
        $rawTemplates = $provider->fetchTemplates($account);
        $now = now();

        $synced = 0;
        DB::transaction(function () use ($account, $rawTemplates, &$synced, $now): void {
            foreach ($rawTemplates as $raw) {
                if (($raw) === null || ! is_array($raw)) {
                    continue;
                }
                if (strtolower((string) ($raw['status'] ?? '')) !== WhatsAppTemplate::STATUS_APPROVED) {
                    continue;
                }

                $name = (string) ($raw['name'] ?? '');
                $language = (string) ($raw['language'] ?? 'es_PE');
                if ($name === '') {
                    continue;
                }

                $row = WhatsAppTemplate::query()
                    ->where('account_id', $account->getKey())
                    ->where('name', $name)
                    ->where('language', $language)
                    ->first();

                if ($row === null) {
                    $row = new WhatsAppTemplate([
                        'account_id' => $account->getKey(),
                        'name' => $name,
                        'language' => $language,
                    ]);
                }

                $row->fill([
                    'status' => WhatsAppTemplate::STATUS_APPROVED,
                    'category' => $raw['category'] ?? null,
                    'body' => $raw['body'] ?? null,
                    'header_kind' => $raw['header_kind'] ?? null,
                    'header_text' => $raw['header_text'] ?? null,
                    'footer_text' => $raw['footer_text'] ?? null,
                    'variables_json' => $raw['variables'] ?? [],
                    'approved_at' => $row->approved_at ?? $now,
                    'synced_at' => $now,
                ])->save();

                $synced++;
            }
        });

        return $synced;
    }

    /**
     * Build the deterministic idempotency key for a given
     * (account, template, phone, vars, timestamp) tuple.
     *
     * @param  array<string, string|int>  $vars
     */
    private function buildIdempotencyKey(
        WhatsAppAccount $account,
        WhatsAppTemplate $template,
        string $phoneNumber,
        array $vars,
        int $timestamp,
    ): string {
        return sha1(
            $account->getKey()
            .'|'.$template->getKey()
            .'|'.$phoneNumber
            .'|'.json_encode($vars, JSON_THROW_ON_ERROR)
            .'|'.$timestamp
        );
    }
}