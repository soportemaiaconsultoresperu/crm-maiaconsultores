<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal activities (odd/tasks/internal-activities.md, T1).
 *
 * An internal activity is an organization event (meeting, training, any kind
 * of internal work) that is not tied to a lead, customer or opportunity. It
 * is shown as the "Interno" layer of the calendar.
 *
 * Visibility is driven by `audience_type`:
 * - all:   every active user.
 * - roles: users holding one of the roles in `internal_activity_roles`.
 * - users: the users listed in `internal_activity_audience_users`.
 * The creator and the participants always see the activity.
 *
 * Participants live in `internal_activity_participants`; at most one of them
 * is flagged `is_responsible`.
 *
 * Additive only: new tables, no existing table is altered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_activities', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->string('location', 150)->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->string('audience_type', 20)->default('all');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index('starts_at');
        });

        Schema::create('internal_activity_roles', function (Blueprint $table) {
            $table->foreignId('internal_activity_id')->constrained('internal_activities')->cascadeOnDelete();
            $table->unsignedBigInteger('role_id');
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->primary(['internal_activity_id', 'role_id']);
        });

        Schema::create('internal_activity_audience_users', function (Blueprint $table) {
            $table->foreignId('internal_activity_id')->constrained('internal_activities')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->primary(['internal_activity_id', 'user_id'], 'internal_activity_audience_users_primary');
        });

        Schema::create('internal_activity_participants', function (Blueprint $table) {
            $table->foreignId('internal_activity_id')->constrained('internal_activities')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_responsible')->default(false);
            $table->primary(['internal_activity_id', 'user_id'], 'internal_activity_participants_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_activity_participants');
        Schema::dropIfExists('internal_activity_audience_users');
        Schema::dropIfExists('internal_activity_roles');
        Schema::dropIfExists('internal_activities');
    }
};
