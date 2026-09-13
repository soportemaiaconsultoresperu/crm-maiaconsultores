<?php

namespace Tests\Feature\Courses;

use App\Enums\Courses\CommercialDocumentType;
use App\Enums\Courses\CourseEnrollmentState;
use App\Http\Requests\CourseTalks\StoreCommercialDocumentRequest;
use App\Http\Requests\CourseTalks\UploadCommercialDocumentRequest;
use App\Models\Courses\CourseCommercialDocument;
use App\Models\Courses\CourseEnrollment;
use App\Models\Courses\CourseEnrollmentGroup;
use App\Models\Document;
use App\Models\User;
use App\Services\Courses\CourseCommercialDocumentService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CourseCommercialDocumentRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('course-talks.commercial-documents.manage');
        $this->actor = User::factory()->create();
        $this->actor->givePermissionTo('course-talks.commercial-documents.manage');
    }

    /**
     * One payable participant of a group purchase, carrying that participant's
     * own charges.
     */
    private function groupEnrollment(
        CourseEnrollmentGroup $group,
        string $activityPrice,
        string $certificateCharge = '0.00',
        string $discount = '0.00',
        CourseEnrollmentState $state = CourseEnrollmentState::Enrolled,
    ): CourseEnrollment {
        return CourseEnrollment::factory()->create([
            'course_edition_id' => $group->course_edition_id,
            'course_enrollment_group_id' => $group->id,
            'state' => $state,
            'activity_price_amount' => $activityPrice,
            'certificate_charge_amount' => $certificateCharge,
            'discount_amount' => $discount,
        ]);
    }

    /**
     * Simulates the winning side of a concurrent double submit: the other
     * request commits its own document between this request's replay lookup and
     * its own insert, so the unique index on `idempotency_key` rejects the
     * second write. The winner is written from a query listener right after the
     * replay lookup ran — the exact interleaving two real requests produce.
     *
     * @param  array<string, mixed>  $row
     */
    private function simulateConcurrentRegistrationWinner(string $operationKey, array $row): void
    {
        $listened = false;

        DB::listen(function (QueryExecuted $query) use ($operationKey, $row, &$listened): void {
            if ($listened || ! str_contains($query->sql, 'course_commercial_documents')) {
                return;
            }

            if (! in_array($operationKey, array_map('strval', $query->bindings), true)) {
                return;
            }

            $listened = true;
            DB::table('course_commercial_documents')->insert($row);
        });
    }

    private function registeredIdForOperationKey(string $operationKey): int
    {
        return (int) CourseCommercialDocument::query()->where('idempotency_key', $operationKey)->value('id');
    }

    /** @param array<string, mixed> $attributes */
    private function assertGroupRegistrationRefused(array $attributes, string $expectedMessage): void
    {
        try {
            app(CourseCommercialDocumentService::class)->register(
                CommercialDocumentType::Factura,
                ['payer_name' => 'Empresa que paga el grupo'] + $attributes,
                $this->actor,
            );
            $this->fail('Expected the group registration to be refused instead of writing a document.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString($expectedMessage, $exception->getMessage());
        }
    }

    public function test_it_registers_an_external_factura_for_one_enrollment_with_pending_upload_and_audit_values(): void
    {
        $enrollment = CourseEnrollment::factory()->create([
            'activity_price_amount' => '100.00',
            'certificate_charge_amount' => '20.00',
            'discount_amount' => '0.00',
        ]);

        $commercial = app(CourseCommercialDocumentService::class)->register(CommercialDocumentType::Factura, [
            'course_enrollment_id' => $enrollment->id,
            'payer_name' => 'Maia Consultores SAC',
            'payer_document_type' => 'ruc',
            'payer_document_number' => '20123456789',
            'series' => 'F001',
            'number' => '00001234',
            'issue_date' => '2026-08-26',
            'observations' => 'Emitido externamente.',
        ], $this->actor);

        $this->assertSame($enrollment->id, $commercial->course_enrollment_id);
        $this->assertNull($commercial->course_enrollment_group_id);
        $this->assertSame('120.00', $commercial->subtotal_amount);
        $this->assertSame('0.1800', $commercial->igv_rate);
        $this->assertSame('21.60', $commercial->igv_amount);
        $this->assertSame('141.60', $commercial->total_amount);
        $this->assertSame('PEN', $commercial->currency);
        $this->assertSame('pending_file', $commercial->status);
        $this->assertNull($commercial->document_id);
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_it_uploads_private_attachments_and_preserves_the_replaced_document_for_audit(): void
    {
        Storage::fake('docs');
        $commercial = app(CourseCommercialDocumentService::class)->register(CommercialDocumentType::Boleta, [
            'course_enrollment_id' => CourseEnrollment::factory()->create()->id,
            'payer_name' => 'Pagador',
        ], $this->actor);
        $service = app(CourseCommercialDocumentService::class);

        $first = $service->upload($commercial, UploadedFile::fake()->create('boleta-1.pdf', 4, 'application/pdf'), $this->actor);
        $second = $service->upload($commercial, UploadedFile::fake()->create('boleta-2.pdf', 4, 'application/pdf'), $this->actor);

        $commercial->refresh();
        $this->assertSame($second->id, $commercial->document_id);
        $this->assertSame('registered', $commercial->status);
        $this->assertStringStartsWith('course-commercial-documents/'.$commercial->id.'/', $second->path);
        $this->assertTrue(Storage::disk('docs')->exists($first->path));
        $this->assertTrue(Storage::disk('docs')->exists($second->path));
        $this->assertDatabaseCount('documents', 2);
        $this->assertDatabaseHas('documents', ['id' => $first->id, 'docable_id' => $commercial->id]);
    }

    public function test_it_uses_zero_igv_for_recibo_and_accepts_one_group_target(): void
    {
        // The group's money comes from its own enrollments: since this unit the
        // charges of a group purchase are never supplied by the payload.
        $group = CourseEnrollmentGroup::factory()->create();
        $this->groupEnrollment($group, '100.00', '20.00', '5.00');

        $commercial = app(CourseCommercialDocumentService::class)->register(CommercialDocumentType::Recibo, [
            'course_enrollment_group_id' => $group->id,
            'payer_name' => 'Grupo pagador',
        ], $this->actor);

        $this->assertSame($group->id, $commercial->course_enrollment_group_id);
        // A zero rate with a non-zero subtotal: the recibo is not a zero document.
        $this->assertSame('115.00', $commercial->subtotal_amount);
        $this->assertSame('0.0000', $commercial->igv_rate);
        $this->assertSame('0.00', $commercial->igv_amount);
        $this->assertSame('115.00', $commercial->total_amount);
    }

    public function test_it_aggregates_the_group_enrollment_charges_instead_of_writing_a_zero_total(): void
    {
        $group = CourseEnrollmentGroup::factory()->create();
        $this->groupEnrollment($group, '100.00', '20.00', '0.00');
        $this->groupEnrollment($group, '50.00', '0.00', '10.00');

        $commercial = app(CourseCommercialDocumentService::class)->register(CommercialDocumentType::Factura, [
            'course_enrollment_group_id' => $group->id,
            'payer_name' => 'Empresa que paga el grupo',
        ], $this->actor);

        $this->assertSame($group->id, $commercial->course_enrollment_group_id);
        $this->assertNull($commercial->course_enrollment_id);
        $this->assertSame('160.00', $commercial->subtotal_amount);
        $this->assertSame('0.1800', $commercial->igv_rate);
        $this->assertSame('28.80', $commercial->igv_amount);
        $this->assertSame('188.80', $commercial->total_amount);
        // The closed hole: a group target used to fall back to '0' charges and
        // persist a zero-value document with no error at all.
        $this->assertNotSame('0.00', $commercial->total_amount);
    }

    public function test_the_group_aggregation_leaves_out_the_terminal_withdrawn_and_no_show_enrollments(): void
    {
        $group = CourseEnrollmentGroup::factory()->create();
        $this->groupEnrollment($group, '100.00');
        $this->groupEnrollment($group, '500.00', '0.00', '0.00', CourseEnrollmentState::Withdrawn);
        $this->groupEnrollment($group, '300.00', '0.00', '0.00', CourseEnrollmentState::NoShow);

        $commercial = app(CourseCommercialDocumentService::class)->register(CommercialDocumentType::Boleta, [
            'course_enrollment_group_id' => $group->id,
            'payer_name' => 'Empresa que paga el grupo',
        ], $this->actor);

        // design.md declares `withdrawn` and `no_show` terminal: those
        // participants no longer receive the service or its certificate, so
        // their charges are not billable.
        $this->assertSame('100.00', $commercial->subtotal_amount);
        $this->assertSame('18.00', $commercial->igv_amount);
        $this->assertSame('118.00', $commercial->total_amount);
    }

    public function test_it_refuses_a_group_without_billable_enrollments_or_a_zero_aggregated_subtotal(): void
    {
        $withoutEnrollments = CourseEnrollmentGroup::factory()->create();
        $onlyTerminal = CourseEnrollmentGroup::factory()->create();
        $this->groupEnrollment($onlyTerminal, '100.00', '0.00', '0.00', CourseEnrollmentState::Withdrawn);
        $zeroCharges = CourseEnrollmentGroup::factory()->create();
        $this->groupEnrollment($zeroCharges, '0.00', '0.00', '0.00');

        $this->assertGroupRegistrationRefused(
            ['course_enrollment_group_id' => $withoutEnrollments->id],
            'no tiene matrículas facturables',
        );
        $this->assertGroupRegistrationRefused(
            ['course_enrollment_group_id' => $onlyTerminal->id],
            'no tiene matrículas facturables',
        );
        $this->assertGroupRegistrationRefused(
            ['course_enrollment_group_id' => $zeroCharges->id],
            'subtotal del grupo es cero',
        );

        $this->assertDatabaseCount('course_commercial_documents', 0);
    }

    public function test_the_group_aggregation_sums_only_the_enrollments_of_the_billed_group(): void
    {
        $billed = CourseEnrollmentGroup::factory()->create();
        $this->groupEnrollment($billed, '100.00');
        $other = CourseEnrollmentGroup::factory()->create([
            'course_edition_id' => $billed->course_edition_id,
        ]);
        $this->groupEnrollment($other, '700.00');
        // An enrollment outside any group of the same edition is not billable
        // through a group purchase either.
        CourseEnrollment::factory()->create([
            'course_edition_id' => $billed->course_edition_id,
            'activity_price_amount' => '900.00',
        ]);

        $commercial = app(CourseCommercialDocumentService::class)->register(CommercialDocumentType::Factura, [
            'course_enrollment_group_id' => $billed->id,
            'payer_name' => 'Empresa que paga el grupo',
        ], $this->actor);

        $this->assertSame('100.00', $commercial->subtotal_amount);
        $this->assertSame('18.00', $commercial->igv_amount);
        $this->assertSame('118.00', $commercial->total_amount);
    }

    public function test_the_group_payload_cannot_fabricate_the_charges_of_its_enrollments(): void
    {
        $group = CourseEnrollmentGroup::factory()->create();
        $this->groupEnrollment($group, '100.00', '20.00');

        $commercial = app(CourseCommercialDocumentService::class)->register(CommercialDocumentType::Factura, [
            'course_enrollment_group_id' => $group->id,
            'payer_name' => 'Empresa que paga el grupo',
            'activity_price_amount' => '999.00',
            'certificate_charge_amount' => '999.00',
            'discount_amount' => '0.00',
        ], $this->actor);

        $this->assertSame('120.00', $commercial->subtotal_amount);
        $this->assertSame('21.60', $commercial->igv_amount);
        $this->assertSame('141.60', $commercial->total_amount);
    }

    /**
     * The group target is authoritative: the money of a group purchase is the
     * aggregation of its own enrollments (the decision of unit 6.f-1b), so an
     * explicit `subtotal_amount` in the payload can neither raise it nor replace
     * it. The zero-total hole that unit closed must stay closed.
     */
    public function test_a_group_registration_keeps_the_aggregated_group_money_even_when_the_payload_declares_a_subtotal(): void
    {
        $group = CourseEnrollmentGroup::factory()->create();
        $this->groupEnrollment($group, '100.00', '20.00');

        $commercial = app(CourseCommercialDocumentService::class)->register(CommercialDocumentType::Factura, [
            'course_enrollment_group_id' => $group->id,
            'payer_name' => 'Grupo con importe declarado',
            'subtotal_amount' => '200.00',
        ], $this->actor);

        $this->assertSame('120.00', $commercial->subtotal_amount);
        $this->assertSame('21.60', $commercial->igv_amount);
        $this->assertSame('141.60', $commercial->total_amount);
    }

    /**
     * The bypass this unit closes: a payload declaring a subtotal used to be able
     * to zero a billable group, and a declared subtotal used to be able to rescue
     * a group with no billable money. Both go through the group aggregation.
     */
    public function test_a_declared_subtotal_cannot_bypass_the_group_aggregation_and_a_zero_result_is_never_persisted(): void
    {
        $billable = CourseEnrollmentGroup::factory()->create();
        $this->groupEnrollment($billable, '100.00', '20.00');
        $zero = CourseEnrollmentGroup::factory()->create();
        $this->groupEnrollment($zero, '0.00', '0.00');

        // A declared zero cannot turn a billable group into a zero-value document.
        $commercial = app(CourseCommercialDocumentService::class)->register(CommercialDocumentType::Factura, [
            'course_enrollment_group_id' => $billable->id,
            'payer_name' => 'Grupo facturable',
            'subtotal_amount' => '0.00',
        ], $this->actor);

        $this->assertSame('120.00', $commercial->subtotal_amount);
        $this->assertSame('141.60', $commercial->total_amount);
        $this->assertNotSame('0.00', $commercial->total_amount);

        // A declared subtotal cannot rescue a group with nothing billable either:
        // the zero result is refused, never persisted silently.
        $this->assertGroupRegistrationRefused(
            ['course_enrollment_group_id' => $zero->id, 'subtotal_amount' => '120.00'],
            'subtotal del grupo es cero',
        );

        $this->assertDatabaseCount('course_commercial_documents', 1);
    }

    /**
     * The enrollment target keeps its Slice 4 behavior byte for byte: with no
     * aggregation to protect, an explicit subtotal still wins.
     */
    public function test_an_explicit_subtotal_amount_still_wins_for_an_enrollment_target(): void
    {
        $enrollment = CourseEnrollment::factory()->create([
            'activity_price_amount' => '100.00',
            'certificate_charge_amount' => '20.00',
            'discount_amount' => '0.00',
        ]);

        $commercial = app(CourseCommercialDocumentService::class)->register(CommercialDocumentType::Factura, [
            'course_enrollment_id' => $enrollment->id,
            'payer_name' => 'Maia Consultores SAC',
            'subtotal_amount' => '90.00',
        ], $this->actor);

        $this->assertSame('90.00', $commercial->subtotal_amount);
        $this->assertSame('16.20', $commercial->igv_amount);
        $this->assertSame('106.20', $commercial->total_amount);
    }

    public function test_it_rejects_missing_or_multiple_targets_negative_amounts_registered_without_a_file_and_unauthorized_actors(): void
    {
        $enrollment = CourseEnrollment::factory()->create();
        $group = CourseEnrollmentGroup::factory()->create();
        $service = app(CourseCommercialDocumentService::class);

        foreach ([
            ['payer_name' => 'Sin destino'],
            ['course_enrollment_id' => $enrollment->id, 'course_enrollment_group_id' => $group->id, 'payer_name' => 'Dos destinos'],
            ['course_enrollment_id' => $enrollment->id, 'payer_name' => 'Monto negativo', 'activity_price_amount' => '-1.00'],
            ['course_enrollment_id' => $enrollment->id, 'payer_name' => 'Sin archivo', 'status' => 'registered'],
        ] as $attributes) {
            try {
                $service->register(CommercialDocumentType::Boleta, $attributes, $this->actor);
                $this->fail('Expected the registration to be rejected.');
            } catch (InvalidArgumentException) {
                $this->assertDatabaseCount('course_commercial_documents', 0);
            }
        }

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $service->register(CommercialDocumentType::Boleta, [
            'course_enrollment_id' => $enrollment->id,
            'payer_name' => 'No autorizado',
        ], User::factory()->create());
    }

    public function test_upload_keeps_document_service_file_validation_as_a_required_second_line_of_defence(): void
    {
        Storage::fake('docs');
        $commercial = app(CourseCommercialDocumentService::class)->register(CommercialDocumentType::Boleta, [
            'course_enrollment_id' => CourseEnrollment::factory()->create()->id,
            'payer_name' => 'Pagador',
        ], $this->actor);

        $this->expectException(InvalidArgumentException::class);
        app(CourseCommercialDocumentService::class)->upload(
            $commercial,
            UploadedFile::fake()->create('malicioso.exe', 1, 'application/x-msdownload'),
            $this->actor,
        );
    }

    public function test_registration_and_upload_requests_reject_invalid_targets_and_missing_required_file(): void
    {
        $registration = Validator::make([
            'type' => 'factura',
            'payer_name' => 'Pagador',
            'course_enrollment_id' => null,
            'course_enrollment_group_id' => null,
        ], (new StoreCommercialDocumentRequest())->rules());
        $upload = Validator::make(['status' => 'registered'], (new UploadCommercialDocumentRequest())->rules());
        $targetConflict = new StoreCommercialDocumentRequest();
        $targetConflict->replace([
            'type' => 'factura',
            'payer_name' => 'Pagador',
            'course_enrollment_id' => 1,
            'course_enrollment_group_id' => 2,
        ]);
        $conflictValidator = Validator::make($targetConflict->all(), $targetConflict->rules());
        $targetConflict->withValidator($conflictValidator);

        $this->assertTrue($registration->fails());
        $this->assertTrue($upload->fails());
        $this->assertArrayHasKey('file', $upload->errors()->toArray());
        $this->assertTrue($conflictValidator->fails());
        $this->assertArrayHasKey('course_enrollment_id', $conflictValidator->errors()->toArray());
    }

    /**
     * Defect A — a double submit of the registration form registered the very
     * same financial document twice. A replay of one operation must return the
     * document the operation already registered instead of writing a second
     * factura with the same total.
     */
    public function test_a_replayed_registration_operation_key_returns_the_existing_document_instead_of_duplicating_it(): void
    {
        $enrollment = CourseEnrollment::factory()->create([
            'activity_price_amount' => '100.00',
            'certificate_charge_amount' => '20.00',
            'discount_amount' => '0.00',
        ]);
        $service = app(CourseCommercialDocumentService::class);
        $operation = [
            'course_enrollment_id' => $enrollment->id,
            'payer_name' => 'Maia Consultores SAC',
            'series' => 'F001',
            'number' => '00001234',
            'operation_key' => 'commercial-registration-replay-001',
        ];

        $registered = $service->register(CommercialDocumentType::Factura, $operation, $this->actor);
        $replayed = $service->register(CommercialDocumentType::Factura, $operation, $this->actor);

        $this->assertSame($registered->id, $replayed->id);
        $this->assertSame('commercial-registration-replay-001', $replayed->idempotency_key);
        $this->assertSame('141.60', $replayed->total_amount);
        $this->assertDatabaseCount('course_commercial_documents', 1);
    }

    /**
     * Defect A — the two requests of a double submit race each other: both miss
     * the replay lookup and both try to insert. The loser of the race must
     * resolve to the row the winner registered (like the delivery channel
     * does), never escape as an error and never add a second document.
     */
    public function test_a_concurrent_registration_collision_resolves_to_the_row_the_other_request_registered(): void
    {
        $enrollment = CourseEnrollment::factory()->create([
            'activity_price_amount' => '100.00',
            'certificate_charge_amount' => '20.00',
            'discount_amount' => '0.00',
        ]);
        $operationKey = 'commercial-registration-collision-001';
        // The column under test is the only place a replay can be recognised:
        // without it the racing winner cannot even be written. Asserting its
        // existence first keeps the pre-fix state an assertion failure instead
        // of an unreadable insert error.
        $this->assertTrue(
            Schema::hasColumn('course_commercial_documents', 'idempotency_key'),
            'La colisión concurrente sólo puede reproducirse sobre la columna idempotency_key.',
        );
        $this->simulateConcurrentRegistrationWinner($operationKey, [
            'course_enrollment_id' => $enrollment->id,
            'type' => CommercialDocumentType::Factura->value,
            'currency' => 'PEN',
            'subtotal_amount' => '120.00',
            'igv_rate' => '0.1800',
            'igv_amount' => '21.60',
            'total_amount' => '141.60',
            'payer_name' => 'Comprobante del request ganador',
            'status' => 'pending_file',
            'idempotency_key' => $operationKey,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $registered = app(CourseCommercialDocumentService::class)->register(
            CommercialDocumentType::Factura,
            [
                'course_enrollment_id' => $enrollment->id,
                'payer_name' => 'Maia Consultores SAC',
                'operation_key' => $operationKey,
            ],
            $this->actor,
        );

        $this->assertSame($this->registeredIdForOperationKey($operationKey), $registered->id);
        $this->assertSame('Comprobante del request ganador', $registered->payer_name);
        $this->assertDatabaseCount('course_commercial_documents', 1);
    }

    /**
     * The new column is additive and nullable: a document registered by a path
     * that supplies no operation key must keep registering exactly as before,
     * and the unique index must not turn two key-less documents into a
     * conflict. This is also the behavioural proof of the NULL-vs-unique-index
     * semantics on the connection the test suite runs on.
     */
    public function test_the_unique_index_on_the_new_column_still_allows_many_documents_without_a_key(): void
    {
        $this->assertTrue(
            Schema::hasColumn('course_commercial_documents', 'idempotency_key'),
            'La columna idempotency_key debe existir en course_commercial_documents.',
        );

        $enrollment = CourseEnrollment::factory()->create();
        $service = app(CourseCommercialDocumentService::class);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $service->register(CommercialDocumentType::Boleta, [
                'course_enrollment_id' => $enrollment->id,
                'payer_name' => 'Compra '.$attempt,
            ], $this->actor);
        }

        $this->assertSame(3, CourseCommercialDocument::query()->count());
        $this->assertSame(3, CourseCommercialDocument::query()->whereNull('idempotency_key')->count());
    }

    /**
     * The bound lives in the service (the only writer of the CHAR(64) column,
     * which a longer key would break on a strict database) and the request
     * mirrors it so the user reads a field error instead of a domain rejection.
     */
    public function test_the_registration_operation_key_is_bounded_by_the_service_rule(): void
    {
        $enrollment = CourseEnrollment::factory()->create();
        $tooLong = str_repeat('a', 65);
        $validPayload = [
            'type' => 'factura',
            'payer_name' => 'Pagador',
            'course_enrollment_id' => $enrollment->id,
        ];
        $rules = (new StoreCommercialDocumentRequest())->rules();

        $overBound = Validator::make($validPayload + ['operation_key' => $tooLong], $rules);
        $this->assertTrue($overBound->fails());
        $this->assertArrayHasKey('operation_key', $overBound->errors()->toArray());

        $atBound = Validator::make($validPayload + ['operation_key' => str_repeat('a', 64)], $rules);
        $this->assertFalse($atBound->fails());

        try {
            app(CourseCommercialDocumentService::class)->register(CommercialDocumentType::Boleta, [
                'course_enrollment_id' => $enrollment->id,
                'payer_name' => 'Clave demasiado larga',
                'operation_key' => $tooLong,
            ], $this->actor);
            $this->fail('El servicio debe rechazar una clave de operación mayor a 64 caracteres.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('La clave de operación no puede superar los 64 caracteres.', $exception->getMessage());
        }

        $this->assertDatabaseCount('course_commercial_documents', 0);
    }
}
