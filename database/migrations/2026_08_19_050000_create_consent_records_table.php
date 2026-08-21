<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B21 — Consentimientos: una fila por (subject, channel, source, purpose)
 *
 * - `subject_type` ∈ {'contact', 'customer', 'lead'}
 * - `channel` ∈ {'email', 'whatsapp'}
 * - `status` ∈ {'active', 'revoked', 'expired'}
 * - `evidence` obligatorio (URL, hash, screenshot path, etc.) para compliance INDECOPI
 * - `revoked_at` y `revoked_reason` se llenan al revocarse
 * - UNIQUE (subject_type, subject_id, channel, purpose) — un subject tiene 1 row por canal+finalidad
 *
 * B22 consulta esta tabla en cada dispatch + `suppression_entries` para listas de exclusión.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_records', function (Blueprint $table) {
            $table->id();
            $table->morphs('subject');                 // subject_type + subject_id
            $table->string('channel', 16);             // 'email' | 'whatsapp'
            $table->string('source', 80);              // 'web_form' | 'manual' | 'import' | 'imported_legacy'
            $table->text('evidence')->nullable();      // URL, hash, screenshot path — INDECOPI compliance
            $table->string('purpose', 191);            // 'marketing_newsletter' | 'promotional' | 'transactional' | 'support'
            $table->string('status', 16)->default('active');  // 'active' | 'revoked' | 'expired'
            $table->timestamp('granted_at')->useCurrent();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revoked_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // morphs() crea (subject_type, subject_id) index + FK; agregamos
            // un índice compuesto para las queries de elegibilidad por canal + status.
            $table->index(['channel', 'status', 'granted_at'], 'consent_eligibility_idx');
            $table->index(['status', 'granted_at'], 'consent_status_granted_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_records');
    }
};
