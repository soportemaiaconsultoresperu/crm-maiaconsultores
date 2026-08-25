<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_calendar_channels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('integration_account_id')->constrained('integration_accounts')->cascadeOnDelete();
            $table->string('provider', 40)->default('google');
            $table->string('external_calendar_id', 255);
            $table->string('channel_id', 255)->unique();
            $table->string('resource_id', 255)->nullable();
            $table->text('resource_uri')->nullable();
            $table->string('channel_token_hash', 64)->nullable();
            $table->enum('status', [
                'pending',
                'active',
                'renewing',
                'expired',
                'stopped',
                'failed',
            ])->default('pending');
            $table->dateTime('expires_at')->nullable();
            $table->string('last_message_number', 40)->nullable();
            $table->dateTime('last_received_at')->nullable();
            $table->dateTime('last_renewed_at')->nullable();
            $table->string('error_class', 255)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['integration_account_id', 'external_calendar_id', 'status'], 'google_calendar_channels_account_calendar_status_index');
            $table->index('resource_id', 'google_calendar_channels_resource_id_index');
            $table->index('expires_at', 'google_calendar_channels_expires_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_calendar_channels');
    }
};
