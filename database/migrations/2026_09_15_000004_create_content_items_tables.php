<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive content-item schema for the content scheduling module.
 *
 * One `content_items` row holds the editorial fields of a planned content or a
 * bare idea; the multi-select objectives, formats and channels live in three
 * additive pivot tables. Following the module convention, an item owns the
 * conventional row-audit columns (`created_by`/`updated_by`), timestamps and
 * soft deletes.
 *
 * Time handling: `planned_at` stores a UTC instant. The application is fixed
 * to UTC while editors work in America/Lima, so the conversion lives in
 * App\Support\Content\PeruTime and never in the database session timezone.
 *
 * `kind` distinguishes a planned content from an idea; `status` is a VARCHAR
 * whose vocabulary is defined in App\Enums\Content\ContentItemStatus. Pillar
 * and topic are nullable on purpose (an idea may have neither) and their
 * project/pillar consistency is enforced in the service layer.
 *
 * Additive only: four new tables, no existing table is altered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('content_project_id');
            $table->foreign('content_project_id')->references('id')->on('content_projects');
            $table->string('kind', 20)->default('content');
            $table->timestamp('planned_at')->nullable();
            $table->unsignedBigInteger('content_pillar_id')->nullable();
            $table->unsignedBigInteger('content_topic_id')->nullable();
            $table->foreign('content_pillar_id')->references('id')->on('content_pillars');
            $table->foreign('content_topic_id')->references('id')->on('content_topics');
            $table->string('status', 40)->default('incomplete');
            $table->string('title', 200);
            $table->text('body');
            $table->text('hashtags')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign('updated_by')->references('id')->on('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['content_project_id', 'kind', 'status']);
            $table->index(['content_project_id', 'planned_at']);
            $table->index('content_pillar_id');
            $table->index('content_topic_id');
        });

        // The third element is an explicit unique name, needed only where the
        // automatic name exceeds MySQL's 64-character identifier limit (the
        // objective pivot's automatic name is 66 characters). The other pivots
        // keep Laravel's default naming.
        $pivots = [
            'content_item_objective' => ['content_objective_id', 'content_objectives', 'uq_content_item_objective_item_objective'],
            'content_item_format' => ['content_format_id', 'content_formats', null],
            'content_item_channel' => ['content_channel_id', 'content_channels', null],
        ];

        foreach ($pivots as $name => [$relatedKey, $relatedTable, $uniqueName]) {
            Schema::create($name, function (Blueprint $table) use ($relatedKey, $relatedTable, $uniqueName) {
                $table->id();
                $table->unsignedBigInteger('content_item_id');
                $table->unsignedBigInteger($relatedKey);
                $table->foreign('content_item_id')->references('id')->on('content_items')->cascadeOnDelete();
                $table->foreign($relatedKey)->references('id')->on($relatedTable)->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['content_item_id', $relatedKey], $uniqueName);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('content_item_channel');
        Schema::dropIfExists('content_item_format');
        Schema::dropIfExists('content_item_objective');
        Schema::dropIfExists('content_items');
    }
};
