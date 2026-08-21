<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_runs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 200);
            $table->foreignId('template_id')->constrained('campaign_templates');
            $table->string('template_hash', 64)->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at_estimated')->nullable();
            $table->foreignId('owner_id')->constrained('users');
            $table->foreignId('team_id')->nullable()->constrained('teams');
            $table->enum('status', ['draft', 'scheduled', 'running', 'paused', 'completed', 'cancelled'])->default('draft');
            $table->dateTime('status_changed_at')->nullable();
            $table->foreignId('status_changed_by')->nullable()->constrained('users');
            $table->text('status_reason')->nullable();
            $table->json('progress_cache')->nullable();
            $table->text('observations')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign('updated_by')->references('id')->on('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'starts_at']);
            $table->index('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_runs');
    }
};
