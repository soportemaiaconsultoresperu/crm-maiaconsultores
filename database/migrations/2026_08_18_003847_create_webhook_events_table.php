<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B11 — webhook_events.
 *
 * Persists every inbound webhook delivery so we can:
 *   - dedupe by (provider, external_event_id) UNIQUE constraint;
 *   - audit signature verification decisions (status='rejected' vs 'received');
 *   - replay a payload if downstream processing failed.
 *
 * `payload_hash` is sha256 of the raw body, separate from `signature`
 * which is the provider-supplied header value (kept for forensics only,
 * never to validate again at replay time).
 *
 * Per docs/v2/01-roadmap.md §2.2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 40);
            $table->string('external_event_id', 191);
            $table->char('payload_hash', 64);
            $table->text('signature')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            // received|processed|rejected|failed (VARCHAR + PHP enum, never ENUM MySQL).
            $table->string('status', 16)->default('received');
            $table->string('error_class', 191)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_event_id'], 'uniq_webhook_events_provider_event');
            $table->index(['status', 'received_at'], 'idx_webhook_events_status_received');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};