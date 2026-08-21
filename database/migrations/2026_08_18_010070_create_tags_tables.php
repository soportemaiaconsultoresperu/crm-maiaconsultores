<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B12 — tags + taggables.
 *
 * Tags are global; taggables is the polymorphic pivot so Lead, Contact,
 * Customer, Opportunity and Quotation all gain a `tags()` relation via the
 * Automatable concern (no schema change on those tables).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->string('slug', 80)->unique();
            $table->string('color', 16)->nullable();
            $table->timestamps();

            $table->index('slug', 'idx_tags_slug');
        });

        Schema::create('taggables', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tag_id');
            $table->string('taggable_type', 80);
            $table->unsignedBigInteger('taggable_id');

            $table->unique(['tag_id', 'taggable_type', 'taggable_id'], 'uq_taggables_tag_subject');
            $table->index(['taggable_type', 'taggable_id'], 'idx_taggables_subject');
        });

        if (Schema::hasTable('tags')) {
            Schema::table('taggables', function (Blueprint $table): void {
                $table->foreign('tag_id', 'fk_taggables_tag')
                    ->references('id')->on('tags')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('taggables')) {
            Schema::table('taggables', function (Blueprint $table): void {
                $table->dropForeign('fk_taggables_tag');
            });
        }

        Schema::dropIfExists('taggables');
        Schema::dropIfExists('tags');
    }
};