<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B13 — email_template_versions.
 *
 * Append-only snapshot of an email template at the moment a new version
 * was published. Captures subject/body/variables and the user that took
 * the snapshot so audit can answer "who shipped what, when".
 *
 * Cascade-on-delete matches the parent template lifecycle: when a template
 * is removed (soft- or hard-deleted) its version history goes with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_template_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('template_id');
            $table->unsignedInteger('version');
            $table->string('subject', 191);
            $table->longText('body_html');
            $table->longText('body_text');
            $table->json('variables_json')->nullable();
            $table->unsignedBigInteger('snapshot_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['template_id', 'version'], 'idx_email_template_versions_template_version');
        });

        if (Schema::hasTable('email_templates')) {
            Schema::table('email_template_versions', function (Blueprint $table): void {
                $table->foreign('template_id', 'fk_email_template_versions_template')
                    ->references('id')->on('email_templates')
                    ->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('email_template_versions', function (Blueprint $table): void {
                $table->foreign('snapshot_by', 'fk_email_template_versions_snapshot_by')
                    ->references('id')->on('users')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('email_template_versions')) {
            Schema::table('email_template_versions', function (Blueprint $table): void {
                $table->dropForeign('fk_email_template_versions_template');
                $table->dropForeign('fk_email_template_versions_snapshot_by');
            });
        }

        Schema::dropIfExists('email_template_versions');
    }
};
