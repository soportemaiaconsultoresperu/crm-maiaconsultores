<?php

declare(strict_types=1);

namespace Tests\Feature\Courses;

use App\Enums\Courses\AcademicDocumentStatus;
use App\Enums\Courses\AcademicDocumentType;
use App\Enums\Courses\CommercialDocumentType;
use App\Enums\Courses\CourseActivityType;
use App\Enums\Courses\DeliveryStatus;
use App\Models\Courses\CourseAcademicDocument;
use App\Models\Courses\CourseActivity;
use App\Models\Courses\CourseCommercialDocument;
use App\Models\Courses\CourseEdition;
use App\Models\Courses\CourseEnrollment;
use App\Models\Courses\CourseEnrollmentGroup;
use App\Models\Courses\CourseParticipant;
use App\Models\Document;
use App\Models\Notification\OutboundDelivery;
use App\Models\User;
use App\Services\Courses\CourseAlertService;
use App\Services\Courses\CourseDocumentDeliveryService;
use Database\Seeders\CoursePermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Slice 7 unit 7.b — the delivery alert dashboards and the filterable alert
 * list.
 *
 * The rules themselves belong to CourseAlertService (7.a) and to
 * CourseDocumentDeliveryService (the deliverability predicates); these tests
 * assert what a user of the two screens actually gets: the counts on screen are
 * the ones the alert domain computes, each filter really narrows the list, an
 * undeliverable follow-up is told apart from a merely pending one, and a user
 * without an ability sees neither data nor a control.
 *
 * URLs are written literally instead of through `route()` on purpose: a missing
 * route then fails as a real HTTP assertion (404 where 200 was expected) instead
 * of erroring out before any rule is exercised.
 */
class CourseDeliveryAlertsDashboardTest extends TestCase
{
    use RefreshDatabase;

    private const ALERTS_URL = '/course-talks/alerts';

    private const DASHBOARD_URL = '/dashboard';

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed clock: the due rule is calendar-day based, so an overdue fixture
        // must not depend on when the suite runs. `delivery_due_days` is 1, so
        // anything anchored on or after 2026-01-24 is still merely pending.
        Carbon::setTestNow('2026-01-25 09:00:00');
        Storage::fake('docs');

        $this->seed(CoursePermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // A — the main dashboard counters
    // ---------------------------------------------------------------------

    public function test_the_main_dashboard_shows_the_course_delivery_counts_to_a_user_who_can_see_the_module(): void
    {
        $viewer = $this->viewer(['course-talks.view']);
        $this->pendingAcademic(['code' => 'CERT-DASH-A', 'issue_date' => '2026-01-05']);
        $this->pendingCommercial(['series' => 'B001', 'number' => '900001', 'issue_date' => '2026-01-25']);

        $alerts = new CourseAlertService();
        $this->assertSame(2, $alerts->pendingCount());
        $this->assertSame(1, $alerts->overdueCount());

        $content = $this->actingAs($viewer)->get(self::DASHBOARD_URL)->assertOk()->getContent();

        $this->assertSame($alerts->pendingCount(), $this->counter($content, 'kpi-course-alerts-pending'));
        $this->assertSame($alerts->overdueCount(), $this->counter($content, 'kpi-course-alerts-overdue'));
        // The card links to the module's own alert list (route() renders an
        // absolute URL, so the path is what is asserted).
        $this->assertStringContainsString('data-testid="dashboard-course-alerts-link"', $content);
        $this->assertStringContainsString(self::ALERTS_URL, $content);
        $this->assertStringContainsString('Entregas de Cursos y charlas', $content);
    }

    public function test_the_main_dashboard_shows_nothing_about_the_module_to_a_user_who_cannot_see_it(): void
    {
        $outsider = $this->viewer();
        $this->pendingAcademic(['code' => 'CERT-DASH-B', 'issue_date' => '2026-01-05']);

        $content = $this->actingAs($outsider)->get(self::DASHBOARD_URL)->assertOk()->getContent();

        $this->assertStringNotContainsString('Entregas de Cursos y charlas', $content);
        $this->assertStringNotContainsString('kpi-course-alerts-pending', $content);
        $this->assertStringNotContainsString(self::ALERTS_URL, $content);
        // The dashboard itself still renders for that user: the guard is the
        // module section, not the page.
        $this->assertStringContainsString('Rendimiento por vendedor', $content);
    }

    // ---------------------------------------------------------------------
    // B — the module alert screen: counts and content
    // ---------------------------------------------------------------------

    public function test_the_alert_screen_counts_equal_the_alert_service_counts_including_after_a_discard_closes_one(): void
    {
        $manager = $this->manager();
        $academic = $this->pendingAcademic(['code' => 'CERT-COUNT-A', 'issue_date' => '2026-01-05']);
        $this->academicDocument(['code' => 'CERT-COUNT-B', 'issue_date' => '2026-01-20', 'delivery_status' => DeliveryStatus::Failed]);
        $this->pendingCommercial(['series' => 'B001', 'number' => '900002', 'issue_date' => '2026-01-25']);

        $alerts = new CourseAlertService();
        $this->assertSame(3, $alerts->pendingCount());
        $this->assertSame(2, $alerts->overdueCount());
        $this->assertSame(3, $this->screenCounts($manager, 'course-talks-alerts-pending-count'));
        $this->assertSame(2, $this->screenCounts($manager, 'course-talks-alerts-overdue-count'));
        $this->assertSame(
            $alerts->pendingCount(),
            $this->counter($this->actingAs($manager)->get(self::DASHBOARD_URL)->assertOk()->getContent(), 'kpi-course-alerts-pending'),
        );

        $this->actingAs($manager)
            ->post(self::ALERTS_URL.'/academic-documents/'.$academic->id.'/discard', ['reason' => 'El participante retiró su consentimiento'])
            ->assertRedirect(self::ALERTS_URL);

        // The state the rule is about: the discard really closed the follow-up.
        $this->assertSame(DeliveryStatus::Discarded, $academic->fresh()->delivery_status);

        $after = new CourseAlertService();
        $this->assertSame(2, $after->pendingCount());
        $this->assertSame(1, $after->overdueCount());

        // Both screens moved with the domain, with no recomputation of their own.
        $this->assertSame($after->pendingCount(), $this->screenCounts($manager, 'course-talks-alerts-pending-count'));
        $this->assertSame($after->overdueCount(), $this->screenCounts($manager, 'course-talks-alerts-overdue-count'));
        $this->assertSame(
            $after->pendingCount(),
            $this->counter($this->actingAs($manager)->get(self::DASHBOARD_URL)->assertOk()->getContent(), 'kpi-course-alerts-pending'),
        );

        // The discarded follow-up left the list and the screen exposes no
        // private storage path nor the stored QR token hash.
        $content = $this->actingAs($manager)->get(self::ALERTS_URL)->assertOk()->getContent();
        $this->assertStringNotContainsString('CERT-COUNT-A', $content);
        $this->assertStringContainsString('CERT-COUNT-B', $content);
    }

    public function test_the_alert_screen_shows_what_the_operator_needs_for_both_channels(): void
    {
        $manager = $this->manager();
        $responsible = User::factory()->create(['is_active' => true, 'name' => 'Rosa Responsable']);
        $activity = CourseActivity::factory()->create(['type' => CourseActivityType::Course, 'name' => 'Actividad Uno']);
        $edition = CourseEdition::factory()->for($activity, 'activity')->create(['code' => 'ED-ONE', 'responsible_user_id' => $responsible->id]);
        $participant = CourseParticipant::factory()->create(['first_name' => 'Ana', 'last_name' => 'Alvarez']);
        $enrollment = CourseEnrollment::factory()->for($edition, 'edition')->for($participant, 'participant')->create();

        $academic = $this->academicDocument([
            'course_enrollment_id' => $enrollment->id,
            'code' => 'CERT-CONTENT-A',
            'issue_date' => '2026-01-05',
            'type' => AcademicDocumentType::TalkCertificate,
        ]);
        $filePath = 'course-academic-documents/'.$enrollment->id.'/CERT-CONTENT-A.pdf';
        $hash = $this->privateFile($academic, $filePath, '%PDF certificate');
        $academic->forceFill(['qr_token_hash' => $hash])->save();

        $commercial = $this->pendingCommercial([
            'course_enrollment_id' => $enrollment->id,
            'series' => 'B001',
            'number' => '900003',
            'issue_date' => '2026-01-06',
        ]);
        $this->deliveryAttempt($commercial, OutboundDelivery::CHANNEL_WHATSAPP, 'queued');
        $this->deliveryAttempt($commercial, OutboundDelivery::CHANNEL_MAIL, 'sent');

        $content = $this->actingAs($manager)->get(self::ALERTS_URL)->assertOk()->getContent();

        // Academic follow-up: activity, edition, participant, responsible, type,
        // status, due state.
        $this->assertStringContainsString('CERT-CONTENT-A', $content);
        $this->assertStringContainsString('Actividad Uno', $content);
        $this->assertStringContainsString('ED-ONE', $content);
        $this->assertStringContainsString('Ana', $content);
        $this->assertStringContainsString('Rosa Responsable', $content);
        $this->assertStringContainsString('Certificado de charla', $content);
        $this->assertStringContainsString('course-talks-alert-overdue-academico-'.$academic->id, $content);
        $this->assertStringContainsString('course-talks-alert-channel-academico-'.$academic->id.'" data-channel=""', $content);
        $this->assertStringContainsString('course-talks-alert-history-academico-'.$academic->id.'" data-attempts="0"', $content);

        // Commercial follow-up: comprobante type plus the LAST attempt, which is
        // the mail one even though WhatsApp came first.
        $this->assertStringContainsString('B001-900003', $content);
        $this->assertStringContainsString('Boleta', $content);
        $this->assertStringContainsString('course-talks-alert-channel-comercial-'.$commercial->id.'" data-channel="mail"', $content);
        $this->assertStringContainsString('course-talks-alert-history-comercial-'.$commercial->id.'" data-attempts="2"', $content);

        // Nothing private leaks through the screen.
        $this->assertStringNotContainsString($filePath, $content);
        $this->assertStringNotContainsString('storage/app', $content);
        $this->assertStringNotContainsString($hash, $content);
    }

    // ---------------------------------------------------------------------
    // D — the undeliverable state
    // ---------------------------------------------------------------------

    public function test_an_undeliverable_but_outstanding_document_is_visibly_distinguished_from_a_pending_one(): void
    {
        $manager = $this->manager();
        $deliverable = $this->pendingAcademic(['code' => 'CERT-WITH-FILE', 'issue_date' => '2026-01-05']);
        $filePath = 'course-academic-documents/'.$deliverable->course_enrollment_id.'/CERT-WITH-FILE.pdf';
        $this->privateFile($deliverable, $filePath, '%PDF certificate');

        $undeliverable = $this->pendingAcademic(['code' => 'CERT-NO-FILE', 'issue_date' => '2026-01-05']);
        $withMissingFile = $this->pendingAcademic(['code' => 'CERT-GONE-FILE', 'issue_date' => '2026-01-06']);
        $this->privateFile($withMissingFile, 'course-academic-documents/'.$withMissingFile->course_enrollment_id.'/CERT-GONE-FILE.pdf', '%PDF certificate');
        Storage::disk('docs')->delete('course-academic-documents/'.$withMissingFile->course_enrollment_id.'/CERT-GONE-FILE.pdf');

        $alerts = new CourseAlertService();
        $this->assertSame(3, $alerts->pendingCount());

        $content = $this->actingAs($manager)->get(self::ALERTS_URL)->assertOk()->getContent();

        // All three are outstanding: the alert domain deliberately does not check
        // the file, so the screen must show them AND tell them apart.
        $this->assertStringContainsString('CERT-WITH-FILE', $content);
        $this->assertStringContainsString('CERT-NO-FILE', $content);
        $this->assertStringContainsString('CERT-GONE-FILE', $content);

        $this->assertStringContainsString('course-talks-alert-deliverable-academico-'.$deliverable->id, $content);
        $this->assertStringContainsString('course-talks-alert-undeliverable-academico-'.$undeliverable->id, $content);
        $this->assertStringContainsString('course-talks-alert-undeliverable-academico-'.$withMissingFile->id, $content);
        $this->assertStringNotContainsString('course-talks-alert-undeliverable-academico-'.$deliverable->id, $content);
        $this->assertStringNotContainsString('course-talks-alert-deliverable-academico-'.$undeliverable->id, $content);

        // An undeliverable follow-up is still closable: discarding is exactly how
        // an operator stops chasing a document that cannot be sent.
        $this->assertStringContainsString('course-talks-alert-discard-form-academico-'.$undeliverable->id, $content);
    }

    // ---------------------------------------------------------------------
    // C — the filters
    // ---------------------------------------------------------------------

    public function test_the_activity_type_filter_narrows_the_list(): void
    {
        $viewer = $this->viewer(['course-talks.view']);
        $this->filterScenario();

        $this->assertAllScenarioDocumentsVisible($viewer);

        $content = $this->actingAs($viewer)->get(self::ALERTS_URL.'?activity_type=course')->assertOk()->getContent();

        $this->assertStringContainsString('CERT-ALFA', $content);
        $this->assertStringContainsString('B001-111111', $content);
        $this->assertStringNotContainsString('CERT-BETA', $content);
        $this->assertStringNotContainsString('F001-222222', $content);
    }

    public function test_the_edition_filter_narrows_the_list(): void
    {
        $viewer = $this->viewer(['course-talks.view']);
        $s = $this->filterScenario();

        $content = $this->actingAs($viewer)->get(self::ALERTS_URL.'?edition_id='.$s['editionB']->id)->assertOk()->getContent();

        $this->assertStringContainsString('CERT-BETA', $content);
        $this->assertStringContainsString('F001-222222', $content);
        $this->assertStringNotContainsString('CERT-ALFA', $content);
        $this->assertStringNotContainsString('B001-111111', $content);
    }

    public function test_the_participant_filter_narrows_the_list(): void
    {
        $viewer = $this->viewer(['course-talks.view']);
        $s = $this->filterScenario();

        $content = $this->actingAs($viewer)->get(self::ALERTS_URL.'?participant_id='.$s['participantB']->id)->assertOk()->getContent();

        $this->assertStringContainsString('CERT-BETA', $content);
        $this->assertStringContainsString('F001-222222', $content);
        $this->assertStringNotContainsString('CERT-ALFA', $content);
        $this->assertStringNotContainsString('B001-111111', $content);
    }

    public function test_the_responsible_filter_narrows_the_list(): void
    {
        $viewer = $this->viewer(['course-talks.view']);
        $s = $this->filterScenario();

        $content = $this->actingAs($viewer)->get(self::ALERTS_URL.'?responsible_user_id='.$s['responsibleB']->id)->assertOk()->getContent();

        $this->assertStringContainsString('CERT-BETA', $content);
        $this->assertStringContainsString('F001-222222', $content);
        $this->assertStringNotContainsString('CERT-ALFA', $content);
        $this->assertStringNotContainsString('B001-111111', $content);
    }

    public function test_the_document_type_filter_narrows_the_list(): void
    {
        $viewer = $this->viewer(['course-talks.view']);
        $this->filterScenario();

        $content = $this->actingAs($viewer)->get(self::ALERTS_URL.'?document_type=boleta')->assertOk()->getContent();

        $this->assertStringContainsString('B001-111111', $content);
        $this->assertStringNotContainsString('CERT-ALFA', $content);
        $this->assertStringNotContainsString('CERT-BETA', $content);
        $this->assertStringNotContainsString('F001-222222', $content);
    }

    public function test_the_delivery_status_filter_narrows_the_list(): void
    {
        $viewer = $this->viewer(['course-talks.view']);
        $this->filterScenario();

        $content = $this->actingAs($viewer)->get(self::ALERTS_URL.'?delivery_status=failed')->assertOk()->getContent();

        $this->assertStringContainsString('CERT-BETA', $content);
        $this->assertStringContainsString('F001-222222', $content);
        $this->assertStringNotContainsString('CERT-ALFA', $content);
        $this->assertStringNotContainsString('B001-111111', $content);
    }

    public function test_the_channel_filter_narrows_by_the_channel_of_the_last_attempt(): void
    {
        $viewer = $this->viewer(['course-talks.view']);
        $s = $this->filterScenario();

        $byMail = $this->actingAs($viewer)->get(self::ALERTS_URL.'?channel=mail')->assertOk()->getContent();

        // The LAST attempt decides, not any attempt: B001-111111 has a WhatsApp
        // attempt too, but its newest one is mail. A document with no attempt at
        // all cannot match a channel.
        $this->assertStringContainsString('B001-111111', $byMail);
        $this->assertStringNotContainsString('F001-222222', $byMail);
        $this->assertStringNotContainsString('CERT-ALFA', $byMail);
        $this->assertStringNotContainsString('CERT-BETA', $byMail);
        $this->assertStringContainsString('course-talks-alert-channel-comercial-'.$s['docC']->id.'" data-channel="mail"', $byMail);

        $byWhatsApp = $this->actingAs($viewer)->get(self::ALERTS_URL.'?channel=whatsapp')->assertOk()->getContent();

        $this->assertStringContainsString('F001-222222', $byWhatsApp);
        $this->assertStringNotContainsString('B001-111111', $byWhatsApp);
    }

    public function test_the_date_range_filter_narrows_the_list(): void
    {
        $viewer = $this->viewer(['course-talks.view']);
        $this->filterScenario();

        $this->assertAllScenarioDocumentsVisible($viewer);

        $from = $this->actingAs($viewer)->get(self::ALERTS_URL.'?date_from=2026-01-10')->assertOk()->getContent();

        $this->assertStringContainsString('CERT-BETA', $from);
        $this->assertStringContainsString('F001-222222', $from);
        $this->assertStringNotContainsString('CERT-ALFA', $from);
        $this->assertStringNotContainsString('B001-111111', $from);

        $to = $this->actingAs($viewer)->get(self::ALERTS_URL.'?date_to=2026-01-10')->assertOk()->getContent();

        $this->assertStringContainsString('CERT-ALFA', $to);
        $this->assertStringContainsString('B001-111111', $to);
        $this->assertStringNotContainsString('CERT-BETA', $to);
        $this->assertStringNotContainsString('F001-222222', $to);
    }

    public function test_an_invalid_filter_value_does_not_break_the_screen_and_is_reported(): void
    {
        $viewer = $this->viewer(['course-talks.view']);
        $this->filterScenario();

        // An unknown status, a non-numeric id and an array where a scalar is
        // expected: none of them may reach a query as an array, and none of them
        // may end as a 500.
        $content = $this->actingAs($viewer)
            ->get(self::ALERTS_URL.'?delivery_status=no_existe&edition_id=abc&channel[]=mail&date_from=ayer')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('course-talks-alerts-filters-invalid', $content);
        $this->assertStringContainsString('course-talks-alerts-empty', $content);
        $this->assertStringNotContainsString('CERT-ALFA', $content);
        $this->assertStringNotContainsString('B001-111111', $content);
    }

    // ---------------------------------------------------------------------
    // E — the discard action
    // ---------------------------------------------------------------------

    public function test_discarding_requires_a_reason_records_it_and_leaves_the_document_valid(): void
    {
        $manager = $this->manager();
        $academic = $this->pendingAcademic(['code' => 'CERT-DISCARD-A', 'issue_date' => '2026-01-05']);
        $filePath = 'course-academic-documents/'.$academic->course_enrollment_id.'/CERT-DISCARD-A.pdf';
        $this->privateFile($academic, $filePath, '%PDF certificate');
        $tokenHash = $academic->fresh()->qr_token_hash;
        $discardUrl = self::ALERTS_URL.'/academic-documents/'.$academic->id.'/discard';

        $this->actingAs($manager)->post($discardUrl, [])->assertSessionHasErrors('alerts');
        $this->actingAs($manager)->post($discardUrl, ['reason' => '   '])->assertSessionHasErrors('alerts');

        // Nothing was closed by a reason-less request, and the follow-up is still
        // on the screen.
        $this->assertSame(DeliveryStatus::Pending, $academic->fresh()->delivery_status);
        $this->assertNull($academic->fresh()->delivery_discard_reason);
        $this->assertStringContainsString('CERT-DISCARD-A', $this->actingAs($manager)->get(self::ALERTS_URL)->assertOk()->getContent());

        $this->actingAs($manager)
            ->post($discardUrl, ['reason' => 'El docente entregó el certificado en mano'])
            ->assertRedirect(self::ALERTS_URL);

        $fresh = $academic->fresh();
        $this->assertSame(DeliveryStatus::Discarded, $fresh->delivery_status);
        $this->assertSame('El docente entregó el certificado en mano', $fresh->delivery_discard_reason);
        $this->assertSame(0, (new CourseAlertService())->pendingCount());

        // The document itself is untouched: it is still current, its QR path was
        // not revoked and its private file is still there.
        $this->assertSame(AcademicDocumentStatus::Current, $fresh->status);
        $this->assertNull($fresh->qr_token_revoked_at);
        $this->assertSame($tokenHash, $fresh->qr_token_hash);
        $this->assertTrue(Storage::disk('docs')->exists($filePath));
        $this->assertStringNotContainsString('CERT-DISCARD-A', $this->actingAs($manager)->get(self::ALERTS_URL)->assertOk()->getContent());
    }

    public function test_a_user_without_the_send_ability_sees_no_discard_control_and_gets_403(): void
    {
        $reader = $this->viewer(['course-talks.view']);
        $academic = $this->pendingAcademic(['code' => 'CERT-FORBID-A', 'issue_date' => '2026-01-05']);
        $this->pendingCommercial(['series' => 'B001', 'number' => '900004', 'issue_date' => '2026-01-05']);

        $content = $this->actingAs($reader)->get(self::ALERTS_URL)->assertOk()->getContent();

        // The read surface is open to the module's read ability, but no discard
        // control is offered to somebody who cannot use it.
        $this->assertStringContainsString('CERT-FORBID-A', $content);
        $this->assertStringNotContainsString('course-talks-alert-discard-form-', $content);
        $this->assertStringNotContainsString('Descartar', $content);

        $this->actingAs($reader)
            ->post(self::ALERTS_URL.'/academic-documents/'.$academic->id.'/discard', ['reason' => 'Motivo válido'])
            ->assertForbidden();

        $this->assertSame(DeliveryStatus::Pending, $academic->fresh()->delivery_status);
        $this->assertNull($academic->fresh()->delivery_discard_reason);
    }

    public function test_guests_and_users_without_the_module_permission_cannot_reach_the_alert_screen(): void
    {
        $this->pendingAcademic(['code' => 'CERT-GUARD-A', 'issue_date' => '2026-01-05']);

        $this->get(self::ALERTS_URL)->assertRedirect('/login');

        $outsider = $this->viewer();
        $content = $this->actingAs($outsider)->get(self::ALERTS_URL)->assertForbidden();

        $this->assertStringNotContainsString('CERT-GUARD-A', $content->getContent());
    }

    // ---------------------------------------------------------------------
    // B — the group comprobante path
    // ---------------------------------------------------------------------

    public function test_a_group_comprobante_shows_its_edition_activity_responsible_and_payer(): void
    {
        $manager = $this->manager();
        $responsible = User::factory()->create(['is_active' => true, 'name' => 'Greta Grupal']);
        $activity = CourseActivity::factory()->create(['type' => CourseActivityType::Talk, 'name' => 'Charla Grupal']);
        $edition = CourseEdition::factory()->for($activity, 'activity')->create(['code' => 'ED-GRUPAL', 'responsible_user_id' => $responsible->id]);
        $group = CourseEnrollmentGroup::factory()->for($edition, 'edition')->create(['payer_name' => 'Empresa Pagadora SAC']);

        $this->pendingCommercial([
            'course_enrollment_id' => null,
            'course_enrollment_group_id' => $group->id,
            'series' => 'F001',
            'number' => '900005',
            'payer_name' => 'Empresa Pagadora SAC',
            'issue_date' => '2026-01-05',
        ]);

        $content = $this->actingAs($manager)->get(self::ALERTS_URL)->assertOk()->getContent();

        $this->assertStringContainsString('F001-900005', $content);
        $this->assertStringContainsString('Charla Grupal', $content);
        $this->assertStringContainsString('ED-GRUPAL', $content);
        $this->assertStringContainsString('Greta Grupal', $content);
        $this->assertStringContainsString('Empresa Pagadora SAC', $content);

        // The edition filter reaches a group comprobante through its group, not
        // through an enrollment.
        $filtered = $this->actingAs($manager)->get(self::ALERTS_URL.'?edition_id='.$edition->id)->assertOk()->getContent();
        $this->assertStringContainsString('F001-900005', $filtered);

        $other = $this->actingAs($manager)->get(self::ALERTS_URL.'?edition_id='.($edition->id + 5000))->assertOk()->getContent();
        $this->assertStringNotContainsString('F001-900005', $other);
    }

    // ---------------------------------------------------------------------
    // Triangulation — the seams the screens depend on
    // ---------------------------------------------------------------------

    public function test_the_filterable_query_still_obeys_the_outstanding_predicate(): void
    {
        $viewer = $this->viewer(['course-talks.view']);
        $pending = $this->pendingAcademic(['code' => 'CERT-OUT-PENDING', 'issue_date' => '2026-01-05', 'delivery_status' => DeliveryStatus::Failed]);
        $this->academicDocument(['code' => 'CERT-OUT-SENT', 'issue_date' => '2026-01-05', 'delivery_status' => DeliveryStatus::Sent]);
        $this->academicDocument(['code' => 'CERT-OUT-DISCARDED', 'issue_date' => '2026-01-05', 'delivery_status' => DeliveryStatus::Discarded]);

        $alerts = new CourseAlertService();

        // Filtering narrows the outstanding set, it does not widen it: a closed
        // follow-up cannot be brought back by a filter, not even one that names
        // its own status.
        $this->assertSame([], $alerts->outstandingAcademicDocuments(['delivery_status' => 'sent'])->pluck('id')->all());
        $this->assertSame([], $alerts->outstandingAcademicDocuments(['delivery_status' => 'discarded'])->pluck('id')->all());
        $this->assertSame([$pending->id], $alerts->outstandingAcademicDocuments(['delivery_status' => 'failed'])->pluck('id')->all());
        $this->assertSame([$pending->id], $alerts->outstandingAcademicDocuments()->pluck('id')->all());

        // A value the domain cannot compare (an array) is not a filter: it must be
        // ignored instead of reaching a query.
        $this->assertSame(
            [$pending->id],
            $alerts->outstandingAcademicDocuments(['channel' => ['mail'], 'edition_id' => null])->pluck('id')->all(),
        );

        // And the screen shows exactly that set.
        $content = $this->actingAs($viewer)->get(self::ALERTS_URL)->assertOk()->getContent();
        $this->assertStringContainsString('CERT-OUT-PENDING', $content);
        $this->assertStringNotContainsString('CERT-OUT-SENT', $content);
        $this->assertStringNotContainsString('CERT-OUT-DISCARDED', $content);
    }

    public function test_combined_filters_intersect(): void
    {
        $viewer = $this->viewer(['course-talks.view']);
        $this->filterScenario();

        // Each filter alone still allows two documents; together they leave one.
        $this->assertStringContainsString(
            'CERT-ALFA',
            $this->actingAs($viewer)->get(self::ALERTS_URL.'?activity_type=course')->assertOk()->getContent(),
        );

        $content = $this->actingAs($viewer)
            ->get(self::ALERTS_URL.'?activity_type=course&document_type=boleta')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('B001-111111', $content);
        $this->assertStringNotContainsString('CERT-ALFA', $content);
        $this->assertStringNotContainsString('CERT-BETA', $content);
        $this->assertStringNotContainsString('F001-222222', $content);
    }

    public function test_the_commercial_discard_route_resolves_the_comprobante_without_touching_it(): void
    {
        $manager = $this->manager();
        $commercial = $this->pendingCommercial(['series' => 'B001', 'number' => '900006', 'issue_date' => '2026-01-05']);
        $filePath = 'course-commercial-documents/'.$commercial->id.'/B001-900006.pdf';
        $this->privateFile($commercial, $filePath, '%PDF comprobante');
        $this->assertSame(1, (new CourseAlertService())->pendingCount());

        $this->actingAs($manager)
            ->post(self::ALERTS_URL.'/commercial-documents/'.$commercial->id.'/discard', ['reason' => 'Se entregó en oficina'])
            ->assertRedirect(self::ALERTS_URL);

        $fresh = $commercial->fresh();
        $this->assertSame(DeliveryStatus::Discarded, $fresh->delivery_status);
        $this->assertSame('Se entregó en oficina', $fresh->delivery_discard_reason);
        $this->assertSame(0, (new CourseAlertService())->pendingCount());

        // The comprobante itself is untouched and still streamable.
        $this->assertSame('registered', $fresh->status);
        $this->assertSame('118.00', $fresh->total_amount);
        $this->assertTrue(Storage::disk('docs')->exists($filePath));
        $this->assertTrue(
            (new CourseDocumentDeliveryService(static fn (): bool => true))
                ->hasStreamableCommercialDocument($fresh),
        );
    }

    public function test_a_reason_posted_as_an_array_is_refused_without_a_server_error(): void
    {
        $manager = $this->manager();
        $academic = $this->pendingAcademic(['code' => 'CERT-ARRAY-A', 'issue_date' => '2026-01-05']);

        $this->actingAs($manager)
            ->post(self::ALERTS_URL.'/academic-documents/'.$academic->id.'/discard', ['reason' => ['motivo']])
            ->assertRedirect(self::ALERTS_URL)
            ->assertSessionHasErrors('alerts');

        $this->assertSame(DeliveryStatus::Pending, $academic->fresh()->delivery_status);
        $this->assertNull($academic->fresh()->delivery_discard_reason);
    }

    // ---------------------------------------------------------------------
    // Fixtures and helpers
    // ---------------------------------------------------------------------

    /**
     * The four follow-ups the filter tests narrow: two editions (a course and a
     * talk), two participants, two responsibles, one academic and one commercial
     * document per edition, each with its own last-attempt channel and anchor
     * date.
     *
     * @return array<string, mixed>
     */
    private function filterScenario(): array
    {
        $responsibleA = User::factory()->create(['is_active' => true, 'name' => 'Rita Alfa']);
        $responsibleB = User::factory()->create(['is_active' => true, 'name' => 'Beto Beta']);

        $activityA = CourseActivity::factory()->create(['type' => CourseActivityType::Course, 'name' => 'Actividad Alfa']);
        $activityB = CourseActivity::factory()->create(['type' => CourseActivityType::Talk, 'name' => 'Actividad Beta']);

        $editionA = CourseEdition::factory()->for($activityA, 'activity')->create(['code' => 'ED-ALFA', 'responsible_user_id' => $responsibleA->id]);
        $editionB = CourseEdition::factory()->for($activityB, 'activity')->create(['code' => 'ED-BETA', 'responsible_user_id' => $responsibleB->id]);

        $participantA = CourseParticipant::factory()->create(['first_name' => 'Ana', 'last_name' => 'Alfa']);
        $participantB = CourseParticipant::factory()->create(['first_name' => 'Bruno', 'last_name' => 'Beta']);

        $enrollmentA = CourseEnrollment::factory()->for($editionA, 'edition')->for($participantA, 'participant')->create();
        $enrollmentB = CourseEnrollment::factory()->for($editionB, 'edition')->for($participantB, 'participant')->create();

        $this->academicDocument([
            'course_enrollment_id' => $enrollmentA->id,
            'code' => 'CERT-ALFA',
            'issue_date' => '2026-01-05',
            'delivery_status' => DeliveryStatus::Pending,
        ]);
        $this->academicDocument([
            'course_enrollment_id' => $enrollmentB->id,
            'code' => 'CERT-BETA',
            'issue_date' => '2026-01-20',
            'type' => AcademicDocumentType::ParticipationConstancy,
            'delivery_status' => DeliveryStatus::Failed,
        ]);

        $docC = $this->commercialDocument([
            'course_enrollment_id' => $enrollmentA->id,
            'series' => 'B001',
            'number' => '111111',
            'issue_date' => '2026-01-06',
            'type' => CommercialDocumentType::Boleta,
            'delivery_status' => DeliveryStatus::Pending,
        ]);
        $this->deliveryAttempt($docC, OutboundDelivery::CHANNEL_WHATSAPP, 'queued');
        $this->deliveryAttempt($docC, OutboundDelivery::CHANNEL_MAIL, 'sent');

        $docD = $this->commercialDocument([
            'course_enrollment_id' => $enrollmentB->id,
            'series' => 'F001',
            'number' => '222222',
            'issue_date' => '2026-01-21',
            'type' => CommercialDocumentType::Factura,
            'delivery_status' => DeliveryStatus::Failed,
        ]);
        $this->deliveryAttempt($docD, OutboundDelivery::CHANNEL_MAIL, 'failed');
        $this->deliveryAttempt($docD, OutboundDelivery::CHANNEL_WHATSAPP, 'queued');

        return [
            'editionA' => $editionA,
            'editionB' => $editionB,
            'participantA' => $participantA,
            'participantB' => $participantB,
            'responsibleA' => $responsibleA,
            'responsibleB' => $responsibleB,
            'docA' => CourseAcademicDocument::query()->where('code', 'CERT-ALFA')->firstOrFail(),
            'docB' => CourseAcademicDocument::query()->where('code', 'CERT-BETA')->firstOrFail(),
            'docC' => $docC,
            'docD' => $docD,
        ];
    }

    private function assertAllScenarioDocumentsVisible(User $viewer): void
    {
        $content = $this->actingAs($viewer)->get(self::ALERTS_URL)->assertOk()->getContent();

        foreach (['CERT-ALFA', 'CERT-BETA', 'B001-111111', 'F001-222222'] as $reference) {
            $this->assertStringContainsString($reference, $content, 'The unfiltered alert list must show '.$reference.'.');
        }
    }

    /**
     * @param  list<string>  $permissions
     */
    private function viewer(array $permissions = []): User
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    private function manager(): User
    {
        return $this->viewer(['course-talks.view', 'course-talks.documents.send']);
    }

    /** @param array<string, mixed> $attributes */
    private function pendingAcademic(array $attributes = []): CourseAcademicDocument
    {
        return $this->academicDocument(array_merge([
            'code' => 'CERT-'.strtoupper(str()->random(8)),
            'issue_date' => '2026-01-05',
            'delivery_status' => DeliveryStatus::Pending,
        ], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    private function academicDocument(array $attributes = []): CourseAcademicDocument
    {
        return CourseAcademicDocument::query()->create(array_merge([
            'course_enrollment_id' => $this->enrollment()->id,
            'type' => AcademicDocumentType::ApprovalCertificate,
            'status' => AcademicDocumentStatus::Current,
            'code' => 'CERT-'.strtoupper(str()->random(8)),
            'issue_date' => '2026-01-05',
            'delivery_status' => DeliveryStatus::Pending,
        ], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    private function pendingCommercial(array $attributes = []): CourseCommercialDocument
    {
        return $this->commercialDocument(array_merge([
            'series' => 'B001',
            'number' => (string) random_int(100000, 999999),
            'issue_date' => '2026-01-05',
            'delivery_status' => DeliveryStatus::Pending,
        ], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    private function commercialDocument(array $attributes = []): CourseCommercialDocument
    {
        $attributes = array_merge([
            'type' => CommercialDocumentType::Boleta,
            'series' => 'B001',
            'number' => (string) random_int(100000, 999999),
            'issue_date' => '2026-01-05',
            'subtotal_amount' => '100.00',
            'igv_rate' => '0.1800',
            'igv_amount' => '18.00',
            'total_amount' => '118.00',
            'payer_name' => 'Pagador de prueba',
            'status' => 'registered',
            'delivery_status' => DeliveryStatus::Pending,
        ], $attributes);

        if (! array_key_exists('course_enrollment_id', $attributes) && ! array_key_exists('course_enrollment_group_id', $attributes)) {
            $attributes['course_enrollment_id'] = $this->enrollment()->id;
        }

        return CourseCommercialDocument::query()->create($attributes);
    }

    private function enrollment(): CourseEnrollment
    {
        return CourseEnrollment::factory()->create();
    }

    private function deliveryAttempt(CourseAcademicDocument|CourseCommercialDocument $document, string $channel, string $status): OutboundDelivery
    {
        return OutboundDelivery::query()->create([
            'channel' => $channel,
            'recipient_ref' => $channel === OutboundDelivery::CHANNEL_MAIL ? 'destino@example.test' : '51999999999',
            'related_entity_type' => $document::class,
            'related_entity_id' => $document->id,
            'status' => $status,
            'attempts' => 1,
            'idempotency_key' => 'alert-'.str()->uuid(),
        ]);
    }

    /**
     * Attach a real private file to the document, mirroring what the generation
     * and upload paths persist, and return the stored QR token hash so a test can
     * prove the screen never renders it.
     */
    private function privateFile(CourseAcademicDocument|CourseCommercialDocument $document, string $path, string $contents): string
    {
        Storage::disk('docs')->put($path, $contents, ['visibility' => 'private']);

        $file = Document::query()->create([
            'docable_type' => $document::class,
            'docable_id' => $document->id,
            'name' => basename($path),
            'disk' => 'docs',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => strlen($contents),
            'uploaded_by' => User::factory()->create()->id,
            'uploaded_at' => now(),
        ]);

        $hash = hash_hmac('sha256', 'alert-raw-token-'.$document->id, (string) config('app.key'));

        // Only an academic document carries a QR token hash; the comprobante has
        // no QR column.
        if ($document instanceof CourseAcademicDocument) {
            $document->forceFill(['document_id' => $file->id, 'qr_token_hash' => $hash])->save();
        } else {
            $document->forceFill(['document_id' => $file->id])->save();
        }

        return $hash;
    }

    /**
     * Read a rendered counter out of the HTML by its data-testid, exactly like
     * DashboardHttpTest does for the KPI cards. Returns -1 when the anchor is
     * missing, so a missing counter can never compare equal to a real zero.
     */
    private function counter(string $html, string $testId): int
    {
        if (preg_match('/data-testid="'.preg_quote($testId, '/').'"[^>]*>\s*([0-9]+)/', $html, $matches) === 1) {
            return (int) $matches[1];
        }

        return -1;
    }

    private function screenCounts(User $viewer, string $testId): int
    {
        $content = $this->actingAs($viewer)->get(self::ALERTS_URL)->assertOk()->getContent();

        return $this->counter($content, $testId);
    }
}
