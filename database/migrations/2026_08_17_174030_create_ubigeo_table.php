<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Official INEI ubigeo catalog, self-referential by level (ADR-009).
     */
    public function up(): void
    {
        Schema::create('ubigeo', function (Blueprint $table) {
            $table->char('code', 6)->primary();
            $table->string('name', 100);
            $table->enum('level', ['departamento', 'provincia', 'distrito']);
            $table->char('parent_code', 6)->nullable();
            $table->foreign('parent_code')->references('code')->on('ubigeo');
            $table->timestamps();

            $table->index('parent_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ubigeo');
    }
};
