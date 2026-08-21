<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Activities. The single source of truth for next actions (ADR-012).
     * The (status, scheduled_at) index feeds the "next pending future
     * activity per subject" query.
     */
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('type_id')->constrained('activity_types');
            $table->string('subject_type', 50);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('owner_id')->constrained('users');
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->dateTime('scheduled_at');
            $table->dateTime('executed_at')->nullable();
            $table->string('result', 255)->nullable();
            $table->enum('status', ['pending', 'in_process', 'completed', 'cancelled', 'overdue'])->default('pending');
            $table->enum('priority', ['baja', 'media', 'alta'])->default('media');
            $table->dateTime('reminder_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign('updated_by')->references('id')->on('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['subject_type', 'subject_id']);
            $table->index('owner_id');
            $table->index('status');
            $table->index('scheduled_at');
            $table->index(['status', 'scheduled_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
