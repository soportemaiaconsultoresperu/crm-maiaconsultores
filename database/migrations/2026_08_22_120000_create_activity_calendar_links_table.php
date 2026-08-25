<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_calendar_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('activity_id')->constrained('activities')->cascadeOnDelete();
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

            $table->unique(['activity_id', 'integration_account_id', 'external_calendar_id'], 'activity_calendar_links_unique_target');
            $table->index(['integration_account_id', 'sync_status'], 'activity_calendar_links_account_status_index');
            $table->index('external_event_id', 'activity_calendar_links_external_event_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_calendar_links');
    }
};
