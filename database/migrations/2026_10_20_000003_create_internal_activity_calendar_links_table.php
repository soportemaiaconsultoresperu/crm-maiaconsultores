<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google Calendar events created for internal activities, one row per user
 * calendar (odd/tasks/internal-activities.md, T4: S7). Mirrors
 * activity_calendar_links.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_activity_calendar_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('internal_activity_id')->constrained('internal_activities')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('integration_account_id')->constrained('integration_accounts')->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('external_calendar_id', 255);
            $table->string('external_event_id', 255)->nullable();
            $table->string('sync_hash', 64)->nullable();
            $table->enum('sync_status', [
                'pending',
                'syncing',
                'synced',
                'temporary_error',
                'failed',
                'cancelled',
                'not_syncable',
                'external_event_missing',
            ])->default('pending');
            $table->dateTime('last_synced_at')->nullable();
            $table->dateTime('last_attempt_at')->nullable();
            $table->string('error_class', 255)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['internal_activity_id', 'integration_account_id', 'external_calendar_id'], 'internal_activity_calendar_links_unique_target');
            $table->index(['integration_account_id', 'sync_status'], 'internal_activity_calendar_links_account_status_index');
            $table->index('external_event_id', 'internal_activity_calendar_links_external_event_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_activity_calendar_links');
    }
};
