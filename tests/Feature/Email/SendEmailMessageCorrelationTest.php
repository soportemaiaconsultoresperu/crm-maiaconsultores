<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Contracts\Email\EmailProvider;
use App\Contracts\Email\EmailProviderFactory;
use App\Enums\Courses\AcademicDocumentStatus;
use App\Enums\Courses\AcademicDocumentType;
use App\Enums\Courses\DeliveryStatus;
use App\Jobs\V2\SendEmailMessage;
use App\Models\Courses\CourseAcademicDocument;
use App\Models\Courses\CourseEnrollment;
use App\Models\Email\EmailMessage;
use App\Models\IntegrationAccount;
use App\Models\Notification\OutboundDelivery;
use App\Services\QuotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Mockery;
use Tests\TestCase;

class SendEmailMessageCorrelationTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_send_marks_only_the_correlated_delivery_and_document_sent(): void
    {
        $academic = CourseAcademicDocument::query()->create([
            'course_enrollment_id' => CourseEnrollment::factory()->create()->id,
            'type' => AcademicDocumentType::ApprovalCertificate,
            'status' => AcademicDocumentStatus::Current,
            'code' => 'CERT-CORRELATION',
            'delivery_status' => DeliveryStatus::Pending,
        ]);
        $message = EmailMessage::query()->create([
            'account_id' => IntegrationAccount::query()->create([
                'provider' => 'smtp',
                'label' => 'Correlation SMTP',
                'is_shared' => true,
                'is_active' => true,
                'test_mode' => true,
            ])->id,
            'direction' => EmailMessage::DIRECTION_OUTBOUND,
            'provider_message_id' => 'local-correlation',
            'from_email' => 'sender@example.test',
            'subject' => 'Document',
            'status' => EmailMessage::STATUS_QUEUED,
        ]);
        $delivery = OutboundDelivery::query()->create([
            'channel' => OutboundDelivery::CHANNEL_MAIL,
            'recipient_ref' => 'recipient@example.test',
            'related_entity_type' => CourseAcademicDocument::class,
            'related_entity_id' => $academic->id,
            'email_message_id' => $message->id,
            'status' => OutboundDelivery::STATUS_QUEUED,
            'attempts' => 1,
            'idempotency_key' => str()->random(64),
        ]);
        $provider = Mockery::mock(EmailProvider::class);
        $provider->shouldReceive('send')->once()->andReturn(['ok' => true, 'provider_message_id' => 'provider-1']);
        $factory = Mockery::mock(EmailProviderFactory::class);
        $factory->shouldReceive('for')->once()->andReturn($provider);

        (new SendEmailMessage($message->id))->handle($factory, app(QuotationService::class));

        $this->assertSame(OutboundDelivery::STATUS_SENT, $delivery->fresh()->status);
        $this->assertSame(DeliveryStatus::Sent, $academic->fresh()->delivery_status);
        $this->assertNotNull($academic->fresh()->last_sent_at);
    }

    public function test_unconfirmed_send_keeps_the_correlated_document_pending_with_a_sanitized_non_terminal_ledger_error(): void
    {
        $academic = CourseAcademicDocument::query()->create([
            'course_enrollment_id' => CourseEnrollment::factory()->create()->id,
            'type' => AcademicDocumentType::ApprovalCertificate,
            'status' => AcademicDocumentStatus::Current,
            'code' => 'CERT-UNCONFIRMED',
            'delivery_status' => DeliveryStatus::Pending,
        ]);
        $account = IntegrationAccount::query()->create(['provider' => 'smtp', 'label' => 'Unconfirmed SMTP', 'is_shared' => true, 'is_active' => true, 'test_mode' => true]);
        $message = EmailMessage::query()->create(['account_id' => $account->id, 'direction' => EmailMessage::DIRECTION_OUTBOUND, 'provider_message_id' => 'local-unconfirmed', 'from_email' => 'sender@example.test', 'status' => EmailMessage::STATUS_QUEUED]);
        $delivery = OutboundDelivery::query()->create(['channel' => OutboundDelivery::CHANNEL_MAIL, 'recipient_ref' => 'recipient@example.test', 'related_entity_type' => CourseAcademicDocument::class, 'related_entity_id' => $academic->id, 'email_message_id' => $message->id, 'status' => OutboundDelivery::STATUS_QUEUED, 'attempts' => 1, 'idempotency_key' => str()->random(64)]);
        $provider = Mockery::mock(EmailProvider::class);
        $provider->shouldReceive('send')->once()->andReturn(['ok' => false, 'indeterminate' => true, 'error_message' => 'provider secret']);
        $factory = Mockery::mock(EmailProviderFactory::class);
        $factory->shouldReceive('for')->once()->andReturn($provider);

        (new SendEmailMessage($message->id))->handle($factory, app(QuotationService::class));

        $this->assertSame(EmailMessage::STATUS_SEND_UNCONFIRMED, $message->fresh()->status);
        $this->assertSame('No se pudo confirmar el envío del correo.', $message->fresh()->error_message);
        $this->assertStringNotContainsString('provider secret', (string) $message->fresh()->error_message);
        $this->assertSame(OutboundDelivery::STATUS_QUEUED, $delivery->fresh()->status);
        $this->assertSame('No fue posible confirmar el envío del correo.', $delivery->fresh()->last_error);
        $this->assertSame(DeliveryStatus::Pending, $academic->fresh()->delivery_status);
        $this->assertNull($academic->fresh()->last_sent_at);
    }

    public function test_an_email_message_cannot_be_correlated_to_multiple_deliveries(): void
    {
        $message = EmailMessage::query()->create([
            'direction' => EmailMessage::DIRECTION_OUTBOUND,
            'provider_message_id' => 'local-one-to-one',
            'from_email' => 'sender@example.test',
            'status' => EmailMessage::STATUS_QUEUED,
        ]);
        $attributes = [
            'channel' => OutboundDelivery::CHANNEL_MAIL,
            'recipient_ref' => 'recipient@example.test',
            'email_message_id' => $message->id,
            'status' => OutboundDelivery::STATUS_QUEUED,
            'attempts' => 1,
        ];
        OutboundDelivery::query()->create($attributes + ['idempotency_key' => str()->random(64)]);

        $this->expectException(QueryException::class);
        OutboundDelivery::query()->create($attributes + ['idempotency_key' => str()->random(64)]);
    }

    public function test_failed_provider_message_is_sanitized_before_persistence(): void
    {
        $account = IntegrationAccount::query()->create(['provider' => 'smtp', 'label' => 'Failure SMTP', 'is_shared' => true, 'is_active' => true, 'test_mode' => true]);
        $message = EmailMessage::query()->create(['account_id' => $account->id, 'direction' => EmailMessage::DIRECTION_OUTBOUND, 'provider_message_id' => 'local-failure', 'from_email' => 'sender@example.test', 'status' => EmailMessage::STATUS_QUEUED]);
        $provider = Mockery::mock(EmailProvider::class);
        $provider->shouldReceive('send')->once()->andReturn(['ok' => false, 'error_class' => 'SmtpError', 'error_message' => 'password=provider-secret']);
        $factory = Mockery::mock(EmailProviderFactory::class);
        $factory->shouldReceive('for')->once()->andReturn($provider);

        (new SendEmailMessage($message->id))->handle($factory, app(QuotationService::class));

        $this->assertSame(EmailMessage::STATUS_FAILED, $message->fresh()->status);
        $this->assertSame('No fue posible enviar el correo.', $message->fresh()->error_message);
        $this->assertStringNotContainsString('provider-secret', (string) $message->fresh()->error_message);
    }
}
