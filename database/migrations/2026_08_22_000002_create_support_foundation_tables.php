<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_ticket_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['is_active', 'sort']);
        });

        Schema::create('support_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['is_active', 'sort']);
        });

        Schema::create('support_channels', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['is_active', 'sort']);
        });

        Schema::create('support_priorities', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->string('color', 30)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['is_active', 'sort']);
        });

        Schema::create('support_statuses', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_terminal')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['is_active', 'sort']);
            $table->index('is_terminal');
        });

        Schema::create('support_tickets', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('title', 200);
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('requester_contact_id')->constrained('contacts');
            $table->foreignId('type_id')->constrained('support_ticket_types');
            $table->foreignId('category_id')->constrained('support_categories');
            $table->foreignId('channel_id')->constrained('support_channels');
            $table->foreignId('priority_id')->constrained('support_priorities');
            $table->foreignId('status_id')->constrained('support_statuses');
            $table->foreignId('responsible_id')->nullable()->constrained('users');
            $table->foreignId('team_id')->nullable()->constrained('teams');
            $table->text('description');
            $table->string('impact', 100)->nullable();
            $table->text('general_observations')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->dateTime('assigned_at')->nullable();
            $table->dateTime('first_responded_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();
            $table->index('customer_id');
            $table->index('requester_contact_id');
            $table->index('responsible_id');
            $table->index('team_id');
            $table->index('status_id');
            $table->index('priority_id');
            $table->index('created_at');
            $table->index('assigned_at');
            $table->index('first_responded_at');
        });

        Schema::create('support_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('previous_responsible_id')->nullable()->constrained('users');
            $table->foreignId('new_responsible_id')->constrained('users');
            $table->foreignId('previous_team_id')->nullable()->constrained('teams');
            $table->foreignId('new_team_id')->nullable()->constrained('teams');
            $table->text('reason')->nullable();
            $table->foreignId('assigned_by')->constrained('users');
            $table->timestamp('assigned_at');
            $table->timestamps();
            $table->index('ticket_id');
            $table->index('new_responsible_id');
            $table->index('new_team_id');
            $table->index('assigned_at');
        });

        Schema::create('support_ticket_updates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->string('type', 50)->default('case_update');
            $table->text('body');
            $table->boolean('is_internal')->default(false);
            $table->boolean('is_customer_response')->default(false);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index('ticket_id');
            $table->index('type');
            $table->index('is_internal');
            $table->index('is_customer_response');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_updates');
        Schema::dropIfExists('support_assignments');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('support_statuses');
        Schema::dropIfExists('support_priorities');
        Schema::dropIfExists('support_channels');
        Schema::dropIfExists('support_categories');
        Schema::dropIfExists('support_ticket_types');
    }
};
