<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B14 — whatsapp_conversations.
 *
 * One row per (account, phone_number) thread. A conversation can be
 * linked to at most one of {contact, customer, lead} — the B14 service
 * layer (Pasada B) reconciles the link on inbound traffic and on lead
 * reassignment. The optional FKs are added conditionally to stay safe
 * across migration orderings.
 *
 * Per docs/v2/01-roadmap.md §2.4. The 24-hour customer-service window is
 * tracked explicitly via `window_opens_at` / `window_closes_at` so the
 * service layer can decide whether a freeform message is allowed without
 * re-computing on every send. Consent is tracked in `whatsapp_consent_log`
 * and mirrored here (`consent_at`, `opt_out_at`) for fast filtering.
 *
 * Status values (open|closed|archived) are constrained at the application
 * layer by {@see App\Models\WhatsApp\WhatsAppConversation::STATUS_*}
 * constants — no new MySQL ENUMs per C-03.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_conversations', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('lead_id')->nullable();

            $table->string('phone_number', 191);
            $table->string('contact_name', 191)->nullable();

            $table->string('status', 16)->default('open');

            $table->unsignedBigInteger('assigned_to')->nullable();

            $table->timestamp('last_message_at')->nullable();
            $table->string('last_direction', 8)->nullable();

            $table->timestamp('consent_at')->nullable();
            $table->timestamp('opt_out_at')->nullable();
            $table->timestamp('window_opens_at')->nullable();
            $table->timestamp('window_closes_at')->nullable();

            $table->timestamps();

            $table->index(['account_id', 'status'], 'idx_whatsapp_conversations_account_status');
            $table->index(['assigned_to', 'status'], 'idx_whatsapp_conversations_assigned_status');
            $table->index('phone_number', 'idx_whatsapp_conversations_phone');
            $table->index('contact_id', 'idx_whatsapp_conversations_contact');
            $table->index('customer_id', 'idx_whatsapp_conversations_customer');
            $table->index('lead_id', 'idx_whatsapp_conversations_lead');
        });

        if (Schema::hasTable('whatsapp_accounts')) {
            Schema::table('whatsapp_conversations', function (Blueprint $table): void {
                $table->foreign('account_id', 'fk_whatsapp_conversations_account')
                    ->references('id')->on('whatsapp_accounts')
                    ->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('contacts')) {
            Schema::table('whatsapp_conversations', function (Blueprint $table): void {
                $table->foreign('contact_id', 'fk_whatsapp_conversations_contact')
                    ->references('id')->on('contacts')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('customers')) {
            Schema::table('whatsapp_conversations', function (Blueprint $table): void {
                $table->foreign('customer_id', 'fk_whatsapp_conversations_customer')
                    ->references('id')->on('customers')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('leads')) {
            Schema::table('whatsapp_conversations', function (Blueprint $table): void {
                $table->foreign('lead_id', 'fk_whatsapp_conversations_lead')
                    ->references('id')->on('leads')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('whatsapp_conversations', function (Blueprint $table): void {
                $table->foreign('assigned_to', 'fk_whatsapp_conversations_assigned_to')
                    ->references('id')->on('users')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('whatsapp_conversations')) {
            Schema::table('whatsapp_conversations', function (Blueprint $table): void {
                $table->dropForeign('fk_whatsapp_conversations_account');
                $table->dropForeign('fk_whatsapp_conversations_contact');
                $table->dropForeign('fk_whatsapp_conversations_customer');
                $table->dropForeign('fk_whatsapp_conversations_lead');
                $table->dropForeign('fk_whatsapp_conversations_assigned_to');
            });
        }

        Schema::dropIfExists('whatsapp_conversations');
    }
};
