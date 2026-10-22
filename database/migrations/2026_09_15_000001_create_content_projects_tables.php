<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Content scheduling module — content project foundation.
 *
 * Additive only: `content_projects` and its membership pivot are new tables.
 * No existing table is altered. `content_projects` carries the repository's
 * standard row-audit columns (`created_by`/`updated_by` FK to `users`),
 * timestamps and soft deletes, plus the module-specific `archived_at` marker:
 * archiving retires a project without deleting it, so it stays distinct from
 * the soft-delete trash lifecycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_projects', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 150)->unique();
            $table->text('description')->nullable();
            $table->string('color', 16)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign('updated_by')->references('id')->on('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
        });

        Schema::create('content_project_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('content_project_id');
            $table->unsignedBigInteger('user_id');
            $table->foreign('content_project_id')->references('id')->on('content_projects')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['content_project_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_project_user');
        Schema::dropIfExists('content_projects');
    }
};
