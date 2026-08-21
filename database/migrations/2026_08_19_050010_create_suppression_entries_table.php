<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B21 — Lista de exclusión (suppression list).
 *
 * - `subject_type` ∈ {'contact', 'customer', 'lead'}
 * - `channel` ∈ {'email', 'whatsapp'} | NULL (global, todos los canales)
 * - `reason` ∈ {'opt_out' | 'bounce' | 'complaint' | 'manual'}
 * - `expires_at` NULL = permanente; futuro = temporal (ej. soft-bounce con retry window)
 *
 * B22 consulta `isEligible(subject, channel)` que verifica:
 *  1. NO hay suppression_entry activa para (subject, channel) o (subject, NULL).
 *  2. Hay consent_record 'active' para (subject, channel, purpose).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppression_entries', function (Blueprint $table) {
            $table->id();
            $table->morphs('subject');
            $table->string('channel', 16)->nullable();     // NULL = global (todos los canales)
            $table->string('reason', 80);                  // 'opt_out' | 'bounce' | 'complaint' | 'manual'
            $table->string('source', 80)->nullable();      // 'web_form' | 'campaign_link' | 'manual' | 'provider_webhook'
            $table->timestamp('expires_at')->nullable();   // NULL = permanente
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['channel', 'reason'], 'suppression_channel_reason_idx');
            $table->index(['expires_at'], 'suppression_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppression_entries');
    }
};
