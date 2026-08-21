<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Jobs\V2\SendEmailMessage;
use App\Models\Email\EmailMessage;
use App\Models\Email\EmailParticipant;
use App\Models\Email\EmailTemplate;
use App\Models\IntegrationAccount;
use App\Models\User;
use App\Services\Email\EmailService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * B13 Pasada B — EmailService happy-path send + transaction rollback.
 */
class EmailServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_send_persists_email_message_and_dispatches_send_job(): void
    {
        Bus::fake();

        $account = IntegrationAccount::create([
            'provider' => 'smtp',
            'label' => 'Default SMTP',
            'owner_id' => null,
            'is_shared' => true,
            'is_active' => true,
            'test_mode' => true,
        ]);

        $template = EmailTemplate::create([
            'name' => 'Bienvenida',
            'slug' => 'bienvenida',
            'subject' => 'Hola, {{ customer_name }}',
            'body_html' => '<p>Hola {{ customer_name }} — propuesta #{{ proposal_id }}</p>',
            'body_text' => 'Hola {{ customer_name }} — propuesta #{{ proposal_id }}',
            'variables_json' => ['customer_name', 'proposal_id'],
            'is_active' => true,
            'version' => 1,
            'created_by' => null,
        ]);

        $actor = User::factory()->create(['is_active' => true]);
        $service = app(EmailService::class);

        $message = $service->send(
            $template,
            ['recipient@example.com'],
            ['customer_name' => 'Acme', 'proposal_id' => 'C-7'],
            ['account_id' => $account->id],
            $actor,
        );

        $this->assertSame(EmailMessage::STATUS_QUEUED, $message->status);
        $this->assertSame(EmailMessage::DIRECTION_OUTBOUND, $message->direction);
        $this->assertStringContainsString('Hola, Acme', (string) $message->subject);
        $this->assertSame($actor->id, (int) $message->created_by);

        $this->assertDatabaseHas('email_participants', [
            'message_id' => $message->id,
            'kind' => EmailParticipant::KIND_TO,
            'email' => 'recipient@example.com',
        ]);

        Bus::assertDispatched(SendEmailMessage::class, fn ($job) => $job->messageId === $message->id);
    }

    public function test_handle_inbound_persists_message_with_received_status(): void
    {
        $service = app(EmailService::class);

        $draft = new EmailMessage([
            'direction' => EmailMessage::DIRECTION_INBOUND,
            'provider_message_id' => 'inbound-pending-1',
            'from_email' => 'sender@example.com',
            'from_name' => 'Sender',
            'subject' => 'Inbound',
            'body_html' => ['<p>Hi</p>'],
            'body_text' => ['Hi'],
        ]);

        $persisted = $service->handleInbound($draft);

        $this->assertSame(EmailMessage::STATUS_RECEIVED, $persisted->status);
        $this->assertSame(EmailMessage::DIRECTION_INBOUND, $persisted->direction);
        $this->assertNotNull($persisted->received_at);
    }

    public function test_send_rolls_back_when_rendering_throws(): void
    {
        $template = EmailTemplate::create([
            'name' => 'Roto',
            'slug' => 'roto',
            'subject' => 'Hola',
            'body_html' => '<p><?php echo 1; ?></p>',
            'body_text' => 'Hola',
            'variables_json' => [],
            'is_active' => true,
            'version' => 1,
            'created_by' => null,
        ]);

        $service = new EmailService(new \App\Services\Email\EmailTemplateRenderer([]));

        $this->expectException(\InvalidArgumentException::class);
        try {
            $service->send($template, ['recipient@example.com'], []);
        } finally {
            $this->assertSame(0, EmailMessage::query()->count());
        }
    }
}
