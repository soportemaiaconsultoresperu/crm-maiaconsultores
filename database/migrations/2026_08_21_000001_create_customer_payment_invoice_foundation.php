<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', fn (Blueprint $table) => $table->string('payment_modality', 100)->nullable()->after('observations'));

        Schema::create('invoice_statuses', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign('updated_by')->references('id')->on('users');
        });

        Schema::create('customer_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('invoice_number', 60);
            $table->date('due_date');
            $table->decimal('total_amount', 14, 2);
            $table->foreignId('status_id')->constrained('invoice_statuses')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('retired_at')->nullable()->index();
            $table->foreignId('retired_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('retire_reason', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign('updated_by')->references('id')->on('users');
            $table->unique(['customer_id', 'invoice_number']);
            $table->index(['customer_id', 'due_date']);
            $table->index('status_id');
            $table->index(['due_date', 'status_id', 'retired_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_invoices');
        Schema::dropIfExists('invoice_statuses');
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('payment_modality'));
    }
};
