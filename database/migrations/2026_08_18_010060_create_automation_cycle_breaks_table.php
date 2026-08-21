<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B12 — automation_cycle_breaks.
 *
 * Records the moment an AutomationCycleDetector decided a rule was about
 * to loop on the same subject within the cycle window. Indexed by
 * (rule_id, subject_type, subject_id) so cycle queries stay fast.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_cycle_breaks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('rule_id');
            $table->string('subject_type', 80);
            $table->unsignedBigInteger('subject_id');
            $table->string('reason', 191);
            $table->timestamp('detected_at');
            $table->timestamps();

            $table->index(['rule_id', 'subject_type', 'subject_id'], 'idx_automation_cycle_breaks_subject');
        });

        if (Schema::hasTable('automation_rules')) {
            Schema::table('automation_cycle_breaks', function (Blueprint $table): void {
                $table->foreign('rule_id', 'fk_automation_cycle_breaks_rule')
                    ->references('id')->on('automation_rules')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('automation_cycle_breaks')) {
            Schema::table('automation_cycle_breaks', function (Blueprint $table): void {
                $table->dropForeign('fk_automation_cycle_breaks_rule');
            });
        }

        Schema::dropIfExists('automation_cycle_breaks');
    }
};