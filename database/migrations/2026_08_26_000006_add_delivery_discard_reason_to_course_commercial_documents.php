<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commercial document delivery discard reason.
 *
 * The two document channels share the delivery workflow, but only the academic
 * table carried `delivery_discard_reason`, so a discarded commercial follow-up
 * could never say WHY it was discarded while the certificate could. The column
 * mirrors the academic one exactly: nullable `text`, no default, so "no reason
 * on record" is representable and no existing row is invalidated.
 *
 * Additive and non-destructive: one nullable column is appended after
 * `delivery_status`. Pre-existing rows keep `NULL` — the previous behaviour,
 * where the reason simply could not be recorded — so nothing that already
 * exists is invalidated or rewritten, and the migration is safe to run on a
 * populated table (no default backfill, no lock beyond the DDL itself).
 * Rolling back drops only this column: every commercial document, its amounts,
 * its attachment and its delivery status survive untouched, and the only loss
 * is the reason text of a discard recorded after this migration. Document
 * validity is orthogonal: discarding the follow-up never annuls a certificate
 * or a comprobante, and this column records metadata about the follow-up only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_commercial_documents', function (Blueprint $table): void {
            $table->text('delivery_discard_reason')->nullable()->after('delivery_status');
        });
    }

    public function down(): void
    {
        Schema::table('course_commercial_documents', function (Blueprint $table): void {
            $table->dropColumn('delivery_discard_reason');
        });
    }
};
