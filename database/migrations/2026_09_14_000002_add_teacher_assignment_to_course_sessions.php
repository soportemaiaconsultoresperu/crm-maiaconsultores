<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The guard must cover the whole migration, and its last step is the DATA
        // backfill, not the schema. MySQL DDL is not transactional, so a retry after a
        // failure mid-backfill would otherwise return early and permanently skip
        // assigning legacy session teachers. The backfill is idempotent — it only fills
        // rows with a null teacher_id that match exactly one teacher — so finishing it
        // here is safe.
        if (Schema::hasColumn('course_sessions', 'teacher_id')
            && $this->hasTeacherAssignmentIndex()) {
            $this->backfillUnambiguousTeacherIds();

            return;
        }

        $driver = DB::getDriverName();

        Schema::table('course_edition_teachers', function (Blueprint $table) use ($driver): void {
            if ($driver !== 'sqlite') {
                $table->unique(['id', 'course_edition_id'], 'course_edition_teachers_id_edition_unique');
            }
        });

        Schema::table('course_sessions', function (Blueprint $table) use ($driver): void {
            if ($driver === 'sqlite') {
                $table->unsignedBigInteger('teacher_id')->nullable()->after('course_edition_id');
            } else {
                $table->foreignId('teacher_id')->nullable()->after('course_edition_id')->constrained('course_edition_teachers')->restrictOnDelete();
                $table->foreign(['teacher_id', 'course_edition_id'], 'course_sessions_teacher_same_edition_fk')
                    ->references(['id', 'course_edition_id'])
                    ->on('course_edition_teachers')
                    ->restrictOnDelete();
            }

            $table->index(['teacher_id', 'course_edition_id'], 'course_sessions_teacher_edition_index');
        });

        $this->backfillUnambiguousTeacherIds();
    }

    public function down(): void
    {
        // Forward-only: dropping session teacher assignments after use would be destructive.
    }

        /**
         * Whether this migration's trailing index already exists. Used as the
         * end-of-migration marker so a partial MySQL failure is never mistaken for
         * a completed migration.
         */
        private function hasTeacherAssignmentIndex(): bool
        {
            foreach (Schema::getIndexes('course_sessions') as $index) {
                if (($index['name'] ?? null) === 'course_sessions_teacher_edition_index') {
                    return true;
                }
            }

            return false;
        }

        private function backfillUnambiguousTeacherIds(): void
    {
        $sessions = DB::table('course_sessions')
            ->whereNull('teacher_id')
            ->whereNotNull('teacher_name')
            ->orderBy('id')
            ->get(['id', 'course_edition_id', 'teacher_name']);

        foreach ($sessions as $session) {
            $name = $this->normalizeTeacherName($session->teacher_name);
            if ($name === '') {
                continue;
            }

            $matches = DB::table('course_edition_teachers')
                ->where('course_edition_id', $session->course_edition_id)
                ->whereNull('deleted_at')
                ->get(['id', 'display_name'])
                ->filter(fn ($teacher): bool => $this->normalizeTeacherName($teacher->display_name) === $name)
                ->values();

            if ($matches->count() === 1) {
                DB::table('course_sessions')->where('id', $session->id)->update(['teacher_id' => $matches->first()->id]);
            }
        }
    }

    private function normalizeTeacherName(?string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $name)) ?? '');
    }
};
