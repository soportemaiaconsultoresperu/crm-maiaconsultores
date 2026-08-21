<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B12 — automation_condition_groups.
 *
 * Groups conditions with an AND/OR logical operator. A rule may have many
 * groups; the final evaluation is AND across groups (per docs/v2/01-roadmap
 * §2.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_condition_groups', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('rule_id');
            $table->string('logical_operator', 8)->default('AND');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index('rule_id', 'idx_automation_condition_groups_rule');
        });

        if (Schema::hasTable('automation_rules')) {
            Schema::table('automation_condition_groups', function (Blueprint $table): void {
                $table->foreign('rule_id', 'fk_automation_condition_groups_rule')
                    ->references('id')->on('automation_rules')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('automation_condition_groups')) {
            Schema::table('automation_condition_groups', function (Blueprint $table): void {
                $table->dropForeign('fk_automation_condition_groups_rule');
            });
        }

        Schema::dropIfExists('automation_condition_groups');
    }
};