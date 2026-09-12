<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-4 — persist the notification content the listener/service built.
 *
 * Until now `NotificationService::dispatch()` used the `payload` only to
 * compute the idempotency key and threw the content away, so
 * `SendOutboundDelivery` (which is dispatched BY ID, and re-runs on retry and
 * from the admin "Reintentar" button) had nothing to send and fabricated a
 * placeholder body. The content now travels with the row.
 *
 * Forward effect (additive, non-locking data-wise on SQLite/MySQL):
 *   - adds a nullable `payload` JSON column to `outbound_deliveries`;
 *   - existing rows keep NULL — the job falls back to an explicit
 *     "no stored content" notice for them (it cannot invent content that was
 *     never persisted);
 *   - no existing column is altered, no index/unique/FK is touched, and no
 *     application writes change shape for other columns.
 *
 * Rollback effect: `down()` drops the column, and with it the stored content
 * of every delivery. The rows themselves (the ledger) survive; a delivery
 * re-run after a rollback behaves like a legacy row. Roll forward again by
 * re-running `migrate` — historical payloads are NOT recoverable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_deliveries', function (Blueprint $table): void {
            $table->json('payload')->nullable()->after('last_response_code');
        });
    }

    public function down(): void
    {
        Schema::table('outbound_deliveries', function (Blueprint $table): void {
            $table->dropColumn('payload');
        });
    }
};
