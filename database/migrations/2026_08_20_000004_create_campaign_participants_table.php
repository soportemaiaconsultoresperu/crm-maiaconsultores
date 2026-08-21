<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('campaign_runs')->cascadeOnDelete();
            $table->string('subject_type', 50); // 'lead' | 'customer' | 'contact' | 'opportunity'
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('assigned_to')->nullable()->constrained('users');
            $table->enum('status', ['active', 'excluded', 'cancelled'])->default('active');
            $table->dateTime('included_at')->nullable();
            $table->dateTime('excluded_at')->nullable();
            $table->text('exclusion_reason')->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users');
            $table->foreignId('removed_by')->nullable()->constrained('users');
            $table->string('display_name', 200);
            $table->string('company_name', 200)->nullable();
            $table->string('document_number_masked', 50)->nullable();
            $table->string('email', 200)->nullable();
            $table->string('phone', 50)->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'subject_type', 'subject_id']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('assigned_to');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_participants');
    }
};
