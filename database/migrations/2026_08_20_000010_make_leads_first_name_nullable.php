<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Companion to 2026_08_20_000000_make_leads_last_name_nullable.
 *
 * Lead `first_name` was declared NOT NULL in the original create migration,
 * but for `person_type = juridica` the form now hides the field (the company
 * already has its name in `company_name`). Making it nullable removes the
 * 1048 constraint violation when an admin saves a juridica prospect without
 * filling `first_name`.
 *
 * Existing rows are not modified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->string('first_name', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->string('first_name', 100)->nullable(false)->change();
        });
    }
};
