<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200)->unique();
            $table->text('description')->nullable();
            $table->enum('objective', ['reactivation', 'nurturing', 'cross_sell', 'onboarding', 'custom'])->default('custom');
            $table->enum('status', ['draft', 'active', 'inactive'])->default('draft');
            $table->foreignId('owner_id')->constrained('users');
            $table->foreignId('team_id')->nullable()->constrained('teams');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign('updated_by')->references('id')->on('users');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_templates');
    }
};
