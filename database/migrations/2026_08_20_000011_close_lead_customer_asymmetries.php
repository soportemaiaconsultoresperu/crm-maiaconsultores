<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closes the inverse asymmetry from `customers` perspective: a `leads` row
 * is a PROSPECT, but juridica prospects are companies and need the same
 * fiscal / commercial identity fields that customers have. Without them,
 * converting a juridica lead to a customer (ADR-001) loses information
 * because there is nowhere to capture `legal_name` / `trade_name` /
 * `sector` / `website` during the prospecting stage.
 *
 * All four are nullable because natural-person prospects do not need them
 * (the form will only show them when person_type === 'juridica').
 *
 * Down() drops every column this migration adds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->string('legal_name', 180)->nullable()->after('company_name');
            $table->string('trade_name', 180)->nullable()->after('legal_name');
            $table->string('sector', 100)->nullable()->after('ubigeo_code');
            $table->string('website', 150)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropColumn(['legal_name', 'trade_name', 'sector', 'website']);
        });
    }
};