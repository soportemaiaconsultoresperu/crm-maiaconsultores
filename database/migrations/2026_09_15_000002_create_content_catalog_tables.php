<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive project catalogs. Catalogs use is_active, not soft deletes:
 * deactivation keeps historical references readable (docs/BASE_DATOS.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['content_pillars', 'content_objectives', 'content_formats', 'content_channels'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('content_project_id');
                $table->foreign('content_project_id')->references('id')->on('content_projects');
                $table->string('name', 150);
                $table->string('slug', 150);
                $table->unsignedInteger('sort')->default(0);
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->foreign('created_by')->references('id')->on('users');
                $table->foreign('updated_by')->references('id')->on('users');
                $table->timestamps();

                $table->unique(['content_project_id', 'slug']);
                $table->index(['content_project_id', 'is_active', 'sort']);
            });
        }

        Schema::create('content_topics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('content_pillar_id');
            $table->foreign('content_pillar_id')->references('id')->on('content_pillars');
            $table->string('name', 150);
            $table->string('slug', 150);
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign('updated_by')->references('id')->on('users');
            $table->timestamps();

            $table->unique(['content_pillar_id', 'slug']);
            $table->index(['content_pillar_id', 'is_active', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_topics');
        Schema::dropIfExists('content_channels');
        Schema::dropIfExists('content_formats');
        Schema::dropIfExists('content_objectives');
        Schema::dropIfExists('content_pillars');
    }
};
