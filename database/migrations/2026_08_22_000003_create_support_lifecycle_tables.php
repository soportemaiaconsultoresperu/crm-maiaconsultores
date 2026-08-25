<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->text('solution_summary')->nullable()->after('reopen_reason');
            $table->text('close_reason')->nullable()->after('solution_summary');
            $table->dateTime('work_started_at')->nullable()->after('first_responded_at');
            $table->dateTime('resolved_at')->nullable()->after('work_started_at');
            $table->dateTime('validated_at')->nullable()->after('resolved_at');
            $table->dateTime('closed_at')->nullable()->after('validated_at');
        });
        Schema::create('support_session_details', function (Blueprint $table): void {
            $table->id(); $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('activity_id')->unique()->constrained('activities')->cascadeOnDelete();
            $table->string('modality', 30); $table->boolean('attendance_recorded')->default(false); $table->string('topic')->nullable(); $table->text('objective')->nullable(); $table->text('agenda')->nullable(); $table->text('notes')->nullable(); $table->text('materials')->nullable(); $table->text('result')->nullable(); $table->text('next_action')->nullable();
            $table->string('virtual_platform')->nullable(); $table->string('virtual_link')->nullable(); $table->text('access_instructions')->nullable(); $table->string('address')->nullable(); $table->text('location_reference')->nullable(); $table->string('location_contact')->nullable(); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('support_reschedules', function (Blueprint $table): void {
            $table->id(); $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete(); $table->foreignId('activity_id')->constrained('activities')->cascadeOnDelete(); $table->dateTime('old_scheduled_at'); $table->dateTime('new_scheduled_at'); $table->text('reason'); $table->foreignId('rescheduled_by')->constrained('users'); $table->foreignId('responsible_id')->nullable()->constrained('users'); $table->timestamps();
        });
        Schema::create('support_incident_details', function (Blueprint $table): void {
            $table->id(); $table->foreignId('ticket_id')->unique()->constrained('support_tickets')->cascadeOnDelete();
            $table->string('system')->nullable(); $table->string('module')->nullable(); $table->string('environment')->nullable(); $table->string('version')->nullable(); $table->text('steps_to_reproduce')->nullable(); $table->text('expected_result')->nullable(); $table->text('actual_result')->nullable(); $table->string('severity')->nullable(); $table->string('browser')->nullable(); $table->string('operating_system')->nullable(); $table->string('device')->nullable(); $table->text('diagnosis')->nullable(); $table->text('root_cause')->nullable(); $table->text('technical_solution')->nullable(); $table->text('post_fix_tests')->nullable(); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('support_resolution_cycles', function (Blueprint $table): void {
            $table->id(); $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete(); $table->unsignedInteger('sequence'); $table->dateTime('started_at'); $table->dateTime('ended_at')->nullable(); $table->timestamps(); $table->unique(['ticket_id','sequence']);
        });
        Schema::create('support_status_periods', function (Blueprint $table): void {
            $table->id(); $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete(); $table->foreignId('cycle_id')->constrained('support_resolution_cycles')->cascadeOnDelete(); $table->foreignId('status_id')->constrained('support_statuses'); $table->string('period_type', 100); $table->boolean('pauses_clock')->default(false); $table->dateTime('started_at'); $table->dateTime('ended_at')->nullable(); $table->timestamps(); $table->index(['ticket_id','ended_at']);
        });
        Schema::create('support_observations', function (Blueprint $table): void {
            $table->id(); $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete(); $table->string('title'); $table->text('description')->nullable(); $table->string('state', 30)->default('pending'); $table->string('priority', 30)->nullable(); $table->foreignId('responsible_id')->nullable()->constrained('users'); $table->dateTime('raised_at')->nullable(); $table->dateTime('due_at')->nullable(); $table->dateTime('lifted_at')->nullable(); $table->dateTime('validated_at')->nullable(); $table->text('solution')->nullable(); $table->text('evidence')->nullable(); $table->text('result')->nullable(); $table->text('reason')->nullable(); $table->foreignId('created_by')->constrained('users'); $table->foreignId('lifted_by')->nullable()->constrained('users'); $table->foreignId('validated_by')->nullable()->constrained('users'); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('support_observation_histories', function (Blueprint $table): void {
            $table->id(); $table->foreignId('observation_id')->constrained('support_observations')->cascadeOnDelete(); $table->string('from_state',30)->nullable(); $table->string('to_state',30); $table->text('reason')->nullable(); $table->foreignId('actor_id')->constrained('users'); $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('support_observation_histories'); Schema::dropIfExists('support_observations'); Schema::dropIfExists('support_status_periods'); Schema::dropIfExists('support_resolution_cycles'); Schema::dropIfExists('support_incident_details'); Schema::dropIfExists('support_reschedules'); Schema::dropIfExists('support_session_details'); Schema::table('support_tickets', function(Blueprint $table): void {$table->dropColumn(['solution_summary','close_reason','work_started_at','resolved_at','validated_at','closed_at']);}); }
};
