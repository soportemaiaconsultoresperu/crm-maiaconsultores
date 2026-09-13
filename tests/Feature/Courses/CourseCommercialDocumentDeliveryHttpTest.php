<?php

namespace Tests\Feature\Courses;

use App\Enums\Courses\CommercialDocumentType;
use App\Enums\Courses\DeliveryStatus;
use App\Models\Courses\CourseActivity;
use App\Models\Courses\CourseCommercialDocument;
use App\Models\Courses\CourseEdition;
use App\Models\Courses\CourseEnrollment;
use App\Models\Courses\CourseEnrollmentGroup;
use App\Models\Courses\CourseParticipant;
use App\Models\Customer;
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
 * Slice 6 unit 6.f-2b — delivered commercial document actions on the edition's
 * commercial documents screen: email, assisted WhatsApp handoff, manual
 * confirmation and the per-comprobante delivery history.
 *
 * The controller stays thin: `course-talks.documents.send` authorizes every
 * delivery action (the route gate, the form contract and the domain service all
 * ask for the same ability), and CourseDocumentDeliveryService owns every rule —
 * the deliverability precondition, ledger rows, idempotency keys, status
 * transitions, snapshot updates and the `wa.me` URL. This surface only decides
 * how a domain rejection is reported and what the history shows, so no rejection
 * becomes an HTTP 500.
 *
 * The email action goes through the queued path (`queueCommercialEmail()`): the
 * attempt is recorded as a ledger row correlated to a real persisted
 * `EmailMessage` carrying the comprobante's signed download link, and the
 * comprobante is NOT marked sent by the surface. `sendCommercialEmail()` (the
 * injected-closure stub that marks a document SENT without sending anything) is
 * deliberately never wired here.
 *
 * The queued transport is faked: the queued path publishes its job after the
 * enclosing transaction commits, so this class asserts the ledger and the
 * persisted message the queued path writes, not the transport outcome.
 */
class CourseCommercialDocumentDeliveryHttpTest extends TestCase
{
    use RefreshDatabase;

    private const PARTICIPANT_EMAIL = 'luz.ramos@example.test';

    private const PARTICIPANT_MOBILE = '51999888777';

    private const PAYER_EMAIL = 'pagador.empresa@example.test';

    private const PAYER_PHONE = '51988777666';

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
                'code' => 'CUR-CDEL-001',
                'name' => 'Curso de entrega comercial',
            ]), 'activity')
            ->create(['code' => 'ED-CDEL-001']);
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function enrollment(
        string $lastName = 'Ramos',
        string $documentNumber = '11111111',
        ?CourseEnrollmentGroup $group = null,
    ): CourseEnrollment {
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
            ->create(['course_enrollment_group_id' => $group?->id]);
    }

    /**
     * A group purchase whose payer is a real customer: the only place the domain
     * holds a payer email and phone, so it is the only prefill source this
     * surface may use.
     */
    private function group(): CourseEnrollmentGroup
    {
        $payer = Customer::factory()->create([
            'email' => self::PAYER_EMAIL,
            'email_norm' => self::PAYER_EMAIL,
            'phone' => self::PAYER_PHONE,
            'phone_norm' => self::PAYER_PHONE,
        ]);

        return CourseEnrollmentGroup::factory()
            ->for($this->edition, 'edition')
            ->create([
                'payer_customer_id' => $payer->id,
                'payer_name' => 'Empresa pagadora SAC',
            ]);
    }

    /**
     * A registered comprobante with its own private attachment, so it is
     * deliverable: the service refuses anything that is not registered/sent or
     * whose private file is gone.
     */
    private function commercialDocument(
        CourseEnrollment|CourseEnrollmentGroup $target,
        string $series = 'B001',
        string $number = '000123',
        bool $withFile = true,
    ): CourseCommercialDocument {
        $commercial = CourseCommercialDocument::query()->create([
            'course_enrollment_id' => $target instanceof CourseEnrollment ? $target->id : null,
            'course_enrollment_group_id' => $target instanceof CourseEnrollmentGroup ? $target->id : null,
            'type' => CommercialDocumentType::Boleta,
            'series' => $series,
            'number' => $number,
            'subtotal_amount' => '100.00',
            'igv_rate' => '0.1800',
            'igv_amount' => '18.00',
            'total_amount' => '118.00',
            'payer_name' => 'Maia Consultores SAC',
            'payer_document_number' => '20123456789',
            'status' => 'registered',
            'delivery_status' => DeliveryStatus::Pending,
        ]);

        if (! $withFile) {
            return $commercial->refresh();
        }

        $path = "course-commercial-documents/{$commercial->id}/{$series}-{$number}.pdf";
        Storage::disk('docs')->put($path, '%PDF comprobante comercial de prueba');
        $file = Document::query()->create([
            'docable_type' => CourseCommercialDocument::class,
            'docable_id' => $commercial->id,
            'name' => "{$series}-{$number}.pdf",
            'disk' => 'docs',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 35,
            'uploaded_by' => $this->manager->id,
            'uploaded_at' => now(),
        ]);
        $commercial->forceFill(['document_id' => $file->id])->save();

        return $commercial->refresh('document');
    }

    private function indexUrl(): string
    {
        return route('course-talks.commercial-documents.index', $this->edition);
    }

    private function sendEmail(
        CourseCommercialDocument $commercial,
        string $recipient,
        string $operationKey,
        ?User $actor = null,
    ): TestResponse {
        return $this->actingAs($actor ?? $this->manager)
            ->from($this->indexUrl())
            ->post(route('course-talks.commercial-documents.email', $commercial), [
                'recipient' => $recipient,
                'operation_key' => $operationKey,
            ]);
    }

    private function openWhatsApp(
        CourseCommercialDocument $commercial,
        string $phone,
        string $operationKey,
        ?User $actor = null,
    ): TestResponse {
        return $this->actingAs($actor ?? $this->manager)
            ->from($this->indexUrl())
            ->post(route('course-talks.commercial-documents.whatsapp', $commercial), [
                'recipient_phone' => $phone,
                'operation_key' => $operationKey,
            ]);
    }

    private function confirmWhatsApp(
        CourseCommercialDocument $commercial,
        int $handoffId,
        string $phone,
        string $operationKey,
        ?User $actor = null,
    ): TestResponse {
        return $this->actingAs($actor ?? $this->manager)
            ->from($this->indexUrl())
            ->post(route('course-talks.commercial-documents.whatsapp.confirm', $commercial), [
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
     * minted once per rendered form instead of on each submit.
     */
    private function renderedKey(CourseCommercialDocument $commercial, string $form): string
    {
        $html = $this->listingHtml();
        $block = $this->formBlock($html, 'course-talks-commercial-'.$form.'-form-'.$commercial->id);

        $matched = preg_match('/name="operation_key" value="([^"]+)"/', $block, $matches);
        $this->assertSame(1, $matched, "The {$form} form must carry a stable idempotency key.");

        return html_entity_decode($matches[1], ENT_QUOTES);
    }

    private function listingHtml(?User $actor = null): string
    {
        return (string) $this->actingAs($actor ?? $this->manager)->get($this->indexUrl())->assertOk()->getContent();
    }

    public function test_guests_are_redirected_to_login_from_the_commercial_delivery_routes(): void
    {
        $commercial = $this->commercialDocument($this->enrollment());

        $this->post(route('course-talks.commercial-documents.email', $commercial), [
            'recipient' => 'guest@example.test',
            'operation_key' => 'guest-email',
        ])->assertRedirect(route('login'));
        $this->post(route('course-talks.commercial-documents.whatsapp', $commercial), [
            'recipient_phone' => self::PARTICIPANT_MOBILE,
            'operation_key' => 'guest-whatsapp',
        ])->assertRedirect(route('login'));
        $this->post(route('course-talks.commercial-documents.whatsapp.confirm', $commercial), [
            'handoff' => 1,
            'recipient_phone' => self::PARTICIPANT_MOBILE,
            'operation_key' => 'guest-confirm',
        ])->assertRedirect(route('login'));

        $this->assertDatabaseCount('outbound_deliveries', 0);
        $this->assertSame(DeliveryStatus::Pending, $commercial->fresh()->delivery_status);
    }

    public function test_the_listing_offers_the_delivery_controls_prefilled_from_the_real_payer_data(): void
    {
        $enrollmentDocument = $this->commercialDocument($this->enrollment());
        $this->commercialDocument($this->group(), 'B002', '000456');

        $html = $this->listingHtml();

        // Enrollment comprobante: the participant is the real recipient the
        // domain holds. Group comprobante: the group's payer customer is.
        $this->assertStringContainsString('value="'.self::PARTICIPANT_EMAIL.'"', $html);
        $this->assertStringContainsString('value="'.self::PARTICIPANT_MOBILE.'"', $html);
        $this->assertStringContainsString('value="'.self::PAYER_EMAIL.'"', $html);
        $this->assertStringContainsString('value="'.self::PAYER_PHONE.'"', $html);

        $this->assertStringContainsString('Enviar por correo', $html);
        $this->assertStringContainsString('Abrir WhatsApp', $html);
        $this->assertStringContainsString('Historial de entregas', $html);

        // Each form carries its own key: the service refuses an existing key that
        // belongs to another channel or recipient.
        $emailKey = $this->renderedKey($enrollmentDocument, 'email');
        $whatsappKey = $this->renderedKey($enrollmentDocument, 'whatsapp');
        $this->assertNotSame('', $emailKey);
        $this->assertNotSame($emailKey, $whatsappKey);

        // No signed URL, private storage path or payer document number leaks.
        $this->assertStringNotContainsString('signature=', $html);
        $this->assertStringNotContainsString('course-commercial-documents/', $html);
    }

    public function test_email_enqueues_the_comprobante_and_records_the_recipient_override_in_the_ledger(): void
    {
        $enrollment = $this->enrollment();
        $commercial = $this->commercialDocument($enrollment);
        $key = $this->renderedKey($commercial, 'email');

        $response = $this->sendEmail($commercial, 'facturacion@example.test', $key);

        $response->assertRedirect($this->indexUrl());
        $response->assertSessionHas('status');

        $delivery = OutboundDelivery::query()->sole();
        $this->assertSame(OutboundDelivery::CHANNEL_MAIL, $delivery->channel);
        $this->assertSame(OutboundDelivery::STATUS_QUEUED, $delivery->status);
        $this->assertSame('facturacion@example.test', $delivery->recipient_ref);
        $this->assertSame(CourseCommercialDocument::class, $delivery->related_entity_type);
        $this->assertSame($commercial->id, $delivery->related_entity_id);
        $this->assertSame($key, $delivery->idempotency_key);
        $this->assertSame(1, $delivery->attempts);

        // The override reaches the transport, not only the ledger.
        $this->assertNotNull($delivery->email_message_id);
        $this->assertSame('facturacion@example.test', $delivery->emailMessage->participants()->where('kind', 'to')->value('email'));

        // The persisted message must actually carry the comprobante: a ledger row
        // and a queued status alone would let an empty email pass.
        $message = $delivery->emailMessage;
        $this->assertStringContainsString('Boleta', $message->subject);
        $this->assertStringContainsString('B001-000123', $message->subject);
        $body = $message->body_text[0];
        $this->assertStringContainsString('Hola,', $body);
        $this->assertStringContainsString('/commercial-documents/'.$commercial->id, $body);
        $this->assertStringContainsString('signature=', $body);
        $this->assertStringContainsString('/commercial-documents/'.$commercial->id, $message->body_html[0]);
        $this->assertStringNotContainsString('course-commercial-documents/', $body);
        $this->assertStringNotContainsString('20123456789', $body);

        // The queued path never marks the comprobante sent by itself.
        $commercial = $commercial->fresh();
        $this->assertSame(DeliveryStatus::Pending, $commercial->delivery_status);
        $this->assertNull($commercial->last_sent_at);
    }

    public function test_the_rendered_operation_key_makes_a_double_submit_idempotent(): void
    {
        $commercial = $this->commercialDocument($this->enrollment());
        $key = $this->renderedKey($commercial, 'email');

        $this->sendEmail($commercial, self::PARTICIPANT_EMAIL, $key)->assertRedirect($this->indexUrl());
        $this->sendEmail($commercial, self::PARTICIPANT_EMAIL, $key)->assertRedirect($this->indexUrl());

        $this->assertDatabaseCount('outbound_deliveries', 1);
        $this->assertDatabaseCount('email_messages', 1);
    }

    public function test_resending_appends_a_new_history_entry_without_touching_the_previous_one(): void
    {
        $commercial = $this->commercialDocument($this->enrollment());

        $this->sendEmail($commercial, self::PARTICIPANT_EMAIL, 'first-send-key')->assertRedirect($this->indexUrl());
        $first = OutboundDelivery::query()->sole();
        $first->forceFill(['status' => OutboundDelivery::STATUS_SENT])->save();
        $commercial->forceFill([
            'delivery_status' => DeliveryStatus::Sent,
            'last_sent_at' => now()->setTime(9, 15),
        ])->save();

        // A fresh render hands out a new key, so a resend is a new attempt
        // instead of a silent no-op.
        $this->sendEmail($commercial, self::PARTICIPANT_EMAIL, $this->renderedKey($commercial, 'email'))
            ->assertRedirect($this->indexUrl());

        $this->assertSame(2, OutboundDelivery::query()->count());
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
        $commercial = $this->commercialDocument($this->enrollment());

        OutboundDelivery::query()->create([
            'channel' => OutboundDelivery::CHANNEL_MAIL,
            'recipient_ref' => 'facturacion@example.test',
            'related_entity_type' => CourseCommercialDocument::class,
            'related_entity_id' => $commercial->id,
            'status' => OutboundDelivery::STATUS_FAILED,
            'attempts' => 2,
            'idempotency_key' => 'failed-attempt-001',
            'last_error' => 'No fue posible enviar el correo.',
        ]);
        $commercial->forceFill(['delivery_status' => DeliveryStatus::Failed])->save();

        $this->actingAs($this->manager)->get($this->indexUrl())
            ->assertOk()
            ->assertSee('Correo')
            ->assertSee('Intento fallido')
            ->assertSee('No fue posible enviar el correo.')
            ->assertSee('Intentos: 2')
            ->assertSee('facturacion@example.test');
    }

    public function test_each_comprobante_shows_only_its_own_delivery_history(): void
    {
        $first = $this->commercialDocument($this->enrollment());
        $second = $this->commercialDocument($this->enrollment('Vega', '33333333'), 'B003', '000789');

        OutboundDelivery::query()->create([
            'channel' => OutboundDelivery::CHANNEL_MAIL,
            'recipient_ref' => 'facturacion@example.test',
            'related_entity_type' => CourseCommercialDocument::class,
            'related_entity_id' => $second->id,
            'status' => OutboundDelivery::STATUS_FAILED,
            'attempts' => 1,
            'idempotency_key' => 'failed-attempt-second-comprobante',
            'last_error' => 'No fue posible enviar el correo.',
        ]);

        $html = $this->listingHtml();

        // The attempt belongs to the second comprobante only: the first keeps its
        // empty-history state and the error is never duplicated across rows.
        $this->assertStringContainsString('course-talks-commercial-delivery-none-'.$first->id, $html);
        $this->assertStringNotContainsString('course-talks-commercial-delivery-none-'.$second->id, $html);
        $this->assertSame(1, substr_count($html, 'No fue posible enviar el correo.'));
    }

    public function test_opening_the_whatsapp_handoff_creates_a_pending_entry_and_never_marks_the_comprobante_sent(): void
    {
        $commercial = $this->commercialDocument($this->enrollment());
        $key = $this->renderedKey($commercial, 'whatsapp');

        $response = $this->openWhatsApp($commercial, self::PARTICIPANT_MOBILE, $key);

        $response->assertStatus(302);
        $response->assertSessionMissing('status');
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://wa.me/'.self::PARTICIPANT_MOBILE.'?text=', $location);

        $handoff = OutboundDelivery::query()->sole();
        $this->assertSame(OutboundDelivery::CHANNEL_WHATSAPP, $handoff->channel);
        $this->assertSame(OutboundDelivery::STATUS_QUEUED, $handoff->status);
        $this->assertSame(self::PARTICIPANT_MOBILE, $handoff->recipient_ref);
        $this->assertSame($key, $handoff->idempotency_key);

        $commercial = $commercial->fresh();
        $this->assertSame(DeliveryStatus::Pending, $commercial->delivery_status);
        $this->assertNull($commercial->last_sent_at);

        // The prepared message and its temporary signed link travel only inside
        // the browser redirect, never through the rendered surface.
        $html = $this->listingHtml();
        $this->assertStringContainsString('Marcar como enviado', $html);
        $this->assertStringContainsString('WhatsApp pendiente de confirmación', $html);
        $this->assertStringNotContainsString('signature=', $html);
    }

    public function test_manual_confirmation_appends_a_sent_entry_and_then_marks_the_comprobante_sent(): void
    {
        $commercial = $this->commercialDocument($this->enrollment());

        $this->openWhatsApp($commercial, self::PARTICIPANT_MOBILE, $this->renderedKey($commercial, 'whatsapp'))
            ->assertStatus(302);
        $handoff = OutboundDelivery::query()->sole();

        $key = $this->renderedKey($commercial, 'whatsapp-confirm');
        $response = $this->confirmWhatsApp($commercial, (int) $handoff->id, self::PARTICIPANT_MOBILE, $key);

        $response->assertRedirect($this->indexUrl());
        $response->assertSessionHas('status');

        $rows = OutboundDelivery::query()->orderBy('id')->get();
        $this->assertCount(2, $rows);
        // The handoff row stays pending and untouched: the confirmation is
        // appended as a new entry before the status flips.
        $this->assertSame(OutboundDelivery::STATUS_QUEUED, $rows[0]->status);
        $this->assertSame(OutboundDelivery::STATUS_SENT, $rows[1]->status);
        $this->assertSame($key, $rows[1]->idempotency_key);
        $this->assertSame(self::PARTICIPANT_MOBILE, $rows[1]->recipient_ref);
        $this->assertTrue((int) $rows[1]->id > (int) $handoff->id);

        $commercial = $commercial->fresh();
        $this->assertSame(DeliveryStatus::Sent, $commercial->delivery_status);
        $this->assertNotNull($commercial->last_sent_at);

        $this->actingAs($this->manager)->get($this->indexUrl())->assertOk()->assertSee('Enviado');
    }

    public function test_manual_confirmation_is_refused_when_the_phone_does_not_match_the_handoff(): void
    {
        $commercial = $this->commercialDocument($this->enrollment());

        $this->openWhatsApp($commercial, self::PARTICIPANT_MOBILE, $this->renderedKey($commercial, 'whatsapp'))
            ->assertStatus(302);
        $handoff = OutboundDelivery::query()->sole();

        $response = $this->confirmWhatsApp($commercial, (int) $handoff->id, '51000000000', 'confirm-mismatch-001');

        $response->assertRedirect($this->indexUrl());
        $response->assertSessionHasErrors('commercial_document');

        $this->assertDatabaseCount('outbound_deliveries', 1);
        $commercial = $commercial->fresh();
        $this->assertSame(DeliveryStatus::Pending, $commercial->delivery_status);
        $this->assertNull($commercial->last_sent_at);

        // The rejection is visible after the redirect, never an HTTP 500.
        $this->actingAs($this->manager)->from($this->indexUrl())->followingRedirects()
            ->post(route('course-talks.commercial-documents.whatsapp.confirm', $commercial), [
                'handoff' => $handoff->id,
                'recipient_phone' => '51000000000',
                'operation_key' => 'confirm-mismatch-002',
            ])
            ->assertSee('no coincide con el handoff');
    }

    public function test_delivery_is_refused_when_the_comprobante_is_not_registered_or_its_private_file_is_gone(): void
    {
        // Not registered: the surface offers no control the service would refuse.
        $pendingFile = $this->commercialDocument($this->enrollment());
        $pendingFile->forceFill(['status' => 'pending_file'])->save();

        // Registered but its private file is gone: the service owns the refusal.
        $withoutFile = $this->commercialDocument($this->enrollment('Vega', '33333333'), 'B004', '000999', withFile: false);
        Storage::disk('docs')->delete('course-commercial-documents/'.$withoutFile->id.'/B004-000999.pdf');

        // A comprobante that really is deliverable keeps its controls, so the
        // assertions below prove an absence and not a broken listing.
        $deliverable = $this->commercialDocument($this->enrollment('Soto', '44444444'), 'B006', '001111');

        $html = $this->listingHtml();
        $this->assertStringNotContainsString('course-talks-commercial-email-form-'.$pendingFile->id, $html);
        $this->assertStringNotContainsString('course-talks-commercial-whatsapp-form-'.$pendingFile->id, $html);
        // Registered, but its private file does not exist: the service predicate
        // also requires the file, so the surface offers no control it would refuse.
        $this->assertStringNotContainsString('course-talks-commercial-email-form-'.$withoutFile->id, $html);
        $this->assertStringNotContainsString('course-talks-commercial-whatsapp-form-'.$withoutFile->id, $html);
        $this->assertStringContainsString('course-talks-commercial-email-form-'.$deliverable->id, $html);
        $this->assertStringContainsString('course-talks-commercial-whatsapp-form-'.$deliverable->id, $html);
        // Exactly one comprobante of the listing is offered the controls.
        $this->assertSame(1, substr_count($html, 'course-talks-commercial-email-form-'));

        $this->sendEmail($withoutFile, 'facturacion@example.test', 'refused-missing-file')
            ->assertSessionHasErrors('commercial_document');
        $this->openWhatsApp($withoutFile, self::PARTICIPANT_MOBILE, 'refused-missing-file-whatsapp')
            ->assertSessionHasErrors('commercial_document');

        // No attempt reaches the ledger for a comprobante that cannot be
        // delivered, and no queued message is left behind.
        $this->assertDatabaseCount('outbound_deliveries', 0);
        $this->assertDatabaseCount('email_messages', 0);
        $this->assertSame(DeliveryStatus::Pending, $withoutFile->fresh()->delivery_status);

        // The rejection is visible in Spanish, never an HTTP 500.
        $this->actingAs($this->manager)->from($this->indexUrl())->followingRedirects()
            ->post(route('course-talks.commercial-documents.email', $withoutFile), [
                'recipient' => 'facturacion@example.test',
                'operation_key' => 'refused-missing-file-visible',
            ])
            ->assertSee('Solo un comprobante registrado con su archivo privado disponible puede entregarse.');
    }

    public function test_a_group_comprobante_is_delivered_through_the_same_actions(): void
    {
        $group = $this->group();
        $commercial = $this->commercialDocument($group, 'B005', '001234');

        $this->sendEmail($commercial, self::PAYER_EMAIL, 'group-email-key')->assertRedirect($this->indexUrl());
        $this->assertDatabaseHas('outbound_deliveries', [
            'channel' => OutboundDelivery::CHANNEL_MAIL,
            'recipient_ref' => self::PAYER_EMAIL,
            'related_entity_type' => CourseCommercialDocument::class,
            'related_entity_id' => $commercial->id,
            'idempotency_key' => 'group-email-key',
        ]);

        $this->openWhatsApp($commercial, self::PAYER_PHONE, 'group-whatsapp-key')->assertStatus(302);
        $handoff = OutboundDelivery::query()->where('channel', OutboundDelivery::CHANNEL_WHATSAPP)->sole();
        $this->assertSame(OutboundDelivery::STATUS_QUEUED, $handoff->status);

        $this->confirmWhatsApp($commercial, (int) $handoff->id, self::PAYER_PHONE, 'group-confirm-key')
            ->assertRedirect($this->indexUrl());

        $this->assertDatabaseCount('outbound_deliveries', 3);
        $this->assertSame(DeliveryStatus::Sent, $commercial->fresh()->delivery_status);
    }

    public function test_the_email_form_contract_requires_a_recipient_and_the_rendered_operation_key(): void
    {
        $commercial = $this->commercialDocument($this->enrollment());

        $this->sendEmail($commercial, '', 'empty-recipient')->assertSessionHasErrors('recipient');
        $this->sendEmail($commercial, 'no-es-un-correo', 'invalid-recipient')->assertSessionHasErrors('recipient');

        $this->actingAs($this->manager)->from($this->indexUrl())
            ->post(route('course-talks.commercial-documents.email', $commercial), [
                'recipient' => 'facturacion@example.test',
                'operation_key' => '',
            ])
            ->assertSessionHasErrors('operation_key');

        $this->assertDatabaseCount('outbound_deliveries', 0);
    }

    public function test_all_delivery_actions_are_denied_without_the_send_permission(): void
    {
        $viewer = $this->userWith(['course-talks.view']);
        $commercial = $this->commercialDocument($this->enrollment());

        $this->sendEmail($commercial, 'facturacion@example.test', 'denied-email', $viewer)->assertForbidden();
        $this->openWhatsApp($commercial, self::PARTICIPANT_MOBILE, 'denied-whatsapp', $viewer)->assertForbidden();
        $this->confirmWhatsApp($commercial, 1, self::PARTICIPANT_MOBILE, 'denied-confirm', $viewer)->assertForbidden();

        $this->assertDatabaseCount('outbound_deliveries', 0);

        // No rendered control can answer 403: the delivery controls are gated on
        // the same ability their routes require.
        $html = $this->listingHtml($viewer);
        $this->assertStringNotContainsString('Enviar por correo', $html);
        $this->assertStringNotContainsString('Abrir WhatsApp', $html);
        $this->assertStringNotContainsString('Marcar como enviado', $html);
        // The delivery history stays visible: it is read under the module
        // permission, not under the send ability.
        $this->assertStringContainsString('Sin intentos de entrega registrados', $html);
    }

    public function test_the_delivery_surface_never_leaks_signed_urls_or_the_recipient_outside_the_ledger(): void
    {
        $commercial = $this->commercialDocument($this->enrollment());

        $response = $this->sendEmail($commercial, 'facturacion@example.test', 'privacy-email-key');

        $response->assertSessionHas(
            'status',
            static fn (string $status): bool => ! str_contains($status, 'facturacion@example.test')
                && ! str_contains($status, 'signature=')
                && ! str_contains($status, 'http'),
        );

        $html = $this->listingHtml();
        // The recipient is visible only as the ledger's own recipient_ref.
        $this->assertStringContainsString('facturacion@example.test', $html);
        $this->assertStringNotContainsString('signature=', $html);
        $this->assertStringNotContainsString('course-commercial-documents/', $html);
    }

    public function test_the_history_shows_every_appended_attempt_of_one_comprobante(): void
    {
        $commercial = $this->commercialDocument($this->enrollment());

        $this->openWhatsApp($commercial, self::PARTICIPANT_MOBILE, $this->renderedKey($commercial, 'whatsapp'))
            ->assertStatus(302);
        $handoff = OutboundDelivery::query()->sole();
        $this->confirmWhatsApp($commercial, (int) $handoff->id, self::PARTICIPANT_MOBILE, $this->renderedKey($commercial, 'whatsapp-confirm'))
            ->assertRedirect($this->indexUrl());
        $confirmation = OutboundDelivery::query()->where('status', OutboundDelivery::STATUS_SENT)->sole();

        $html = $this->listingHtml();

        // Both ledger rows of the same channel are rendered with their own state:
        // the append-only history keeps the pending handoff beside the confirmed
        // delivery instead of collapsing them into one attempt.
        $this->assertStringContainsString('course-talks-commercial-delivery-'.$commercial->id.'-'.$handoff->id, $html);
        $this->assertStringContainsString('course-talks-commercial-delivery-'.$commercial->id.'-'.$confirmation->id, $html);
        $this->assertStringNotContainsString('course-talks-commercial-delivery-none-'.$commercial->id, $html);
        $this->assertStringContainsString('En cola', $html);
        $this->assertStringContainsString('Enviado', $html);
    }

    public function test_the_delivery_history_never_shows_another_edition_comprobante_attempts(): void
    {
        $this->commercialDocument($this->enrollment());

        // A comprobante of a different edition, with its own failed attempt: the
        // edition's listing is scoped to its own documents, so neither the other
        // document nor its recipient may appear here.
        $otherEdition = CourseEdition::factory()
            ->for(CourseActivity::factory()->create(['code' => 'CUR-CDEL-002']), 'activity')
            ->create(['code' => 'ED-CDEL-002']);
        $otherEnrollment = CourseEnrollment::factory()
            ->for($otherEdition, 'edition')
            ->for(CourseParticipant::factory()->create(['email' => 'otra.edicion@example.test']), 'participant')
            ->create();
        $otherCommercial = CourseCommercialDocument::query()->create([
            'course_enrollment_id' => $otherEnrollment->id,
            'type' => CommercialDocumentType::Boleta,
            'series' => 'B900',
            'number' => '000900',
            'subtotal_amount' => '100.00',
            'igv_rate' => '0.1800',
            'igv_amount' => '18.00',
            'total_amount' => '118.00',
            'payer_name' => 'Pagador ajeno',
            'status' => 'registered',
            'delivery_status' => DeliveryStatus::Failed,
        ]);
        OutboundDelivery::query()->create([
            'channel' => OutboundDelivery::CHANNEL_MAIL,
            'recipient_ref' => 'otra-edicion@example.test',
            'related_entity_type' => CourseCommercialDocument::class,
            'related_entity_id' => $otherCommercial->id,
            'status' => OutboundDelivery::STATUS_FAILED,
            'attempts' => 1,
            'idempotency_key' => 'failed-attempt-other-edition',
            'last_error' => 'No fue posible enviar el correo.',
        ]);

        $html = $this->listingHtml();

        $this->assertStringNotContainsString('otra-edicion@example.test', $html);
        $this->assertStringNotContainsString('course-talks-commercial-delivery-'.$otherCommercial->id, $html);
        $this->assertStringNotContainsString('Pagador ajeno', $html);
    }

    public function test_a_missing_commercial_document_id_is_not_found(): void
    {
        $commercial = $this->commercialDocument($this->enrollment());

        $this->actingAs($this->manager)->post(route('course-talks.commercial-documents.email', 999999), [
            'recipient' => 'facturacion@example.test',
            'operation_key' => 'missing-email',
        ])->assertNotFound();
        $this->actingAs($this->manager)->post(route('course-talks.commercial-documents.whatsapp', 999999), [
            'recipient_phone' => self::PARTICIPANT_MOBILE,
            'operation_key' => 'missing-whatsapp',
        ])->assertNotFound();
        $this->actingAs($this->manager)->post(route('course-talks.commercial-documents.whatsapp.confirm', 999999), [
            'handoff' => 1,
            'recipient_phone' => self::PARTICIPANT_MOBILE,
            'operation_key' => 'missing-confirm',
        ])->assertNotFound();

        $this->assertDatabaseCount('outbound_deliveries', 0);
        $this->assertSame('registered', $commercial->fresh()->status);
    }
}
