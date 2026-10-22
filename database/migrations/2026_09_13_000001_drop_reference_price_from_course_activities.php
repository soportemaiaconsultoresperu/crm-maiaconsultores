<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the unreachable `course_activities.reference_price` column.
 *
 * The column is nullable `decimal(14,2)` and NOTHING can ever load it: it is not
 * in `StoreCourseActivityRequest` (which validates exactly the attributes
 * `CourseActivityService::create()` consumes), it has no input in
 * `resources/views/course-talks/activities/create.blade.php`, no service reads or
 * writes it, and no test asserts it. It was carried by the model's `$fillable`
 * and `casts()` and by `CourseActivityFactory`, so the only thing that ever set
 * it was the factory — on rows that exist solely inside the test transaction.
 * Verified against the local development database before dropping it:
 * `SELECT COUNT(*) FROM course_activities` returned 1 row and
 * `SUM(reference_price IS NOT NULL)` returned 0, so the drop discards no data
 * and no operator has ever seen this field.
 *
 * Forward effect (destructive but empty):
 *   - drops one column from `course_activities`; SQLite (>= 3.35, native
 *     `ALTER TABLE ... DROP COLUMN`) and MySQL 8 both do this without touching
 *     any other column;
 *   - all 1 existing row survives, losing only a value that was already NULL
 *     everywhere;
 *   - no index, unique constraint or foreign key references the column, so none
 *     is dropped or rebuilt: `course_activities` keeps its unique `code` and its
 *     `['type','is_active']` / `name` indexes untouched;
 *   - no application write changes shape, because nothing read or wrote it.
 *
 * Rollback effect: `down()` re-adds the column exactly as it was — nullable
 * `decimal(14,2)`, positioned after `base_syllabus_json` where the creating
 * migration put it — so `migrate:rollback` restores the original schema shape.
 * The re-added column is empty on every row: the values it held were NULL
 * before the drop, and a dropped column cannot be un-dropped with data. That is
 * the whole recoverable content, so the rollback is complete in practice, not
 * merely structurally.
 *
 * Deliberately NOT an edit to `2026_08_26_000001_create_course_domain_foundation_tables`:
 * that migration has already run on this database (and on every other one), so
 * editing it would leave each existing database with a column the schema says
 * should not exist, and only a fresh `migrate:fresh` would ever agree. A new
 * additive-in-reverse migration is the only honest way to remove a column that
 * is already applied.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_activities', function (Blueprint $table): void {
            $table->dropColumn('reference_price');
        });
    }

    public function down(): void
    {
        Schema::table('course_activities', function (Blueprint $table): void {
            $table->decimal('reference_price', 14, 2)->nullable()->after('base_syllabus_json');
        });
    }
};
