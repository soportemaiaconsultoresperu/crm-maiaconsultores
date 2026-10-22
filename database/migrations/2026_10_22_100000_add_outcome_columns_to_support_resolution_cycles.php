<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-cycle outcome so a reopened ticket keeps the first cycle's resolution,
 * validation and close times (design §10/§11); the ticket row only mirrors
 * the latest cycle.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('support_resolution_cycles', function (Blueprint $table): void {
            $table->dateTime('resolved_at')->nullable()->after('ended_at');
            $table->foreignId('resolved_by')->nullable()->after('resolved_at')->constrained('users');
            $table->text('solution_summary')->nullable()->after('resolved_by');
            $table->dateTime('validated_at')->nullable()->after('solution_summary');
            $table->dateTime('closed_at')->nullable()->after('validated_at');
        });
    }

    public function down(): void
    {
        Schema::table('support_resolution_cycles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropColumn(['resolved_at', 'solution_summary', 'validated_at', 'closed_at']);
        });
    }
};
