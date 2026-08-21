<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B12 — automation_executions.
 *
 * One row per (rule, event, subject) attempt. The idempotency_key is a
 * SHA-1 over rule + event + subject + payload_hash; combined with the UNIQUE
 * constraint it prevents duplicate executions even when the lock window is
 * missed.
 *
 * Status values are constrained at the application layer by the
 * App\Enums\AutomationExecutionStatus class — the column is VARCHAR per
 * C-03 (no new MySQL ENUMs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_executions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('rule_id');
            $table->string('trigger_event', 191);
            $table->string('subject_type', 80);
            $table->unsignedBigInteger('subject_id');
            $table->char('idempotency_key', 64)->unique();
            $table->string('status', 16)->default('queued');
            $table->unsignedInteger('attempt')->default(1);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('error_class', 191)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['rule_id', 'status'], 'idx_automation_executions_rule_status');
            $table->index(['subject_type', 'subject_id'], 'idx_automation_executions_subject');
            $table->index(['status', 'created_at'], 'idx_automation_executions_status_created');
        });

        if (Schema::hasTable('automation_rules')) {
            Schema::table('automation_executions', function (Blueprint $table): void {
                $table->foreign('rule_id', 'fk_automation_executions_rule')
                    ->references('id')->on('automation_rules')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('automation_executions')) {
            Schema::table('automation_executions', function (Blueprint $table): void {
                $table->dropForeign('fk_automation_executions_rule');
            });
        }

        Schema::dropIfExists('automation_executions');
    }
};