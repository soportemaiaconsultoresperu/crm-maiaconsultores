<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lead `last_name` was declared NOT NULL in the original create migration, but
 * for `person_type = juridica` the field is meaningless (a company has no
 * surname). Making it nullable aligns the schema with the form semantics
 * (RF-LEAD-001) and removes the 1048 constraint violation that surfaced when
 * admins saved a juridica prospect without filling `last_name`.
 *
 * Existing rows are not modified: NULL is allowed going forward and any prior
 * non-null value stays as-is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->string('last_name', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->string('last_name', 100)->nullable(false)->change();
        });
    }
};
