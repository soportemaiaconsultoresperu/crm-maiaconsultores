<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-project list of hidden optional standard fields (S2).
 *
 * The column holds the keys of the optional standard fields (pillar, topic,
 * objectives, formats, channels, hashtags, notes) the project does not use.
 * Null or an empty list means every field is shown, so existing projects keep
 * today's behaviour. Hiding a field never touches the stored content data.
 *
 * Additive nullable column on the module's own table only; JSON is supported
 * by MySQL and stored as text by SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_projects', function (Blueprint $table) {
            $table->json('hidden_standard_fields')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('content_projects', function (Blueprint $table) {
            $table->dropColumn('hidden_standard_fields');
        });
    }
};
