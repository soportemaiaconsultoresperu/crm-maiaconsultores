<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive content-attachment schema for the content scheduling module (T7).
 *
 * One `content_attachments` row is either an uploaded media file or an
 * external media link attached to a content item:
 *
 *   - `kind`         image | video | link (the media vocabulary lives in
 *                    App\Models\Content\ContentAttachment, never in a DB enum).
 *   - `path`         private-disk path for an upload; null for a link.
 *   - `url`          external URL for a link; null for an upload.
 *   - `original_name` the name the operator uploaded, or the link's basename.
 *   - `mime_type`    the MIME type detected from the real file content for an
 *                    upload, or the one implied by the link's extension.
 *   - `size_bytes`   the real upload size; null for a link.
 *   - `sort`         manual ordering inside the content.
 *
 * The row also owns the module's conventional audit columns
 * (`created_by`/`updated_by`), timestamps and soft deletes: retiring an
 * attachment only marks the row, so the physical file is retained and the
 * attachment can come back with its content. Nothing in this work unit deletes
 * a file from disk.
 *
 * Additive only: one new table, no existing table is altered. The foreign key
 * deliberately does not cascade, so a hard delete of a content cannot silently
 * orphan (or destroy) stored media.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('content_item_id');
            $table->foreign('content_item_id')->references('id')->on('content_items');
            $table->string('kind', 20);
            $table->string('path', 255)->nullable();
            $table->text('url')->nullable();
            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign('updated_by')->references('id')->on('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['content_item_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_attachments');
    }
};
