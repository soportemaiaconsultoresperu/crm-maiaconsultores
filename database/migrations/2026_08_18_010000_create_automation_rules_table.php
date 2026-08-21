<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B12 — automation_rules.
 *
 * A rule has a single trigger_event (the FQCN of a domain event), an
 * execution mode (live|test), an ordering hint and optional ownership.
 *
 * Per docs/v2/01-roadmap.md §2.1 the rule stores NO conditions/actions JSON;
 * those live in their own tables and are the source of truth.
 *
 * Conditions for foreign keys are wrapped in Schema::hasTable() guards
 * because the B11 migrations and the B12 migrations may be applied in
 * different orderings across environments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 191);
            $table->text('description')->nullable();
            $table->string('trigger_event', 191);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('order')->default(0);
            $table->string('mode', 16)->default('live');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['trigger_event', 'is_active'], 'idx_automation_rules_trigger_active');
            $table->index('owner_id', 'idx_automation_rules_owner');
        });

        if (Schema::hasTable('users')) {
            Schema::table('automation_rules', function (Blueprint $table): void {
                $table->foreign('created_by', 'fk_automation_rules_created_by')
                    ->references('id')->on('users')
                    ->nullOnDelete();
                $table->foreign('owner_id', 'fk_automation_rules_owner')
                    ->references('id')->on('users')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('automation_rules')) {
            Schema::table('automation_rules', function (Blueprint $table): void {
                $table->dropForeign('fk_automation_rules_created_by');
                $table->dropForeign('fk_automation_rules_owner');
            });
        }

        Schema::dropIfExists('automation_rules');
    }
};