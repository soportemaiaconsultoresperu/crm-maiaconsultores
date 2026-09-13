<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_academic_documents', function (Blueprint $table): void {
            $table->index('qr_token_hash', 'course_academic_documents_qr_token_hash_index');
        });
    }

    public function down(): void
    {
        Schema::table('course_academic_documents', function (Blueprint $table): void {
            $table->dropIndex('course_academic_documents_qr_token_hash_index');
        });
    }
};
