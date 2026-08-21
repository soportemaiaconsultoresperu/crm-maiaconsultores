<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B13 — email_attachments.
 *
 * One row per file attached to an outbound or inbound email. The actual
 * file lives on the private disk at `storage_path`; `sha256` is the
 * integrity hash used for deduplication and tamper detection. When the
 * file is also a tracked `Document` we keep `document_id` to link back
 * to the V1 document store (RF-DOC-001..005).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_attachments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('message_id');
            $table->unsignedBigInteger('document_id')->nullable();
            $table->string('filename', 191);
            $table->string('mime', 191);
            $table->unsignedInteger('size');
            $table->string('storage_path', 191);
            $table->char('sha256', 64)->nullable();
            $table->timestamps();

            $table->index('message_id', 'idx_email_attachments_message');
            $table->index('document_id', 'idx_email_attachments_document');
        });

        if (Schema::hasTable('email_messages')) {
            Schema::table('email_attachments', function (Blueprint $table): void {
                $table->foreign('message_id', 'fk_email_attachments_message')
                    ->references('id')->on('email_messages')
                    ->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('documents')) {
            Schema::table('email_attachments', function (Blueprint $table): void {
                $table->foreign('document_id', 'fk_email_attachments_document')
                    ->references('id')->on('documents')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('email_attachments')) {
            Schema::table('email_attachments', function (Blueprint $table): void {
                $table->dropForeign('fk_email_attachments_message');
                $table->dropForeign('fk_email_attachments_document');
            });
        }

        Schema::dropIfExists('email_attachments');
    }
};
