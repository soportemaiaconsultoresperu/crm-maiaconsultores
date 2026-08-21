<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Leads. No "next action" columns: next actions live only in
     * activities (ADR-012).
     */
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->enum('person_type', ['natural', 'juridica']);
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('company_name', 150)->nullable();
            $table->string('position', 100)->nullable();
            $table->enum('doc_type', ['dni', 'ruc', 'ce', 'pasaporte', 'otro'])->nullable();
            $table->string('doc_number', 20)->nullable();
            $table->string('doc_number_norm', 20)->nullable()->index();
            $table->string('phone', 30)->nullable();
            $table->string('phone_norm', 20)->nullable()->index();
            $table->string('whatsapp', 30)->nullable();
            $table->string('whatsapp_norm', 20)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('email_norm', 150)->nullable()->index();
            $table->string('address', 255)->nullable();
            $table->char('ubigeo_code', 6)->nullable();
            $table->foreignId('source_id')->constrained('lead_sources');
            $table->foreignId('status_id')->constrained('lead_statuses');
            $table->enum('interest_level', ['bajo', 'medio', 'alto'])->nullable();
            $table->foreignId('owner_id')->constrained('users');
            $table->dateTime('entered_at');
            $table->text('observations')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->foreign('ubigeo_code')->references('code')->on('ubigeo');
            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign('updated_by')->references('id')->on('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index('owner_id');
            $table->index('status_id');
            $table->index('source_id');
            $table->index('entered_at');
            $table->index('ubigeo_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
