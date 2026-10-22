<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Only skip when the whole intended end state is present. MySQL DDL is
        // not transactional, so a partially applied migration must not return
        // early: that would leave the application running against an incomplete
        // schema (no soft deletes, no audit columns) until someone noticed.
        if (Schema::hasColumn('course_edition_teachers', 'id')
            && Schema::hasColumn('course_edition_teachers', 'deleted_at')
            && Schema::hasColumn('course_edition_teachers', 'created_by')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteTable(); return;
        }
        Schema::table('course_edition_teachers', fn (Blueprint $table) => $table->unsignedBigInteger('id')->nullable()->first());
        DB::statement('SET @course_teacher_identity := 0');
        DB::statement('UPDATE course_edition_teachers SET id = (@course_teacher_identity := @course_teacher_identity + 1) ORDER BY course_edition_id, sort_order');
        // MySQL refuses to drop the primary key while it is the only index
        // backing the course_edition_id foreign key, so create its replacement first.
        Schema::table('course_edition_teachers', fn (Blueprint $table) => $table->unique(['course_edition_id', 'sort_order'], 'course_edition_teachers_edition_order_unique'));
        DB::statement('ALTER TABLE course_edition_teachers DROP PRIMARY KEY');
        DB::statement('ALTER TABLE course_edition_teachers MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY');
        DB::statement('ALTER TABLE course_edition_teachers MODIFY sort_order SMALLINT UNSIGNED NULL');
        Schema::table('course_edition_teachers', function (Blueprint $table): void {
            $table->foreignId('created_by')->nullable()->after('sort_order')->constrained('users');
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users');
            $table->timestamps(); $table->softDeletes();
        });
    }

    public function down(): void {}

    private function rebuildSqliteTable(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::create('course_edition_teachers_new', function (Blueprint $table): void {
            $table->id(); $table->foreignId('course_edition_id')->constrained('course_editions')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('display_name'); $table->string('email')->nullable();
            $table->unsignedSmallInteger('sort_order')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps(); $table->softDeletes();
            $table->unique(['course_edition_id', 'sort_order'], 'course_edition_teachers_edition_order_unique');
        });
        DB::statement('INSERT INTO course_edition_teachers_new (id, course_edition_id, user_id, display_name, email, sort_order, created_by, updated_by, created_at, updated_at, deleted_at) SELECT ROW_NUMBER() OVER (ORDER BY course_edition_id, sort_order), course_edition_id, user_id, display_name, email, sort_order, NULL, NULL, NULL, NULL, NULL FROM course_edition_teachers ORDER BY course_edition_id, sort_order');
        Schema::drop('course_edition_teachers'); Schema::rename('course_edition_teachers_new', 'course_edition_teachers');
        Schema::enableForeignKeyConstraints();
    }
};
