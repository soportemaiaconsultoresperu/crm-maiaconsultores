<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B17 — outbound_deliveries.
 *
 * Append-only ledger of every outbound notification attempt dispatched
 * through the B17 notification subsystem (database | mail | whatsapp |
 * webhook). Each row documents one logical dispatch with up to N retry
 * attempts; idempotency is keyed on the operation id (`idempotency_key`,
 * CHAR(64)) — collisions short-circuit at the application layer rather than
 * relying on a payload hash (per docs/v2/01-roadmap.md §2.7: "idempotencia
 * por operación, no por payload_hash").
 *
 * Per docs/v2/01-roadmap.md §2.7 and §10 (D-21a..D-21g). Template and channel
 * lifecycle is validated at the application layer via the STATUS_* / CHANNEL_*
 * constants on {@see \App\Models\Notification\OutboundDelivery} (no new MySQL
 * ENUMs per C-03).
 *
 * Notes on the foreign keys:
 *  - `account_id` is a nullable FK to `integration_accounts` (cascadeOnDelete
 *    so removing a vendor account also wipes its delivery history; mirrors
 *    the B14 `whatsapp_accounts.account_id` shape).
 *  - `template_id` is reserved for the future B17.x template system. It is
 *    intentionally NOT a real FK this run — no `notification_templates`
 *    table exists yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_deliveries', function (Blueprint $table): void {
            $table->id();

            $table->string('channel', 16);
            $table->string('recipient_ref', 191);

            // Reserved for the future B17.x template system. No FK constraint
            // is declared this run because `notification_templates` does not
            // exist yet (the seeder / migration pair from B17 Pasada B will
            // materialise it and add the FK then).
            $table->unsignedBigInteger('template_id')->nullable();

            // Polymorphic reference to the originating domain entity (Lead,
            // Customer, Opportunity, Quotation, Activity, ...). Mirrors the
            // shape used elsewhere in the codebase (e.g. automation_cycle_breaks).
            $table->string('related_entity_type', 80)->nullable();
            $table->unsignedBigInteger('related_entity_id')->nullable();

            $table->unsignedBigInteger('account_id')->nullable();

            $table->string('status', 16)->default('queued');
            $table->integer('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->integer('last_response_code')->nullable();

            $table->char('idempotency_key', 64)->unique();

            $table->timestamps();

            $table->index(['channel', 'status'], 'idx_outbound_deliveries_channel_status');
            $table->index(['related_entity_type', 'related_entity_id'], 'idx_outbound_deliveries_entity');
            $table->index('recipient_ref', 'idx_outbound_deliveries_recipient');
        });

        if (Schema::hasTable('integration_accounts')) {
            Schema::table('outbound_deliveries', function (Blueprint $table): void {
                $table->foreign('account_id', 'fk_outbound_deliveries_account')
                    ->references('id')->on('integration_accounts')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('outbound_deliveries')) {
            Schema::table('outbound_deliveries', function (Blueprint $table): void {
                $table->dropForeign('fk_outbound_deliveries_account');
            });
        }

        Schema::dropIfExists('outbound_deliveries');
    }
};
