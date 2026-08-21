<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_item_reschedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('campaign_action_items')->cascadeOnDelete();
            $table->dateTime('old_scheduled_at');
            $table->dateTime('new_scheduled_at');
            $table->text('reason');
            $table->foreignId('rescheduled_by')->constrained('users');
            $table->dateTime('rescheduled_at');
            $table->enum('scope', ['individual', 'global'])->default('individual');
            $table->boolean('preserved_individual')->default(false);
            $table->timestamps();

            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_item_reschedules');
    }
};
