<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_data_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('demo_data_batches')->cascadeOnDelete();
            $table->string('module', 60);
            $table->string('table_name', 120);
            $table->string('model_type');
            $table->unsignedBigInteger('record_id');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('batch_id');
            $table->index('module');
            $table->index(['model_type', 'record_id']);
            $table->index(['table_name', 'record_id']);
            $table->unique(['model_type', 'record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_data_records');
    }
};
