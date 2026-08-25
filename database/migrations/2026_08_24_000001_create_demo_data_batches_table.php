<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_data_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('scenario_name');
            $table->string('status', 30)->index();
            $table->json('modules')->nullable();
            $table->json('record_counts')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('reset_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('created_by');
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_data_batches');
    }
};
