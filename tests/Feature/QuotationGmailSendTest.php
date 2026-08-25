<?php

namespace Tests\Feature;

use App\Contracts\Email\EmailProviderFactory;
use App\Jobs\V2\SendEmailMessage;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Email\EmailAttachment;
use App\Models\Email\EmailMessage;
use App\Models\Email\EmailParticipant;
use App\Models\IntegrationAccount;
use App\Models\Lead;
use App\Models\Quotation;
use App\Models\Tax;
use App\Models\User;
use App\Services\QuotationService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class QuotationGmailSendTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private QuotationService $quotations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->actor = User::factory()->create(['is_active' => true, 'email' => 'asesor@example.com']);
        $this->actor->assignRole('admin');
        $this->quotations = app(QuotationService::class);
    }

    public function test_show_splits_gmail_send_and_manual_mark_actions(): void
    {
        $quotation = $this->draftQuotationForLead('cliente@example.com');

        $this->actingAs($this->actor)
            ->get('/quotations/'.$quotation->id)
            ->assertOk()
            ->assertSee('Enviar por Gmail')
            ->assertSee('Marcar como enviada')
            ->assertDontSee('> Enviar<', false);
    }

    public function test_gmail_confirm_requires_connected_user_gmail(): void
    {
        $quotation = $this->draftQuotationForLead('cliente@example.com');

        $this->actingAs($this->actor)
            ->get('/quotations/'.$quotation->id.'/gmail-confirm')
            ->assertRedirect()
            ->assertSessionHas('error', 'Conectá Gmail para enviar esta cotización desde el sistema.');
    }

    public function test_gmail_confirm_suggests_responsible_contact_and_template_without_default_copies(): void
    {
        $this->googleAccount();
        $customer = Customer::factory()->forOwner($this->actor)->create(['legal_name' => 'Empresa SAC', 'email' => null]);
        $contact = Contact::factory()->for($customer)->create([
            'first_name' => 'Ana',
            'last_name' => 'Cliente',
            'email' => 'ana@example.com',
            'is_primary' => true,
            'is_active' => true,
        ]);
        $quotation = Quotation::factory()->forCustomer($customer)->forOwner($this->actor)->create(['contact_id' => $contact->id, 'total' => 100]);

        $this->actingAs($this->actor)
            ->get('/quotations/'.$quotation->id.'/gmail-confirm')
            ->assertOk()
            ->assertSee('value="ana@example.com"', false)
            ->assertSee('Cotización '.$quotation->number.' – Empresa SAC')
            ->assertSee('Hola Ana Cliente:')
            ->assertSee('data-testid="gmail-cc"', false)
            ->assertSee('data-testid="gmail-bcc"', false)
            ->assertSee('Cotizacion-'.$quotation->number.'.pdf');
    }

    public function test_gmail_confirm_does_not_choose_ambiguous_recipient(): void
    {
        $this->googleAccount();
        $customer = Customer::factory()->forOwner($this->actor)->create(['email' => null]);
        Contact::factory()->for($customer)->create(['email' => 'uno@example.com', 'is_active' => true]);
        Contact::factory()->for($customer)->create(['email' => 'dos@example.com', 'is_active' => true]);
        $quotation = Quotation::factory()->forCustomer($customer)->forOwner($this->actor)->create();

        $this->actingAs($this->actor)
            ->get('/quotations/'.$quotation->id.'/gmail-confirm')
            ->assertOk()
            ->assertSee('data-testid="recipient-ambiguous"', false)
            ->assertSee('name="to" type="email" required class="form-control " value=""', false);
    }

    public function test_gmail_send_validates_recipient_and_connected_account(): void
    {
        $quotation = $this->draftQuotationForLead('cliente@example.com');

        $this->actingAs($this->actor)
            ->post('/quotations/'.$quotation->id.'/gmail-send', $this->gmailPayload(['to' => 'bad-email']))
            ->assertRedirect()
            ->assertSessionHas('error', 'Conectá Gmail para enviar esta cotización desde el sistema.');

        $this->googleAccount();
        $this->actingAs($this->actor)
            ->post('/quotations/'.$quotation->id.'/gmail-send', $this->gmailPayload(['to' => 'bad-email']))
            ->assertSessionHasErrors('to');
    }

    public function test_gmail_send_creates_pending_message_attachment_and_queues_job(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->googleAccount();
        $quotation = $this->draftQuotationForLead('cliente@example.com');

        $this->actingAs($this->actor)
            ->post('/quotations/'.$quotation->id.'/gmail-send', $this->gmailPayload())
            ->assertRedirect('/quotations/'.$quotation->id);

        $message = EmailMessage::query()->where('related_quotation_id', $quotation->id)->firstOrFail();
        $this->assertSame(EmailMessage::STATUS_PENDING, $message->status);
        $this->assertNotNull($message->idempotency_key);
        $this->assertSame('cliente@example.com', $message->participants()->where('kind', EmailParticipant::KIND_TO)->value('email'));
        $this->assertSame(0, $message->participants()->whereIn('kind', [EmailParticipant::KIND_CC, EmailParticipant::KIND_BCC])->count());
        $this->assertSame('Cotizacion-'.$quotation->number.'.pdf', $message->attachments()->firstOrFail()->filename);
        $this->assertSame('draft', $quotation->fresh()->status);
        Queue::assertPushed(SendEmailMessage::class);
    }

    public function test_gmail_job_success_marks_message_and_quotation_sent(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fresh', 'expires_in' => 3600, 'token_type' => 'Bearer']),
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/send' => Http::response(['id' => 'gmail-1', 'threadId' => 'thread-1'], 200),
        ]);
        $account = $this->googleAccount(['access_token' => 'expired'], now()->subMinute());
        $quotation = $this->draftQuotationForLead('cliente@example.com');
        $message = $this->pendingMessage($account, $quotation);

        (new SendEmailMessage($message->id))->handle(app(EmailProviderFactory::class), $this->quotations);

        $this->assertSame(EmailMessage::STATUS_SENT, $message->fresh()->status);
        $this->assertSame('gmail-1', $message->fresh()->provider_message_id);
        $this->assertSame('thread-1', $message->fresh()->thread_id);
        $this->assertSame('sent', $quotation->fresh()->status);
    }

    public function test_gmail_job_explicit_failure_keeps_quotation_draft(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/send' => Http::response(['error' => ['message' => 'Bad request']], 400),
        ]);
        $account = $this->googleAccount();
        $quotation = $this->draftQuotationForLead('cliente@example.com');
        $message = $this->pendingMessage($account, $quotation);

        (new SendEmailMessage($message->id))->handle(app(EmailProviderFactory::class), $this->quotations);

        $this->assertSame(EmailMessage::STATUS_FAILED, $message->fresh()->status);
        $this->assertSame('GmailApiError', $message->fresh()->error_class);
        $this->assertSame('draft', $quotation->fresh()->status);
    }

    public function test_gmail_job_indeterminate_sets_send_unconfirmed_without_marking_quotation_sent(): void
    {
        Storage::fake('local');
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));
        $account = $this->googleAccount();
        $quotation = $this->draftQuotationForLead('cliente@example.com');
        $message = $this->pendingMessage($account, $quotation);

        (new SendEmailMessage($message->id))->handle(app(EmailProviderFactory::class), $this->quotations);

        $this->assertSame(EmailMessage::STATUS_SEND_UNCONFIRMED, $message->fresh()->status);
        $this->assertSame('draft', $quotation->fresh()->status);
    }

    public function test_manual_mark_as_sent_does_not_call_gmail(): void
    {
        Http::fake();
        $quotation = $this->draftQuotationForLead('cliente@example.com');

        $this->actingAs($this->actor)
            ->post('/quotations/'.$quotation->id.'/send')
            ->assertRedirect();

        $this->assertSame('sent', $quotation->fresh()->status);
        Http::assertNothingSent();
    }

    private function draftQuotationForLead(string $email): Quotation
    {
        $lead = Lead::factory()->forOwner($this->actor)->create(['email' => $email]);

        return $this->quotations->create([
            'lead_id' => $lead->id,
            'currency_code' => 'PEN',
            'issued_at' => now()->toDateString(),
            'owner_id' => $this->actor->id,
            'items' => [[
                'description' => 'Servicio de prueba',
                'quantity' => '1',
                'unit' => 'unidad',
                'unit_price' => '100.00',
                'discount_amount' => '0.00',
                'tax_id' => Tax::where('slug', 'gravado-igv')->value('id'),
            ]],
        ], $this->actor);
    }

    /** @param array<string, mixed> $overrides */
    private function gmailPayload(array $overrides = []): array
    {
        return array_merge([
            'to' => 'cliente@example.com',
            'cc' => '',
            'bcc' => '',
            'subject' => 'Cotización de prueba',
            'body' => 'Mensaje de prueba',
        ], $overrides);
    }

    /** @param array<string, string> $credentials */
    private function googleAccount(array $credentials = ['access_token' => 'token', 'refresh_token' => 'refresh'], mixed $expiresAt = null): IntegrationAccount
    {
        return IntegrationAccount::query()->create([
            'provider' => 'google',
            'label' => 'Google Workspace — asesor@example.com',
            'owner_id' => $this->actor->id,
            'is_active' => true,
            'test_mode' => false,
            'config_json' => ['google_account_email' => 'asesor@example.com', 'services' => ['gmail' => true, 'calendar' => false], 'status' => 'connected'],
            'credentials_encrypted' => array_merge(['refresh_token' => 'refresh', 'token_type' => 'Bearer'], $credentials),
            'scopes' => ['openid', 'email', 'profile', 'https://www.googleapis.com/auth/gmail.send'],
            'expires_at' => $expiresAt ?? now()->addHour(),
        ]);
    }

    private function pendingMessage(IntegrationAccount $account, Quotation $quotation): EmailMessage
    {
        $message = EmailMessage::query()->create([
            'account_id' => $account->id,
            'direction' => EmailMessage::DIRECTION_OUTBOUND,
            'provider_message_id' => 'pending-test',
            'idempotency_key' => hash('sha256', 'test-'.$quotation->id.random_int(1, 999999)),
            'from_email' => 'asesor@example.com',
            'subject' => 'Cotización',
            'body_html' => ['Mensaje'],
            'body_text' => ['Mensaje'],
            'status' => EmailMessage::STATUS_PENDING,
            'related_quotation_id' => $quotation->id,
            'created_by' => $this->actor->id,
        ]);
        EmailParticipant::query()->create(['message_id' => $message->id, 'kind' => EmailParticipant::KIND_TO, 'email' => 'cliente@example.com']);
        Storage::disk('local')->put('email-attachments/test.pdf', "%PDF-1.4\n%");
        EmailAttachment::query()->create([
            'message_id' => $message->id,
            'filename' => 'Cotizacion-'.$quotation->number.'.pdf',
            'mime' => 'application/pdf',
            'size' => 10,
            'storage_path' => 'email-attachments/test.pdf',
            'sha256' => hash('sha256', "%PDF-1.4\n%"),
        ]);

        return $message;
    }
}
