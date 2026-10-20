<?php

use App\Http\Controllers\CourseTalks\CourseActivityController;
use App\Http\Controllers\CourseTalks\CourseEditionController;
use Illuminate\Support\Facades\Route;

/*
 * Edit and guarded delete of course/talk activities and their dictados.
 *
 * Required INSIDE the `course-talks.` group of routes/web.php, which already
 * applies the `auth` + `active` middleware, the `course-talks` prefix and the
 * `course-talks.` name prefix, so every URI and name here is relative to it.
 * The `edit` segments are deeper than the read-only group's
 * `activities/{activity}` and `editions/{edition}` bindings, and PUT/DELETE
 * never collide with their GET, so the order inside the group is irrelevant.
 */

Route::get('activities/{activity}/edit', [CourseActivityController::class, 'edit'])->name('activities.edit');
Route::put('activities/{activity}', [CourseActivityController::class, 'update'])->name('activities.update');
Route::delete('activities/{activity}', [CourseActivityController::class, 'destroy'])->name('activities.destroy');

Route::get('editions/{edition}/edit', [CourseEditionController::class, 'edit'])->name('editions.edit');
Route::put('editions/{edition}', [CourseEditionController::class, 'update'])->name('editions.update');
Route::delete('editions/{edition}', [CourseEditionController::class, 'destroy'])->name('editions.destroy');
