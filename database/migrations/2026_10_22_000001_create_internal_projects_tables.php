<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal projects (odd/tasks/internal-projects.md, T1: S2, S3, S5).
 *
 * An internal project (e.g. a software development) is planned as ordered
 * phases, each holding tasks. Progress is the share of task weight already
 * "terminada"; every status change is recorded for auditability.
 *
 * Visibility follows the internal activities model through `audience_type`:
 * - all:   every active user.
 * - roles: users holding one of the roles in `internal_project_audience_roles`.
 * - users: the users listed in `internal_project_audience_users`.
 * The creator, the responsible and the members (`internal_project_members`,
 * the team that edits phases and tasks) always see the project.
 *
 * A task always belongs to a phase of its own project; `internal_project_id`
 * is kept on the task so project-wide progress, Gantt and calendar queries
 * need no join through phases.
 *
 * Additive only: new tables, no existing table is altered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_projects', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('code', 30)->unique();
            $table->text('description')->nullable();
            $table->string('color', 7)->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('status', 20)->default('planificado');
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('audience_type', 20)->default('all');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        Schema::create('internal_project_members', function (Blueprint $table) {
            $table->foreignId('internal_project_id')->constrained('internal_projects')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->primary(['internal_project_id', 'user_id'], 'internal_project_members_primary');
        });

        Schema::create('internal_project_audience_roles', function (Blueprint $table) {
            $table->foreignId('internal_project_id')->constrained('internal_projects')->cascadeOnDelete();
            $table->unsignedBigInteger('role_id');
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->primary(['internal_project_id', 'role_id'], 'internal_project_audience_roles_primary');
        });

        Schema::create('internal_project_audience_users', function (Blueprint $table) {
            $table->foreignId('internal_project_id')->constrained('internal_projects')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->primary(['internal_project_id', 'user_id'], 'internal_project_audience_users_primary');
        });

        Schema::create('internal_project_phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internal_project_id')->constrained('internal_projects')->cascadeOnDelete();
            $table->string('name', 120);
            $table->integer('sort')->default(0);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestamps();

            $table->index(['internal_project_id', 'sort'], 'ip_phases_project_sort_idx');
        });

        Schema::create('internal_project_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internal_project_id')->constrained('internal_projects')->cascadeOnDelete();
            $table->foreignId('internal_project_phase_id')->constrained('internal_project_phases')->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('starts_on');
            $table->date('due_on');
            $table->string('status', 20)->default('pendiente');
            $table->unsignedSmallInteger('weight')->default(1);
            $table->boolean('is_milestone')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->integer('sort')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['internal_project_id', 'status'], 'ip_tasks_project_status_idx');
            $table->index('due_on', 'ip_tasks_due_on_idx');
            $table->index('status', 'ip_tasks_status_idx');
        });

        Schema::create('internal_project_task_status_changes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('internal_project_task_id');
            $table->foreign('internal_project_task_id', 'ip_status_changes_task_fk')
                ->references('id')->on('internal_project_tasks')->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreign('user_id', 'ip_status_changes_user_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->timestamp('changed_at');

            $table->index(['internal_project_task_id', 'changed_at'], 'ip_status_changes_task_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_project_task_status_changes');
        Schema::dropIfExists('internal_project_tasks');
        Schema::dropIfExists('internal_project_phases');
        Schema::dropIfExists('internal_project_audience_users');
        Schema::dropIfExists('internal_project_audience_roles');
        Schema::dropIfExists('internal_project_members');
        Schema::dropIfExists('internal_projects');
    }
};
