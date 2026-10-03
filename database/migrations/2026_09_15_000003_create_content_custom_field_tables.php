<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive custom-field schema for the content scheduling module.
 *
 * A content project configures its own typed fields and sections at runtime,
 * so no physical column is ever added per field: definitions live as rows in
 * `content_field_definitions`, their list options in `content_field_options`,
 * and their grouping in `content_field_sections`.
 *
 * Like the project catalogs, fields are deactivated (`is_active`) instead of
 * soft-deleted so historical values keep resolving their definition. Uniqueness
 * ignores `is_active` on purpose: a retired key or slug stays reserved.
 *
 * Additive only: three new tables, no existing table is altered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_field_sections', function (Blueprint $table) {
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

        Schema::create('content_field_definitions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('content_project_id');
            $table->unsignedBigInteger('content_field_section_id')->nullable();
            $table->foreign('content_project_id')->references('id')->on('content_projects');
            $table->foreign('content_field_section_id')->references('id')->on('content_field_sections');
            $table->string('key', 100);
            $table->string('label', 150);
            $table->string('type', 50);
            $table->text('help_text')->nullable();
            $table->boolean('required')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign('updated_by')->references('id')->on('users');
            $table->timestamps();

            $table->unique(['content_project_id', 'key']);
            $table->index(['content_project_id', 'is_active', 'sort']);
        });

        Schema::create('content_field_options', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('content_field_definition_id');
            $table->foreign('content_field_definition_id')->references('id')->on('content_field_definitions');
            $table->string('label', 150);
            $table->string('slug', 150);
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign('updated_by')->references('id')->on('users');
            $table->timestamps();

            $table->unique(['content_field_definition_id', 'slug']);
            $table->index(['content_field_definition_id', 'is_active', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_field_options');
        Schema::dropIfExists('content_field_definitions');
        Schema::dropIfExists('content_field_sections');
    }
};
