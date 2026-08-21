<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quotations and lines. Line items keep a historical copy of price and
     * tax data (ADR-005); item deletion cascades with the header.
     */
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('lead_id')->nullable()->constrained('leads');
            $table->foreignId('customer_id')->nullable()->constrained('customers');
            $table->foreignId('contact_id')->nullable()->constrained('contacts');
            $table->foreignId('opportunity_id')->nullable()->constrained('opportunities');
            $table->foreignId('owner_id')->constrained('users');
            $table->date('issued_at');
            $table->date('expires_at')->nullable();
            $table->char('currency_code', 3);
            $table->text('terms')->nullable();
            $table->text('observations')->nullable();
            $table->enum('status', ['draft', 'sent', 'accepted', 'rejected', 'expired', 'voided'])->default('draft');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->dateTime('accepted_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->foreign('currency_code')->references('code')->on('currencies');
            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign('updated_by')->references('id')->on('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index('lead_id');
            $table->index('customer_id');
            $table->index('contact_id');
            $table->index('opportunity_id');
            $table->index('owner_id');
            $table->index('status');
            $table->index('expires_at');
            $table->index('currency_code');
        });

        Schema::create('quotation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained('quotations')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products');
            $table->string('description', 255);
            $table->decimal('quantity', 12, 2);
            $table->string('unit', 30)->nullable();
            $table->decimal('unit_price', 14, 2);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->foreignId('tax_id')->nullable()->constrained('taxes');
            $table->string('tax_name', 50);
            $table->decimal('tax_rate', 5, 2);
            $table->decimal('line_subtotal', 14, 2);
            $table->decimal('line_tax', 14, 2);
            $table->decimal('line_total', 14, 2);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign('updated_by')->references('id')->on('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index('quotation_id');
            $table->index('product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quotation_items');
        Schema::dropIfExists('quotations');
    }
};
