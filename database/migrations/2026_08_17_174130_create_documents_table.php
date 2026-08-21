<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Documents: metadata only; files live on a private disk (ADR-011).
     * Soft delete marks the record; the physical file is retained.
     */
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('docable_type', 50);
            $table->unsignedBigInteger('docable_id');
            $table->string('name', 150);
            $table->string('disk', 50);
            $table->string('path', 255);
            $table->string('mime_type', 100);
            $table->string('extension', 10);
            $table->unsignedInteger('size_bytes');
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['docable_type', 'docable_id']);
            $table->index('uploaded_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
