<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * System parameters (key/value with type hint).
     *
     * Note: "key" is the primary key (VARCHAR(100), functionally UNIQUE per
     * docs/BASE_DATOS.md §3.1). The Setting model uses it as a
     * non-incrementing string primary key.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->text('value')->nullable();
            $table->enum('type', ['string', 'integer', 'decimal', 'boolean', 'json']);
            $table->string('group', 50)->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
