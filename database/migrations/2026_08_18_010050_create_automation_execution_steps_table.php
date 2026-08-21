<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B12 — automation_execution_steps.
 *
 * One row per (execution, action) attempt. Retries increment `attempt`;
 * `response_json` captures the would-be payload in test mode or the
 * truncated response body in live mode.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_execution_steps', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('execution_id');
            $table->unsignedBigInteger('action_id');
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('attempt')->default(1);
            $table->json('response_json')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('error_class', 191)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('execution_id', 'idx_automation_execution_steps_execution');
            $table->index('action_id', 'idx_automation_execution_steps_action');
        });

        if (Schema::hasTable('automation_executions')) {
            Schema::table('automation_execution_steps', function (Blueprint $table): void {
                $table->foreign('execution_id', 'fk_automation_execution_steps_execution')
                    ->references('id')->on('automation_executions')
                    ->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('automation_actions')) {
            Schema::table('automation_execution_steps', function (Blueprint $table): void {
                $table->foreign('action_id', 'fk_automation_execution_steps_action')
                    ->references('id')->on('automation_actions')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('automation_execution_steps')) {
            Schema::table('automation_execution_steps', function (Blueprint $table): void {
                $table->dropForeign('fk_automation_execution_steps_execution');
                $table->dropForeign('fk_automation_execution_steps_action');
            });
        }

        Schema::dropIfExists('automation_execution_steps');
    }
};