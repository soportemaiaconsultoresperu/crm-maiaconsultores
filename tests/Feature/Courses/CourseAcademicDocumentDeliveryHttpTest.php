<?php

namespace Tests\Feature\Courses;

use App\Enums\Courses\AcademicDocumentStatus;
use App\Enums\Courses\AcademicDocumentType;
use App\Enums\Courses\CourseActivityType;
use App\Enums\Courses\DeliveryStatus;
use App\Enums\Courses\FinalResult;
use App\Enums\Courses\PaymentStatus;
use App\Models\Courses\CourseAcademicDocument;
use App\Models\Courses\CourseActivity;
use App\Models\Courses\CourseEdition;
use App\Models\Courses\CourseEnrollment;
use App\Models\Courses\CourseParticipant;
use App\Models\Document;
use App\Models\Notification\OutboundDelivery;
use App\Models\User;
use Database\Seeders\CoursePermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Slice 6.e-2 — academic document delivery actions of one edition: email,
 * WhatsApp assisted handoff, manual confirmation and the per-document delivery
 * history.
 *
 * The controller stays thin: `course-talks.documents.send` authorizes every
 * delivery action (the route gate, the form contract and the domain service all
 * ask for the same ability), and CourseDocumentDeliveryService owns every rule —
 * ledger rows, idempotency keys, status transitions, snapshot updates and the
 * `wa.me` URL. This surface only decides how a domain rejection is reported and
 * what the history shows, so no rejection becomes an HTTP 500.
 *
 * The queued transport is faked: the queued email path publishes its job after
 * the enclosing transaction commits, so this class asserts the ledger the queued
 * path writes (channel, recipient override, idempotency key, email message) and
 * that the snapshot is NOT marked sent. The terminal `sent`/`failed` snapshots
 * are owned by the transport job and keep their own service-level coverage.
 */
class CourseAcademicDocumentDeliveryHttpTest extends TestCase
{
    use RefreshDatabase;

    private const PARTICIPANT_EMAIL = 'luz.ramos@example.test';

    private const PARTICIPANT_MOBILE = '51999888777';

    private User $manager;

    private CourseEdition $edition;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('docs');
        Queue::fake();

        $this->seed(CoursePermissionsSeeder::class);

        $this->manager = $this->userWith([
            'course-talks.view',
            'course-talks.documents.send',
        ]);

        $this->edition = CourseEdition::factory()
            ->for(CourseActivity::factory()->create([
                'type' => CourseActivityType::Course,
                'code' => 'CUR-DEL-001',
                'name' => 'Curso de entrega',
            ]), 'activity')
            ->create([
                'code' => 'ED-DEL-001',
                'validations_completed_at' => now(),
            ]);
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function enrollment(string $lastName = 'Ramos', string $documentNumber = '11111111'): CourseEnrollment
    {
        $participant = CourseParticipant::factory()->create([
            'first_name' => 'Luz',
            'last_name' => $lastName,
            'document_number' => $documentNumber,
            'document_number_norm' => $documentNumber,
            'email' => self::PARTICIPANT_EMAIL,
            'email_norm' => self::PARTICIPANT_EMAIL,
            'mobile' => self::PARTICIPANT_MOBILE,
            'mobile_norm' => self::PARTICIPANT_MOBILE,
        ]);

        return CourseEnrollment::factory()
            ->for($this->edition, 'edition')
            ->for($participant, 'participant')
            ->create([
                'payment_status' => PaymentStatus::Paid,
                'final_result' => FinalResult::Approved,
            ]);
    }

    private function currentDocument(
        CourseEnrollment $enrollment,
        string $code = 'CERT-APR-DEL-001',
        bool $withFile = true,
    ): CourseAcademicDocument {
        $document = CourseAcademicDocument::query()->create([
            'course_enrollment_id' => $enrollment->id,
            'type' => AcademicDocumentType::ApprovalCertificate,
            'status' => AcademicDocumentStatus::Current,
            'code' => $code,
            'issue_date' => now()->toDateString(),
            'filename' => 'Certificado_Luz Ramos_Curso de entrega_01-02.07.26_Maia Consultores.pdf',
            'qr_token_hash' => 'sha256-secret-hash-'.$code,
            'delivery_status' => DeliveryStatus::Pending,
        ]);

        if (! $withFile) {
            return $document->refresh();
        }

        // A real private PDF behind the row: the assisted WhatsApp handoff only
        // opens for a current non-revoked document with a streamable file.
        $path = "course-academic-documents/{$enrollment->id}/{$code}.pdf";
        Storage::disk('docs')->put($path, '%PDF documento académico de prueba');
        $file = Document::query()->create([
            'docable_type' => $document->getMorphClass(),
            'docable_id' => $document->id,
            'name' => $document->filename,
            'disk' => 'docs',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 37,
            'uploaded_by' => $this->manager->id,
            'uploaded_at' => now(),
        ]);
        $document->forceFill(['document_id' => $file->id])->save();

        return $document->refresh();
    }

    private function indexUrl(): string
    {
        return route('course-talks.documents.index', $this->edition);
    }

    private function sendEmail(
        CourseAcademicDocument $document,
        string $recipient,
        string $operationKey,
        ?User $actor = null,
    ): TestResponse {
        return $this->actingAs($actor ?? $this->manager)
            ->from($this->indexUrl())
            ->post(route('course-talks.documents.email', $document), [
                'recipient' => $recipient,
                'operation_key' => $operationKey,
            ]);
    }

    private function openWhatsApp(
        CourseAcademicDocument $document,
        string $phone,
        string $operationKey,
        ?User $actor = null,
    ): TestResponse {
        return $this->actingAs($actor ?? $this->manager)
            ->from($this->indexUrl())
            ->post(route('course-talks.documents.whatsapp', $document), [
                'recipient_phone' => $phone,
                'operation_key' => $operationKey,
            ]);
    }

    private function confirmWhatsApp(
        CourseAcademicDocument $document,
        int $handoffId,
        string $phone,
        string $operationKey,
        ?User $actor = null,
    ): TestResponse {
        return $this->actingAs($actor ?? $this->manager)
            ->from($this->indexUrl())
            ->post(route('course-talks.documents.whatsapp.confirm', $document), [
                'handoff' => $handoffId,
                'recipient_phone' => $phone,
                'operation_key' => $operationKey,
            ]);
    }

    private function formBlock(string $html, string $testId): string
    {
        $start = strpos($html, 'data-testid="'.$testId.'"');
        $this->assertNotFalse($start, "The {$testId} form must be rendered.");

        $end = strpos($html, '</form>', (int) $start);
        $this->assertNotFalse($end, "The {$testId} form must be closed.");

        return substr($html, (int) $start, (int) $end - (int) $start);
    }

    /**
     * The idempotency key is read back out of the rendered form, proving it is
     * generated once per render instead of on each submit.
     */
    private function renderedKey(CourseAcademicDocument $document, string $form): string
    {
        $html = (string) $this->actingAs($this->manager)->get($this->indexUrl())->assertOk()->getContent();
        $block = $this->formBlock($html, 'course-talks-document-'.$form.'-form-'.$document->id);

        $matched = preg_match('/name="operation_key" value="([^"]+)"/', $block, $matches);
        $this->assertSame(1, $matched, "The {$form} form must carry a stable idempotency key.");

        return html_entity_decode($matches[1], ENT_QUOTES);
    }

    private function indexHtml(): string
    {
        return (string) $this->actingAs($this->manager)->get($this->indexUrl())->assertOk()->getContent();
    }

    public function test_guests_are_redirected_to_login_from_the_delivery_routes(): void
    {
        $document = $this->currentDocument($this->enrollment());

        $this->post(route('course-talks.documents.email', $document), [
            'recipient' => 'guest@example.test',
            'operation_key' => 'guest-email',
        ])->assertRedirect(route('login'));
        $this->post(route('course-talks.documents.whatsapp', $document), [
            'recipient_phone' => self::PARTICIPANT_MOBILE,
            'operation_key' => 'guest-whatsapp',
        ])->assertRedirect(route('login'));
        $this->post(route('course-talks.documents.whatsapp.confirm', $document), [
            'handoff' => 1,
            'recipient_phone' => self::PARTICIPANT_MOBILE,
            'operation_key' => 'guest-confirm',
        ])->assertRedirect(route('login'));

        $this->assertDatabaseCount('outbound_deliveries', 0);
        $this->assertSame(DeliveryStatus::Pending, $document->fresh()->delivery_status);
    }

    public function test_the_document_list_offers_the_delivery_controls_prefilled_from_the_participant(): void
    {
        $document = $this->currentDocument($this->enrollment());

        $html = $this->indexHtml();

        $this->assertStringContainsString('value="'.self::PARTICIPANT_EMAIL.'"', $html);
        $this->assertStringContainsString('value="'.self::PARTICIPANT_MOBILE.'"', $html);
        $this->assertStringContainsString('Enviar por correo', $html);
        $this->assertStringContainsString('Abrir WhatsApp', $html);

        // Each form carries its own key: the service refuses an existing key that
        // belongs to another channel or recipient.
        $emailKey = $this->renderedKey($document, 'email');
        $whatsappKey = $this->renderedKey($document, 'whatsapp');
        $this->assertNotSame('', $emailKey);
        $this->assertNotSame($emailKey, $whatsappKey);

        // No signed URL, private storage path or raw QR material reaches the view.
        $this->assertStringNotContainsString('signature=', $html);
        $this->assertStringNotContainsString('course-academic-documents/', $html);
        $this->assertStringNotContainsString('sha256-secret-hash', $html);
    }

    public function test_email_enqueues_the_document_and_records_the_recipient_override_in_the_ledger(): void
    {
        $enrollment = $this->enrollment();
        $document = $this->currentDocument($enrollment);
        $key = $this->renderedKey($document, 'email');

        $response = $this->sendEmail($document, 'jefa@example.test', $key);

        $response->assertRedirect($this->indexUrl());
        $response->assertSessionHas('status');

        $delivery = OutboundDelivery::query()->sole();
        $this->assertSame(OutboundDelivery::CHANNEL_MAIL, $delivery->channel);
        $this->assertSame(OutboundDelivery::STATUS_QUEUED, $delivery->status);
        $this->assertSame('jefa@example.test', $delivery->recipient_ref);
        $this->assertSame(CourseAcademicDocument::class, $delivery->related_entity_type);
        $this->assertSame($document->id, $delivery->related_entity_id);
        $this->assertSame($key, $delivery->idempotency_key);
        $this->assertSame(1, $delivery->attempts);

        // The override reaches the transport, not only the ledger.
        $this->assertNotNull($delivery->email_message_id);
        $this->assertSame('jefa@example.test', $delivery->emailMessage->participants()->where('kind', 'to')->value('email'));

        // The queued path never marks the document sent by itself.
        $document = $document->fresh();
        $this->assertSame(DeliveryStatus::Pending, $document->delivery_status);
        $this->assertNull($document->last_sent_at);
    }

    public function test_the_rendered_operation_key_makes_a_double_submit_idempotent(): void
    {
        $document = $this->currentDocument($this->enrollment());
        $key = $this->renderedKey($document, 'email');

        $this->sendEmail($document, self::PARTICIPANT_EMAIL, $key)->assertRedirect($this->indexUrl());
        $this->sendEmail($document, self::PARTICIPANT_EMAIL, $key)->assertRedirect($this->indexUrl());

        $this->assertDatabaseCount('outbound_deliveries', 1);
        $this->assertDatabaseCount('email_messages', 1);
    }

    public function test_resending_appends_a_new_history_entry_without_touching_the_previous_one(): void
    {
        $enrollment = $this->enrollment();
        $document = $this->currentDocument($enrollment);

        $this->sendEmail($document, self::PARTICIPANT_EMAIL, 'first-send-key')->assertRedirect($this->indexUrl());
        $first = OutboundDelivery::query()->sole();
        $first->forceFill(['status' => OutboundDelivery::STATUS_SENT])->save();
        $document->forceFill([
            'delivery_status' => DeliveryStatus::Sent,
            'last_sent_at' => now()->setTime(9, 15),
        ])->save();

        // A fresh render hands out a new key, so a resend is a new attempt
        // instead of a silent no-op.
        $this->sendEmail($document, self::PARTICIPANT_EMAIL, $this->renderedKey($document, 'email'))
            ->assertRedirect($this->indexUrl());

        $this->assertSame(2, OutboundDelivery::query()->count());
        $this->assertSame(2, OutboundDelivery::query()->where('channel', OutboundDelivery::CHANNEL_MAIL)->count());
        // The already recorded attempt is untouched: the resend appended.
        $this->assertDatabaseHas('outbound_deliveries', [
            'id' => $first->id,
            'status' => OutboundDelivery::STATUS_SENT,
            'recipient_ref' => self::PARTICIPANT_EMAIL,
            'idempotency_key' => 'first-send-key',
        ]);

        $this->actingAs($this->manager)->get($this->indexUrl())
            ->assertOk()
            ->assertSee('Enviado')
            ->assertSee('09:15');
    }

    public function test_a_failed_delivery_stays_visible_in_the_history_with_its_error(): void
    {
        $enrollment = $this->enrollment();
        $document = $this->currentDocument($enrollment);

        OutboundDelivery::query()->create([
            'channel' => OutboundDelivery::CHANNEL_MAIL,
            'recipient_ref' => 'jefa@example.test',
            'related_entity_type' => CourseAcademicDocument::class,
            'related_entity_id' => $document->id,
            'status' => OutboundDelivery::STATUS_FAILED,
            'attempts' => 2,
            'idempotency_key' => 'failed-attempt-001',
            'last_error' => 'No fue posible enviar el correo.',
        ]);
        $document->forceFill(['delivery_status' => DeliveryStatus::Failed])->save();

        $this->actingAs($this->manager)->get($this->indexUrl())
            ->assertOk()
            ->assertSee('Correo')
            ->assertSee('Intento fallido')
            ->assertSee('No fue posible enviar el correo.')
            ->assertSee('Intentos: 2')
            ->assertSee('jefa@example.test');
    }

    public function test_each_document_shows_only_its_own_delivery_history(): void
    {
        $first = $this->currentDocument($this->enrollment(), 'CERT-APR-DEL-900');
        $second = $this->currentDocument($this->enrollment('Vega', '33333333'), 'CERT-APR-DEL-901');

        OutboundDelivery::query()->create([
            'channel' => OutboundDelivery::CHANNEL_MAIL,
            'recipient_ref' => 'jefa@example.test',
            'related_entity_type' => CourseAcademicDocument::class,
            'related_entity_id' => $second->id,
            'status' => OutboundDelivery::STATUS_FAILED,
            'attempts' => 1,
            'idempotency_key' => 'failed-attempt-second-document',
            'last_error' => 'No fue posible enviar el correo.',
        ]);

        $html = $this->indexHtml();

        // The attempt belongs to the second document only: the first one keeps
        // its empty-history state and the error is never duplicated across rows.
        $this->assertStringContainsString('course-talks-document-delivery-none-'.$first->id, $html);
        $this->assertStringNotContainsString('course-talks-document-delivery-none-'.$second->id, $html);
        $this->assertSame(1, substr_count($html, 'No fue posible enviar el correo.'));
    }

    public function test_opening_the_whatsapp_handoff_creates_a_pending_entry_and_never_marks_the_document_sent(): void
    {
        $document = $this->currentDocument($this->enrollment());
        $key = $this->renderedKey($document, 'whatsapp');

        $response = $this->openWhatsApp($document, self::PARTICIPANT_MOBILE, $key);

        $response->assertStatus(302);
        $response->assertSessionMissing('status');
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://wa.me/'.self::PARTICIPANT_MOBILE.'?text=', $location);

        $handoff = OutboundDelivery::query()->sole();
        $this->assertSame(OutboundDelivery::CHANNEL_WHATSAPP, $handoff->channel);
        $this->assertSame(OutboundDelivery::STATUS_QUEUED, $handoff->status);
        $this->assertSame(self::PARTICIPANT_MOBILE, $handoff->recipient_ref);
        $this->assertSame($key, $handoff->idempotency_key);

        $document = $document->fresh();
        $this->assertSame(DeliveryStatus::Pending, $document->delivery_status);
        $this->assertNull($document->last_sent_at);

        // The prepared message and its temporary signed link travel only inside
        // the browser redirect, never through the rendered surface.
        $html = $this->indexHtml();
        $this->assertStringContainsString('Marcar como enviado', $html);
        $this->assertStringContainsString('WhatsApp pendiente de confirmación', $html);
        $this->assertStringNotContainsString('signature=', $html);
    }

    public function test_manual_confirmation_appends_a_sent_entry_and_then_marks_the_document_sent(): void
    {
        $document = $this->currentDocument($this->enrollment());

        $this->openWhatsApp($document, self::PARTICIPANT_MOBILE, $this->renderedKey($document, 'whatsapp'))
            ->assertStatus(302);
        $handoff = OutboundDelivery::query()->sole();

        $key = $this->renderedKey($document, 'whatsapp-confirm');
        $response = $this->confirmWhatsApp($document, (int) $handoff->id, self::PARTICIPANT_MOBILE, $key);

        $response->assertRedirect($this->indexUrl());
        $response->assertSessionHas('status');

        $rows = OutboundDelivery::query()->orderBy('id')->get();
        $this->assertCount(2, $rows);
        // The handoff row stays pending and untouched: the confirmation is
        // appended as a new entry.
        $this->assertSame(OutboundDelivery::STATUS_QUEUED, $rows[0]->status);
        $this->assertSame(OutboundDelivery::STATUS_SENT, $rows[1]->status);
        $this->assertSame($key, $rows[1]->idempotency_key);
        $this->assertSame(self::PARTICIPANT_MOBILE, $rows[1]->recipient_ref);
        $this->assertTrue((int) $rows[1]->id > (int) $handoff->id);

        $document = $document->fresh();
        $this->assertSame(DeliveryStatus::Sent, $document->delivery_status);
        $this->assertNotNull($document->last_sent_at);

        $this->actingAs($this->manager)->get($this->indexUrl())->assertOk()->assertSee('Enviado');
    }

    public function test_manual_confirmation_is_refused_when_the_phone_does_not_match_the_handoff(): void
    {
        $document = $this->currentDocument($this->enrollment());

        $this->openWhatsApp($document, self::PARTICIPANT_MOBILE, $this->renderedKey($document, 'whatsapp'))
            ->assertStatus(302);
        $handoff = OutboundDelivery::query()->sole();

        $response = $this->confirmWhatsApp($document, (int) $handoff->id, '51000000000', 'confirm-mismatch-001');

        $response->assertRedirect($this->indexUrl());
        $response->assertSessionHasErrors('documents');

        $this->assertDatabaseCount('outbound_deliveries', 1);
        $document = $document->fresh();
        $this->assertSame(DeliveryStatus::Pending, $document->delivery_status);
        $this->assertNull($document->last_sent_at);

        // The rejection is visible after the redirect, never an HTTP 500.
        $this->actingAs($this->manager)->from($this->indexUrl())->followingRedirects()
            ->post(route('course-talks.documents.whatsapp.confirm', $document), [
                'handoff' => $handoff->id,
                'recipient_phone' => '51000000000',
                'operation_key' => 'confirm-mismatch-002',
            ])
            ->assertSee('no coincide con el handoff');
    }

    public function test_delivery_is_refused_when_the_document_is_not_current_or_its_private_file_is_missing(): void
    {
        $annulled = $this->currentDocument($this->enrollment(), 'CERT-APR-DEL-800');
        $annulled->forceFill([
            'status' => AcademicDocumentStatus::Annulled,
            'qr_token_revoked_at' => now(),
            'annul_reason' => 'Error en los datos del participante',
        ])->save();

        $withoutFile = $this->currentDocument($this->enrollment('Vega', '33333333'), 'CERT-APR-DEL-801', withFile: false);

        $this->sendEmail($annulled, 'jefa@example.test', 'refused-annulled-email')
            ->assertSessionHasErrors('documents');
        $this->openWhatsApp($annulled, self::PARTICIPANT_MOBILE, 'refused-annulled-whatsapp')
            ->assertSessionHasErrors('documents');
        $this->sendEmail($withoutFile, 'jefa@example.test', 'refused-missing-file')
            ->assertSessionHasErrors('documents');

        // No attempt reaches the ledger for a document that cannot be delivered.
        $this->assertDatabaseCount('outbound_deliveries', 0);
        $this->assertSame(AcademicDocumentStatus::Annulled, $annulled->fresh()->status);
        $this->assertSame(DeliveryStatus::Pending, $annulled->fresh()->delivery_status);

        // The rejection is visible, and the annulled document is not offered a
        // delivery control that the service would refuse.
        $this->actingAs($this->manager)->from($this->indexUrl())->followingRedirects()
            ->post(route('course-talks.documents.email', $annulled), [
                'recipient' => 'jefa@example.test',
                'operation_key' => 'refused-annulled-visible',
            ])
            ->assertSee('Solo un documento vigente con su archivo privado disponible puede entregarse.');

        $html = $this->indexHtml();
        $this->assertStringNotContainsString('course-talks-document-email-form-'.$annulled->id, $html);
        $this->assertStringNotContainsString('course-talks-document-whatsapp-form-'.$annulled->id, $html);
    }

    public function test_the_email_form_contract_requires_a_recipient_and_the_rendered_operation_key(): void
    {
        $document = $this->currentDocument($this->enrollment());

        $this->sendEmail($document, '', 'empty-recipient')->assertSessionHasErrors('recipient');
        $this->sendEmail($document, 'no-es-un-correo', 'invalid-recipient')->assertSessionHasErrors('recipient');

        $this->actingAs($this->manager)->from($this->indexUrl())
            ->post(route('course-talks.documents.email', $document), [
                'recipient' => 'jefa@example.test',
                'operation_key' => '',
            ])
            ->assertSessionHasErrors('operation_key');

        $this->assertDatabaseCount('outbound_deliveries', 0);
    }

    public function test_all_delivery_actions_are_denied_without_the_send_permission(): void
    {
        $viewer = $this->userWith(['course-talks.view']);
        $document = $this->currentDocument($this->enrollment());

        $this->sendEmail($document, 'jefa@example.test', 'denied-email', $viewer)->assertForbidden();
        $this->openWhatsApp($document, self::PARTICIPANT_MOBILE, 'denied-whatsapp', $viewer)->assertForbidden();
        $this->confirmWhatsApp($document, 1, self::PARTICIPANT_MOBILE, 'denied-confirm', $viewer)->assertForbidden();

        $this->assertDatabaseCount('outbound_deliveries', 0);

        // No rendered control can answer 403: the delivery controls are gated on
        // the same ability their routes require.
        $html = (string) $this->actingAs($viewer)->get($this->indexUrl())->assertOk()->getContent();
        $this->assertStringNotContainsString('Enviar por correo', $html);
        $this->assertStringNotContainsString('Abrir WhatsApp', $html);
        $this->assertStringNotContainsString('Marcar como enviado', $html);
        // The delivery history stays visible: it is read under the module
        // permission, not under the send ability.
        $this->assertStringContainsString('Sin intentos de entrega registrados', $html);
    }

    public function test_the_delivery_surface_never_leaks_signed_urls_or_the_recipient_outside_the_ledger(): void
    {
        $document = $this->currentDocument($this->enrollment());

        $response = $this->sendEmail($document, 'jefa@example.test', 'privacy-email-key');

        $response->assertSessionHas(
            'status',
            static fn (string $status): bool => ! str_contains($status, 'jefa@example.test')
                && ! str_contains($status, 'signature=')
                && ! str_contains($status, 'http'),
        );

        $html = $this->indexHtml();
        // The recipient is visible only as the ledger's own recipient_ref.
        $this->assertStringContainsString('jefa@example.test', $html);
        $this->assertStringNotContainsString('signature=', $html);
        $this->assertStringNotContainsString('course-academic-documents/', $html);
        $this->assertStringNotContainsString('sha256-secret-hash', $html);
    }

    public function test_a_missing_document_id_is_not_found(): void
    {
        $document = $this->currentDocument($this->enrollment());

        $this->actingAs($this->manager)->post(route('course-talks.documents.email', 999999), [
            'recipient' => 'jefa@example.test',
            'operation_key' => 'missing-email',
        ])->assertNotFound();
        $this->actingAs($this->manager)->post(route('course-talks.documents.whatsapp', 999999), [
            'recipient_phone' => self::PARTICIPANT_MOBILE,
            'operation_key' => 'missing-whatsapp',
        ])->assertNotFound();
        $this->actingAs($this->manager)->post(route('course-talks.documents.whatsapp.confirm', 999999), [
            'handoff' => 1,
            'recipient_phone' => self::PARTICIPANT_MOBILE,
            'operation_key' => 'missing-confirm',
        ])->assertNotFound();

        $this->assertDatabaseCount('outbound_deliveries', 0);
        $this->assertSame(AcademicDocumentStatus::Current, $document->fresh()->status);
    }
}
