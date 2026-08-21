<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Correlative sequences per entity and calendar year (ADR-002).
     */
    public function up(): void
    {
        Schema::create('code_sequences', function (Blueprint $table) {
            $table->id();
            $table->enum('entity', ['lead', 'customer', 'opportunity', 'quotation']);
            $table->smallInteger('year');
            $table->string('prefix', 10);
            $table->unsignedInteger('next_number')->default(1);
            $table->tinyInteger('pad_length')->default(5);
            $table->timestamps();

            $table->unique(['entity', 'year']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('code_sequences');
    }
};
