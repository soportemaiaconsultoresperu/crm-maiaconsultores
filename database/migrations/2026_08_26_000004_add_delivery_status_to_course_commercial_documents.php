<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_commercial_documents', function (Blueprint $table): void {
            $table->string('delivery_status', 30)->default('pending')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('course_commercial_documents', function (Blueprint $table): void {
            $table->dropColumn('delivery_status');
        });
    }
};
