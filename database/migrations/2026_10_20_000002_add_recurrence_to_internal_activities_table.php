<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recurrence for internal activities (odd/tasks/internal-activities.md, T2).
 *
 * `recurrence_frequency` is null for a single occurrence, or one of daily |
 * weekly | monthly. `recurrence_until` is the last calendar day an occurrence
 * may start on. Occurrences are never stored: the calendar expands them at
 * read time from `starts_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internal_activities', function (Blueprint $table) {
            $table->string('recurrence_frequency', 20)->nullable()->after('audience_type');
            $table->date('recurrence_until')->nullable()->after('recurrence_frequency');
        });
    }

    public function down(): void
    {
        Schema::table('internal_activities', function (Blueprint $table) {
            $table->dropColumn(['recurrence_frequency', 'recurrence_until']);
        });
    }
};
