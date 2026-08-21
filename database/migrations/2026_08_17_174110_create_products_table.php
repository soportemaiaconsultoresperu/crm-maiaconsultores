<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Products with a default tax affectation (ADR-005).
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->enum('product_type', ['producto', 'servicio']);
            $table->string('name', 150);
            $table->foreignId('category_id')->nullable()->constrained('product_categories');
            $table->text('description')->nullable();
            $table->decimal('price', 14, 2);
            $table->char('currency_code', 3);
            $table->foreignId('tax_id')->constrained('taxes');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->foreign('currency_code')->references('code')->on('currencies');
            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign('updated_by')->references('id')->on('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index('category_id');
            $table->index('tax_id');
            $table->index('currency_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
