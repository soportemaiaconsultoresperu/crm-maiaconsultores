<?php

namespace Tests\Feature\Courses;

use App\Enums\Courses\CourseActivityType;
use App\Enums\Courses\CourseEditionState;
use App\Enums\Courses\CourseEnrollmentState;
use App\Enums\Courses\CourseModality;
use App\Enums\Courses\PaymentStatus;
use App\Models\Courses\CourseActivity;
use App\Models\Courses\CourseEdition;
use App\Models\Courses\CourseEditionTeacher;
use App\Models\Courses\CourseEnrollment;
use App\Models\Courses\CourseEnrollmentGroup;
use App\Models\Courses\CourseParticipant;
use App\Models\Courses\CourseSession;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CourseDomainFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_course_foundation_tables_and_defaults_exist(): void
    {
        // Exactly the 12 tables 2026_08_26_000001 creates: course_edition_teachers
        // included, because it is the one table the migration adds without an
        // `id` column and the module writes through it.
        foreach (['course_activities', 'course_editions', 'course_edition_teachers', 'course_sessions', 'course_participants', 'course_enrollment_groups', 'course_enrollments', 'course_attendances', 'course_grades', 'course_certificate_templates', 'course_academic_documents', 'course_commercial_documents'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table {$table}");
        }

        $this->assertSame(0.18, config('courses.igv_rate'));
        $this->assertSame(1, config('courses.delivery_due_days'));
        $this->assertSame('PEN', config('courses.default_currency'));
        $this->assertSame(10080, config('courses.email_document_link_minutes'));
    }

    /**
     * Defect B — the migration's `down()` drops all 12 domain tables and nothing
     * else. The private PDFs survive with no row left to locate them, and the
     * surviving `documents` rows can end up pointing at a DIFFERENT certificate
     * once MySQL reuses the AUTO_INCREMENT ids. No test drives that rollback, so
     * the damage is recorded as documentation; this test fails if the record is
     * deleted.
     */
    public function test_the_schema_rollback_damage_is_documented(): void
    {
        $doc = $this->knownLimitations();

        $this->assertStringContainsString('migrate:rollback', $doc);
        $this->assertStringContainsString('AUTO_INCREMENT', $doc);
        $this->assertStringContainsString('storage/app/private/docs', $doc);
    }

    /**
     * Finding 5 — `course_commercial_documents.status = 'sent'` is read by three
     * surfaces and written by nothing in `app/`. It stays as a reserved value
     * (the design lists it), and the reservation is recorded here so a future
     * reader can tell a deliberate value from a dead branch.
     */
    public function test_the_reserved_commercial_status_is_documented(): void
    {
        $doc = $this->knownLimitations();

        $this->assertStringContainsString('course_commercial_documents.status', $doc);
        $this->assertStringContainsString('reserved', $doc);
    }

    public function test_course_edition_teachers_have_stable_identity_audit_and_soft_delete_columns(): void
    {
        foreach (['id', 'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('course_edition_teachers', $column), "Missing teacher column {$column}");
        }

        $edition = CourseEdition::factory()->create();
        $first = CourseEditionTeacher::query()->create([
            'course_edition_id' => $edition->id,
            'display_name' => 'Ana Docente',
            'sort_order' => 1,
        ]);
        $second = CourseEditionTeacher::query()->create([
            'course_edition_id' => $edition->id,
            'display_name' => 'Luis Docente',
            'sort_order' => 2,
        ]);

        $this->assertIsInt($first->id);
        $this->assertIsInt($second->id);
        $this->assertNotSame($first->id, $second->id);
        $this->assertNotNull($first->created_at);
    }

    public function test_course_edition_teachers_keep_active_order_unique_while_soft_deleted_rows_release_their_slot(): void
    {
        $edition = CourseEdition::factory()->create();
        $teacher = CourseEditionTeacher::query()->create([
            'course_edition_id' => $edition->id,
            'display_name' => 'Ana Docente',
            'sort_order' => 1,
        ]);

        $teacher->forceFill(['sort_order' => null])->save();
        $teacher->delete();

        CourseEditionTeacher::query()->create([
            'course_edition_id' => $edition->id,
            'display_name' => 'Luis Docente',
            'sort_order' => 1,
        ]);

        $this->assertDatabaseHas('course_edition_teachers', [
            'id' => $teacher->id,
            'sort_order' => null,
        ]);
        $this->assertSame(1, CourseEditionTeacher::query()->count());
        $this->assertSame(2, CourseEditionTeacher::withTrashed()->count());
    }

    public function test_teacher_identity_migration_preserves_existing_sqlite_rows_with_deterministic_ids(): void
    {
        $editionA = CourseEdition::factory()->create();
        $editionB = CourseEdition::factory()->create();

        Schema::disableForeignKeyConstraints();
        Schema::drop('course_edition_teachers');
        Schema::create('course_edition_teachers', function (Blueprint $table): void {
            $table->unsignedBigInteger('course_edition_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('display_name');
            $table->string('email')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(1);
            $table->primary(['course_edition_id', 'sort_order']);
        });

        DB::table('course_edition_teachers')->insert([
            ['course_edition_id' => $editionB->id, 'display_name' => 'B Uno', 'sort_order' => 1],
            ['course_edition_id' => $editionA->id, 'display_name' => 'A Uno', 'sort_order' => 1],
            ['course_edition_id' => $editionA->id, 'display_name' => 'A Dos', 'sort_order' => 2],
        ]);

        $migration = require database_path('migrations/2026_09_14_000001_convert_course_edition_teachers_to_stable_identity.php');
        $migration->up();

        $rows = DB::table('course_edition_teachers')->orderBy('id')->get(['id', 'course_edition_id', 'display_name', 'sort_order']);
        $this->assertCount(3, $rows);
        $this->assertSame([1, 2, 3], $rows->pluck('id')->all());
        $this->assertSame(['A Uno', 'A Dos', 'B Uno'], $rows->pluck('display_name')->all());
        $this->assertTrue(Schema::hasColumn('course_edition_teachers', 'deleted_at'));
    }

    public function test_session_teacher_assignment_migration_backfills_only_exact_unambiguous_matches(): void
    {
        $edition = CourseEdition::factory()->create();
        $other = CourseEdition::factory()->create();
        CourseEditionTeacher::query()->create(['course_edition_id' => $edition->id, 'display_name' => 'Ana Docente', 'sort_order' => 1]);
        CourseEditionTeacher::query()->create(['course_edition_id' => $edition->id, 'display_name' => 'Nombre Duplicado', 'sort_order' => 2]);
        CourseEditionTeacher::query()->create(['course_edition_id' => $edition->id, 'display_name' => 'Nombre Duplicado', 'sort_order' => 3]);
        CourseEditionTeacher::query()->create(['course_edition_id' => $other->id, 'display_name' => 'Ana Docente', 'sort_order' => 1]);

        Schema::disableForeignKeyConstraints();
        Schema::drop('course_sessions');
        Schema::create('course_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_edition_id')->constrained('course_editions');
            $table->date('session_date')->nullable();
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->string('teacher_name')->nullable();
            $table->string('topic');
            $table->unsignedSmallInteger('sort_order')->default(1);
            $table->unique(['course_edition_id', 'sort_order']);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::enableForeignKeyConstraints();

        DB::table('course_sessions')->insert([
            ['course_edition_id' => $edition->id, 'teacher_name' => ' Ana   Docente ', 'topic' => 'Exacta', 'sort_order' => 1],
            ['course_edition_id' => $edition->id, 'teacher_name' => 'Sin coincidencia', 'topic' => 'No match', 'sort_order' => 2],
            ['course_edition_id' => $edition->id, 'teacher_name' => 'Nombre Duplicado', 'topic' => 'Ambigua', 'sort_order' => 3],
        ]);

        (require database_path('migrations/2026_09_14_000002_add_teacher_assignment_to_course_sessions.php'))->up();

        $rows = DB::table('course_sessions')->orderBy('sort_order')->get(['teacher_name', 'teacher_id']);
        $this->assertTrue(Schema::hasColumn('course_sessions', 'teacher_id'));
        $this->assertNotNull($rows[0]->teacher_id);
        $this->assertNull($rows[1]->teacher_id);
        $this->assertNull($rows[2]->teacher_id);
    }

    public function test_activity_codes_and_enrollments_are_unique(): void
    {
        CourseActivity::create(['type' => CourseActivityType::Course, 'code' => 'CUR-SAN-001', 'name' => 'Saneamiento', 'slug' => 'saneamiento', 'official_academic_hours' => '8.00']);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        CourseActivity::create(['type' => CourseActivityType::Talk, 'code' => 'CUR-SAN-001', 'name' => 'Duplicado', 'slug' => 'duplicado', 'official_academic_hours' => '2.00']);
    }

    public function test_models_cast_enums_and_resolve_relationships_for_group_enrollments(): void
    {
        $activity = CourseActivity::factory()->create(['type' => CourseActivityType::Course]);
        $edition = CourseEdition::factory()->for($activity, 'activity')->create(['state' => CourseEditionState::Scheduled, 'modality' => CourseModality::Hybrid]);
        $participant = CourseParticipant::factory()->create();
        $group = CourseEnrollmentGroup::factory()->for($edition, 'edition')->create(['payer_name' => 'Maia Consultores']);
        $enrollment = CourseEnrollment::factory()->for($edition, 'edition')->for($participant, 'participant')->for($group, 'group')->create(['state' => CourseEnrollmentState::Confirmed, 'payment_status' => PaymentStatus::Paid]);
        $session = CourseSession::factory()->for($edition, 'edition')->create();

        $this->assertSame(CourseEditionState::Scheduled, $enrollment->edition->state);
        $this->assertSame(CourseModality::Hybrid, $edition->modality);
        $this->assertSame('Maia Consultores', $enrollment->group->payer_name);
        $this->assertTrue($edition->sessions->contains($session));
        $this->assertTrue($participant->enrollments->contains($enrollment));
    }

    public function test_enrollment_uniqueness_is_per_edition_and_participant(): void
    {
        $edition = CourseEdition::factory()->create();
        $participant = CourseParticipant::factory()->create();
        CourseEnrollment::factory()->for($edition, 'edition')->for($participant, 'participant')->create();

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        CourseEnrollment::factory()->for($edition, 'edition')->for($participant, 'participant')->create();
    }

    private function knownLimitations(): string
    {
        // The record must be found whether the change is still ACTIVE or already
        // ARCHIVED: closing a change moves its artifacts under
        // `openspec/changes/archive/<date>-<name>/`, so hardcoding the active path
        // made these two tests fail the moment the change was archived. The
        // assertion is about the record existing, not about where it lives.
        $candidates = [
            base_path('openspec/changes/course-talks-management/known-limitations.md'),
        ];

        foreach (glob(base_path('openspec/changes/archive/*-course-talks-management/known-limitations.md')) ?: [] as $archived) {
            $candidates[] = $archived;
        }

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return (string) file_get_contents($path);
            }
        }

        throw new \RuntimeException(
            'El registro de limitaciones del cambio debe existir, en el change activo o en el archivado.'
        );
    }
}
