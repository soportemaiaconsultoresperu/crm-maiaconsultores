<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B14 — whatsapp_consent_log.
 *
 * Append-only ledger of opt-in / opt-out events for a contact. Meta's
 * Cloud API requires explicit opt-in before any business-initiated
 * conversation and forbids any further traffic after an opt-out. The
 * current state is mirrored on the conversation row for fast filtering
 * (`consent_at`, `opt_out_at`), but this log is the auditable source of
 * truth.
 *
 * Per docs/v2/01-roadmap.md §2.4. `type` values (opt_in|opt_out) are
 * constrained at the application layer by
 * {@see App\Models\WhatsApp\WhatsAppConsentLog::TYPE_*} constants — no
 * new MySQL ENUMs per C-03.
 *
 * The `conversation_id` link is nullable so a contact can opt-in or
 * opt-out before any conversation has been opened (e.g. via the public
 * web form opt-in flow).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_consent_log', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('contact_id');
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->string('type', 16); // opt_in|opt_out
            $table->string('source', 40)->nullable();

            $table->timestamp('granted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->index(['contact_id', 'type'], 'idx_whatsapp_consent_log_contact_type');
            $table->index('conversation_id', 'idx_whatsapp_consent_log_conversation');
            $table->index('source', 'idx_whatsapp_consent_log_source');
        });

        if (Schema::hasTable('contacts')) {
            Schema::table('whatsapp_consent_log', function (Blueprint $table): void {
                $table->foreign('contact_id', 'fk_whatsapp_consent_log_contact')
                    ->references('id')->on('contacts')
                    ->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('whatsapp_conversations')) {
            Schema::table('whatsapp_consent_log', function (Blueprint $table): void {
                $table->foreign('conversation_id', 'fk_whatsapp_consent_log_conversation')
                    ->references('id')->on('whatsapp_conversations')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('whatsapp_consent_log')) {
            Schema::table('whatsapp_consent_log', function (Blueprint $table): void {
                $table->dropForeign('fk_whatsapp_consent_log_contact');
                $table->dropForeign('fk_whatsapp_consent_log_conversation');
            });
        }

        Schema::dropIfExists('whatsapp_consent_log');
    }
};
