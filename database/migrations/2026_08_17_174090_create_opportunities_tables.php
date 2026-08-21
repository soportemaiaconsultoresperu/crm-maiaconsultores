<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Opportunities and their append-only stage history. No "next action"
     * columns: next actions live only in activities (ADR-012).
     */
    public function up(): void
    {
        Schema::create('opportunities', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('title', 200);
            $table->foreignId('lead_id')->nullable()->constrained('leads');
            $table->foreignId('customer_id')->nullable()->constrained('customers');
            $table->foreignId('contact_id')->nullable()->constrained('contacts');
            $table->foreignId('owner_id')->constrained('users');
            $table->foreignId('stage_id')->constrained('pipeline_stages');
            $table->decimal('estimated_amount', 14, 2);
            $table->char('currency_code', 3);
            $table->decimal('probability', 5, 2)->default(0);
            $table->date('expected_close_at')->nullable();
            $table->foreignId('source_id')->nullable()->constrained('lead_sources');
            $table->enum('priority', ['baja', 'media', 'alta'])->default('media');
            $table->text('description')->nullable();
            $table->foreignId('loss_reason_id')->nullable()->constrained('loss_reasons');
            $table->dateTime('closed_at')->nullable();
            $table->decimal('final_amount', 14, 2)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->foreign('currency_code')->references('code')->on('currencies');
            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign('updated_by')->references('id')->on('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index('owner_id');
            $table->index('stage_id');
            $table->index('lead_id');
            $table->index('customer_id');
            $table->index('contact_id');
            $table->index('expected_close_at');
            $table->index('closed_at');
        });

        Schema::create('opportunity_stage_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('opportunities');
            $table->foreignId('from_stage_id')->nullable()->constrained('pipeline_stages');
            $table->foreignId('to_stage_id')->constrained('pipeline_stages');
            $table->foreignId('user_id')->constrained('users');
            $table->dateTime('changed_at');
            $table->string('note', 255)->nullable();

            $table->index(['opportunity_id', 'changed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('opportunity_stage_histories');
        Schema::dropIfExists('opportunities');
    }
};
