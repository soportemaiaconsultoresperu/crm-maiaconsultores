<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B14 — whatsapp_messages.
 *
 * One row per message exchanged with Meta Cloud API. `provider_message_id`
 * is the id Meta returns for outbound messages or the inbound webhook id;
 * it is UNIQUE so duplicate webhooks (Meta retries on timeout) become a
 * constraint violation instead of a duplicate row.
 *
 * Per docs/v2/01-roadmap.md §2.4. Status values
 * (queued|sent|delivered|read|failed) are constrained at the application
 * layer by {@see App\Models\WhatsApp\WhatsAppMessage::STATUS_*}
 * constants — no new MySQL ENUMs per C-03. `idempotency_key` is the
 * SHA-256 hash computed by the service layer (rule_id + payload hash) so
 * automation-driven sends cannot double-fire on retry.
 *
 * `wamid` (WhatsApp Message ID) is separate from `provider_message_id`
 * because some adapter implementations key the unique row by the API
 * envelope id, not the inner wamid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('conversation_id');
            $table->string('provider_message_id', 191);
            $table->string('wamid', 191)->nullable();
            $table->string('direction', 8);
            $table->string('type', 16);
            $table->text('body')->nullable();

            $table->unsignedBigInteger('template_id')->nullable();

            $table->string('status', 16)->default('queued');
            $table->string('error_class', 191)->nullable();
            $table->text('error_message')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();

            $table->char('idempotency_key', 64)->nullable();

            $table->timestamps();

            $table->unique('provider_message_id', 'uq_whatsapp_messages_provider_message');
            $table->unique('idempotency_key', 'uq_whatsapp_messages_idempotency');
            $table->index(['conversation_id', 'status'], 'idx_whatsapp_messages_conv_status');
            $table->index('direction', 'idx_whatsapp_messages_direction');
            $table->index('template_id', 'idx_whatsapp_messages_template');
            $table->index('status', 'idx_whatsapp_messages_status');
        });

        if (Schema::hasTable('whatsapp_conversations')) {
            Schema::table('whatsapp_messages', function (Blueprint $table): void {
                $table->foreign('conversation_id', 'fk_whatsapp_messages_conversation')
                    ->references('id')->on('whatsapp_conversations')
                    ->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('whatsapp_templates')) {
            Schema::table('whatsapp_messages', function (Blueprint $table): void {
                $table->foreign('template_id', 'fk_whatsapp_messages_template')
                    ->references('id')->on('whatsapp_templates')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('whatsapp_messages')) {
            Schema::table('whatsapp_messages', function (Blueprint $table): void {
                $table->dropForeign('fk_whatsapp_messages_conversation');
                $table->dropForeign('fk_whatsapp_messages_template');
            });
        }

        Schema::dropIfExists('whatsapp_messages');
    }
};
