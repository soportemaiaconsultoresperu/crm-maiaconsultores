<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive important-dates calendar schema for the content scheduling module
 * (T9).
 *
 * One `content_calendar_annotations` row is a free note an editor writes on a
 * calendar day of a content project: a festivity, an anniversary or an
 * activity. A project owns its own calendar, so every row is scoped to a
 * `content_project_id`.
 *
 * Time handling: `annotated_at` stores a UTC instant for the Peruvian calendar
 * day the editor picked, exactly as `content_items.planned_at` does. The
 * application is fixed to UTC while the editorial team works in
 * America/Lima, so the conversion lives in App\Support\Content\PeruTime and
 * never in the database session timezone. Grouping an annotation under a day
 * therefore always reads its Peruvian date, never the raw UTC date.
 *
 * Several annotations may share one day on purpose, so `annotated_at` is
 * deliberately NOT unique: the specification allows more than one festivity,
 * anniversary or activity per date.
 *
 * The row owns the module's conventional audit columns (`created_by`/
 * `updated_by`), timestamps and soft deletes: retiring an annotation only marks
 * the row, so it can be restored and never destroys a project's calendar copy.
 *
 * Additive only: one new table, no existing table is altered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_calendar_annotations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('content_project_id');
            $table->foreign('content_project_id')->references('id')->on('content_projects');
            $table->timestamp('annotated_at');
            $table->string('name', 150);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign('updated_by')->references('id')->on('users');
            $table->timestamps();
            $table->softDeletes();

            // No unique index on `annotated_at`: several annotations may share
            // the same day. This composite index only accelerates the monthly
            // read of one project's calendar.
            $table->index(['content_project_id', 'annotated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_calendar_annotations');
    }
};
