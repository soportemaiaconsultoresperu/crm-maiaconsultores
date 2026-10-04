<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive typed-value storage for the content scheduling module (T6b).
 *
 * A content project defines its own typed fields at runtime (T5a), so values
 * cannot be physical columns: one `content_field_values` row references one
 * content item and one field definition, with the unique pair
 * (content item, definition) making the value singular. The row holds one
 * storage column per shape the field type needs — text, a decimal number, a
 * date and an optional reference to a single selected option. A
 * multiple-selection field stores its chosen options in the additive pivot
 * `content_field_value_option`, whose unique pair stops duplicate choices.
 *
 * Values follow their content item and are never independently soft-deleted,
 * so there is no `deleted_at` here: the rows disappear with the content. The
 * definition's stable `key` stays the identity, so relabelling or reordering a
 * field never disturbs the stored value referenced by its row.
 *
 * Additive only: two new tables, no existing table is altered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_field_values', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('content_item_id');
            $table->unsignedBigInteger('content_field_definition_id');
            $table->foreign('content_item_id')->references('id')->on('content_items')->cascadeOnDelete();
            $table->foreign('content_field_definition_id')->references('id')->on('content_field_definitions');
            $table->text('value_text')->nullable();
            $table->decimal('value_number', 14, 2)->nullable();
            $table->date('value_date')->nullable();
            $table->unsignedBigInteger('content_field_option_id')->nullable();
            $table->foreign('content_field_option_id')->references('id')->on('content_field_options');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign('updated_by')->references('id')->on('users');
            $table->timestamps();

            // Explicit name: the automatic name is 71 characters, over MySQL's
            // 64-character identifier limit.
            $table->unique(['content_item_id', 'content_field_definition_id'], 'uq_content_field_values_item_definition');
            $table->index('content_field_definition_id');
        });

        Schema::create('content_field_value_option', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('content_field_value_id');
            $table->unsignedBigInteger('content_field_option_id');
            $table->foreign('content_field_value_id')->references('id')->on('content_field_values')->cascadeOnDelete();
            $table->foreign('content_field_option_id')->references('id')->on('content_field_options')->cascadeOnDelete();
            $table->timestamps();

            // Explicit name: the automatic name is 80 characters, over MySQL's
            // 64-character identifier limit.
            $table->unique(['content_field_value_id', 'content_field_option_id'], 'uq_content_field_value_option_value_option');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_field_value_option');
        Schema::dropIfExists('content_field_values');
    }
};
