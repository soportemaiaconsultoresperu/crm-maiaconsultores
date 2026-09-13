<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commercial document idempotency key.
 *
 * A double submit of the registration form wrote two identical facturas with
 * the same total, duplicating the financial record and the IGV base. The key is
 * the only place a replay can be recognised without guessing at business
 * identity: `series` and `number` are nullable because externally issued
 * documents may not carry them.
 *
 * Additive and non-destructive: one new nullable CHAR(64) column plus a unique
 * index naming it, mirroring the platform's existing nullable idempotency
 * columns (`email_messages`, `whatsapp_messages`). Every existing row keeps
 * `NULL`, and a unique index treats NULLs as distinct values, so any number of
 * key-less documents coexist — including the ones already persisted. Rolling
 * back drops the index and then the column, which discards only idempotency
 * keys: no document, amount, payer or attachment is ever touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_commercial_documents', function (Blueprint $table): void {
            $table->char('idempotency_key', 64)->nullable()->after('document_id');
            $table->unique('idempotency_key', 'uq_course_commercial_documents_idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('course_commercial_documents', function (Blueprint $table): void {
            $table->dropUnique('uq_course_commercial_documents_idempotency_key');
            $table->dropColumn('idempotency_key');
        });
    }
};
