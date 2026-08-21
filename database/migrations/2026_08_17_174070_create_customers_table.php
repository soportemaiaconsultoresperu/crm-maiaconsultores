<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customers. Conversion from a lead creates a new customer record and
     * keeps the original lead (ADR-001).
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->enum('person_type', ['natural', 'juridica']);
            $table->string('legal_name', 180);
            $table->string('trade_name', 180)->nullable();
            $table->enum('doc_type', ['dni', 'ruc', 'ce', 'pasaporte', 'otro'])->nullable();
            $table->string('doc_number', 20)->nullable();
            $table->string('doc_number_norm', 20)->nullable()->index();
            $table->string('phone', 30)->nullable();
            $table->string('phone_norm', 20)->nullable();
            $table->string('whatsapp', 30)->nullable();
            $table->string('whatsapp_norm', 20)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('email_norm', 150)->nullable()->index();
            $table->string('website', 150)->nullable();
            $table->string('fiscal_address', 255)->nullable();
            $table->char('ubigeo_code', 6)->nullable();
            $table->string('sector', 100)->nullable();
            $table->foreignId('owner_id')->constrained('users');
            $table->enum('status', ['activo', 'inactivo'])->default('activo');
            $table->foreignId('converted_from_lead_id')->nullable()->constrained('leads');
            $table->dateTime('converted_at')->nullable();
            $table->text('observations')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->foreign('ubigeo_code')->references('code')->on('ubigeo');
            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign('updated_by')->references('id')->on('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index('owner_id');
            $table->index('converted_from_lead_id');
            $table->index('ubigeo_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
