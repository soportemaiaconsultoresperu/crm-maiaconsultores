<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B12 — automation_actions.
 *
 * `type` stores the FQCN of an ActionContract implementation. `payload_json`
 * carries action-specific configuration (e.g. for SendEmail it carries
 * `to`, `subject`, `body`); only this column is JSON per the C-02 rule
 * that JSON stays at the action-specific configuration level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_actions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('rule_id');
            $table->unsignedInteger('position')->default(0);
            $table->string('type', 80);
            $table->string('channel', 40)->nullable();
            $table->string('recipient_strategy', 80)->nullable();
            $table->json('payload_json')->nullable();
            $table->json('retry_policy_json')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('rule_id', 'idx_automation_actions_rule');
        });

        if (Schema::hasTable('automation_rules')) {
            Schema::table('automation_actions', function (Blueprint $table): void {
                $table->foreign('rule_id', 'fk_automation_actions_rule')
                    ->references('id')->on('automation_rules')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('automation_actions')) {
            Schema::table('automation_actions', function (Blueprint $table): void {
                $table->dropForeign('fk_automation_actions_rule');
            });
        }

        Schema::dropIfExists('automation_actions');
    }
};