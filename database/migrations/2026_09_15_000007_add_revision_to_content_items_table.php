<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optimistic-concurrency column for the content editor (T8a).
 *
 * `revision` is a per-row counter that starts at 1 and is incremented on every
 * successful save. The edit form carries the revision it loaded; a save whose
 * revision no longer matches is refused instead of silently overwriting a
 * concurrent edit, so two people editing the same content never lose the first
 * person's work.
 *
 * This is the module's own `content_items` table, so the change is an additive
 * column on that table only: no other table is altered and no existing column
 * changes shape. New rows pick up the default without any caller having to set
 * it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->unsignedInteger('revision')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->dropColumn('revision');
        });
    }
};
