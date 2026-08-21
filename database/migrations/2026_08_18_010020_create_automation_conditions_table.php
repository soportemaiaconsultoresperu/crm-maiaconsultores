<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B12 — automation_conditions.
 *
 * `rule_id` is denormalized on top of `group_id` for query speed
 * (conditions for a rule are loaded without joining groups).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_conditions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('group_id');
            $table->unsignedBigInteger('rule_id');
            $table->string('field', 80);
            $table->string('operator', 32);
            $table->text('value')->nullable();
            $table->string('value_type', 16)->default('string');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index('group_id', 'idx_automation_conditions_group');
            $table->index('rule_id', 'idx_automation_conditions_rule');
        });

        if (Schema::hasTable('automation_condition_groups')) {
            Schema::table('automation_conditions', function (Blueprint $table): void {
                $table->foreign('group_id', 'fk_automation_conditions_group')
                    ->references('id')->on('automation_condition_groups')
                    ->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('automation_rules')) {
            Schema::table('automation_conditions', function (Blueprint $table): void {
                $table->foreign('rule_id', 'fk_automation_conditions_rule')
                    ->references('id')->on('automation_rules')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('automation_conditions')) {
            Schema::table('automation_conditions', function (Blueprint $table): void {
                $table->dropForeign('fk_automation_conditions_group');
                $table->dropForeign('fk_automation_conditions_rule');
            });
        }

        Schema::dropIfExists('automation_conditions');
    }
};