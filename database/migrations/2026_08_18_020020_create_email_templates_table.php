<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B13 — email_templates.
 *
 * Editable email templates with a basic versioning scheme. The active row
 * (is_active) is what the senders read; `email_template_versions` keeps a
 * point-in-time snapshot per version bump.
 *
 * Per docs/v2/01-roadmap.md §2.3. Variables are stored in `variables_json`
 * (denormalized schema of the allowed variable list) — template content is
 * interpolated against this allow-list by the rendering service (B13 Pasada C).
 * No code execution: per the C-02 architectural rule, blade/PHP/eval are
 * forbidden in templates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 191);
            $table->string('slug', 80)->unique();
            $table->string('subject', 191);
            $table->longText('body_html');
            $table->longText('body_text');
            $table->json('variables_json')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);

            $table->unsignedBigInteger('owner_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active', 'idx_email_templates_active');
            $table->index('owner_id', 'idx_email_templates_owner');
        });

        if (Schema::hasTable('users')) {
            Schema::table('email_templates', function (Blueprint $table): void {
                $table->foreign('owner_id', 'fk_email_templates_owner')
                    ->references('id')->on('users')
                    ->nullOnDelete();
                $table->foreign('created_by', 'fk_email_templates_created_by')
                    ->references('id')->on('users')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('email_templates')) {
            Schema::table('email_templates', function (Blueprint $table): void {
                $table->dropForeign('fk_email_templates_owner');
                $table->dropForeign('fk_email_templates_created_by');
            });
        }

        Schema::dropIfExists('email_templates');
    }
};
