<?php

declare(strict_types=1);

namespace App\Services\Courses;

use App\Enums\Courses\AcademicDocumentStatus;
use App\Enums\Courses\AcademicDocumentType;
use App\Enums\Courses\DeliveryStatus;
use App\Models\Courses\CourseAcademicDocument;
use App\Models\Courses\CourseCommercialDocument;
use App\Models\Email\EmailMessage;
use App\Models\Notification\OutboundDelivery;
use App\Models\User;
use App\Services\Email\EmailService;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;
use Throwable;

class CourseDocumentDeliveryService
{
    /** @var Closure(): bool */
    private Closure $mailOperation;

    /** @var Closure(): \DateTimeInterface */
    private Closure $clock;

    /**
     * The transport is injected deliberately: this foundation records email
     * attempts without coupling the ledger to a provider/job implementation.
     *
     * @param Closure(): bool $mailOperation
     * @param Closure(): \DateTimeInterface|null $clock
     */
    public function __construct(Closure $mailOperation, ?Closure $clock = null)
    {
        $this->mailOperation = $mailOperation;
        $this->clock = $clock ?? static fn (): \DateTimeInterface => now();
    }

    public function queueAcademicEmail(
        CourseAcademicDocument $academic,
        string $recipient,
        User $actor,
        string $operationKey,
        EmailService $email,
    ): OutboundDelivery {
        Gate::forUser($actor)->authorize('send', CourseAcademicDocument::class);
        $recipient = trim($recipient);
        if ($recipient === '' || $operationKey === '' || strlen($operationKey) > 64) {
            throw new InvalidArgumentException('Recipient and operation key are required.');
        }

        $existing = $this->matchingAcademicDelivery($academic, $operationKey, OutboundDelivery::CHANNEL_MAIL, $recipient);
        if ($existing !== null) {
            return $existing;
        }

        // The deliverability rule is the domain's, not the HTTP surface's: an
        // annulled, replaced or QR-revoked document — or one whose private file is
        // gone — is refused before any ledger row or queued message exists.
        // `secureAcademicDocumentUrl()` asserts exactly that rule and returns the
        // controlled temporary/read route the design allows as the email payload.
        // The emailed link is valid for days, not minutes: it is opened hours or
        // days after the message was sent.
        $documentUrl = $this->secureAcademicDocumentUrl($academic, $this->emailDocumentLinkMinutes());

        try {
            $delivery = DB::transaction(function () use ($academic, $recipient, $operationKey, $email, $actor, $documentUrl): OutboundDelivery {
                $delivery = OutboundDelivery::query()->create([
                    'channel' => OutboundDelivery::CHANNEL_MAIL,
                    'recipient_ref' => $recipient,
                    'related_entity_type' => CourseAcademicDocument::class,
                    'related_entity_id' => $academic->id,
                    'status' => OutboundDelivery::STATUS_QUEUED,
                    'attempts' => 1,
                    'idempotency_key' => $operationKey,
                ]);

                $email->send(new EmailMessage([
                    'from_email' => (string) config('mail.from.address'),
                    'from_name' => config('mail.from.name'),
                    'subject' => $this->academicEmailSubject($academic),
                    'body_html' => [$this->academicEmailBodyHtml($academic, $documentUrl)],
                    'body_text' => [$this->academicEmailBodyText($academic, $documentUrl)],
                ]), [$recipient], [], ['outbound_delivery_id' => $delivery->id], $actor);

                return $delivery;
            });
        } catch (QueryException $exception) {
            $delivery = OutboundDelivery::query()->where('idempotency_key', $operationKey)->first();
            if ($delivery === null) {
                throw $exception;
            }
        }

        return $delivery->fresh();
    }

    /**
     * @return array{delivery: OutboundDelivery, phone: string, text: string, url: string}
     */
    public function openAcademicWhatsAppHandoff(
        CourseAcademicDocument $academic,
        string $recipientPhone,
        User $actor,
        string $operationKey,
    ): array {
        Gate::forUser($actor)->authorize('send', CourseAcademicDocument::class);

        $phone = preg_replace('/\D+/', '', $recipientPhone) ?? '';
        if (strlen($phone) < 8 || strlen($phone) > 15 || $operationKey === '' || strlen($operationKey) > 64) {
            throw new InvalidArgumentException('A valid recipient phone and operation key are required.');
        }

        $delivery = $this->matchingAcademicDelivery($academic, $operationKey, OutboundDelivery::CHANNEL_WHATSAPP, $phone);
        if ($delivery === null) {
            // The deliverability rule runs before the ledger row exists, so a
            // refused handoff leaves no orphan `queued` attempt in the delivery
            // history. Replays keep returning their existing handoff.
            $this->assertDeliverableAcademicDocument($academic);

            try {
                $delivery = OutboundDelivery::query()->create([
                    'channel' => OutboundDelivery::CHANNEL_WHATSAPP,
                    'recipient_ref' => $phone,
                    'related_entity_type' => CourseAcademicDocument::class,
                    'related_entity_id' => $academic->id,
                    'status' => OutboundDelivery::STATUS_QUEUED,
                    'attempts' => 1,
                    'idempotency_key' => $operationKey,
                ]);
            } catch (QueryException $exception) {
                $delivery = $this->matchingAcademicDelivery($academic, $operationKey, OutboundDelivery::CHANNEL_WHATSAPP, $phone);
                if ($delivery === null) {
                    throw $exception;
                }
            }
        }

        $phone = $delivery->recipient_ref;
        $text = 'Hola, le escribimos de Maia Consultores. Puede descargar su documento académico aquí: '
            .$this->secureAcademicDocumentUrl($academic);

        return [
            'delivery' => $delivery->fresh(),
            'phone' => $phone,
            'text' => $text,
            'url' => 'https://wa.me/'.$phone.'?text='.rawurlencode($text),
        ];
    }

    public function secureAcademicDocumentUrl(CourseAcademicDocument $academic, int $minutes = 60): string
    {
        $this->assertDeliverableAcademicDocument($academic);

        return URL::temporarySignedRoute('certificates.documents.show', now()->addMinutes($minutes), [
            'academicDocument' => $academic->id,
        ]);
    }

    public function confirmAcademicWhatsAppSent(
        CourseAcademicDocument $academic,
        OutboundDelivery $handoff,
        string $recipientPhone,
        User $actor,
        string $operationKey,
    ): OutboundDelivery {
        Gate::forUser($actor)->authorize('send', CourseAcademicDocument::class);

        $phone = preg_replace('/\D+/', '', $recipientPhone) ?? '';
        if (strlen($phone) < 8 || strlen($phone) > 15 || $operationKey === '' || strlen($operationKey) > 64) {
            throw new InvalidArgumentException('A valid recipient phone and operation key are required.');
        }

        $existing = OutboundDelivery::query()->where('idempotency_key', $operationKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        $isMatchingHandoff = $handoff->exists
            && $handoff->channel === OutboundDelivery::CHANNEL_WHATSAPP
            && $handoff->related_entity_type === CourseAcademicDocument::class
            && $handoff->related_entity_id === $academic->id
            && $handoff->recipient_ref === $phone;
        if (! $isMatchingHandoff) {
            throw new InvalidArgumentException('A matching WhatsApp handoff is required.');
        }

        try {
            $sentAt = ($this->clock)();
            $confirmation = DB::transaction(function () use ($academic, $phone, $operationKey, $sentAt): OutboundDelivery {
                $confirmation = OutboundDelivery::query()->create([
                    'channel' => OutboundDelivery::CHANNEL_WHATSAPP,
                    'recipient_ref' => $phone,
                    'related_entity_type' => CourseAcademicDocument::class,
                    'related_entity_id' => $academic->id,
                    'status' => OutboundDelivery::STATUS_SENT,
                    'attempts' => 1,
                    'idempotency_key' => $operationKey,
                ]);
                $academic->forceFill(['delivery_status' => DeliveryStatus::Sent, 'last_sent_at' => $sentAt])->save();

                return $confirmation;
            });
        } catch (QueryException $exception) {
            $confirmation = OutboundDelivery::query()->where('idempotency_key', $operationKey)->first();
            if ($confirmation === null) {
                throw $exception;
            }
        }

        activity()
            ->performedOn($academic)
            ->causedBy($actor)
            ->event('course-document-whatsapp-confirmed')
            ->withProperties(['delivery_id' => $confirmation->id])
            ->log('Confirmación manual de entrega de documento académico por WhatsApp');

        return $confirmation->fresh();
    }

    public function sendAcademicEmail(
        CourseAcademicDocument $academic,
        string $recipient,
        User $actor,
        string $operationKey,
    ): OutboundDelivery {
        Gate::forUser($actor)->authorize('send', CourseAcademicDocument::class);

        $recipient = trim($recipient);
        if ($recipient === '' || $operationKey === '' || strlen($operationKey) > 64) {
            throw new InvalidArgumentException('Recipient and operation key are required.');
        }

        $existing = $this->matchingAcademicDelivery($academic, $operationKey, OutboundDelivery::CHANNEL_MAIL, $recipient);
        if ($existing !== null) {
            return $existing;
        }

        try {
            $delivery = DB::transaction(function () use ($academic, $recipient, $operationKey): OutboundDelivery {
                return OutboundDelivery::query()->create([
                    'channel' => OutboundDelivery::CHANNEL_MAIL,
                    'recipient_ref' => $recipient,
                    'related_entity_type' => CourseAcademicDocument::class,
                    'related_entity_id' => $academic->id,
                    'status' => OutboundDelivery::STATUS_SENDING,
                    'attempts' => 1,
                    'idempotency_key' => $operationKey,
                ]);
            });
        } catch (QueryException $exception) {
            $delivery = OutboundDelivery::query()->where('idempotency_key', $operationKey)->first();
            if ($delivery === null) {
                throw $exception;
            }

            return $delivery;
        }

        try {
            if (($this->mailOperation)() !== true) {
                throw new \RuntimeException('Mail operation did not confirm delivery.');
            }

            $sentAt = ($this->clock)();
            DB::transaction(function () use ($delivery, $academic, $sentAt): void {
                $delivery->forceFill([
                    'status' => OutboundDelivery::STATUS_SENT,
                    'last_error' => null,
                ])->save();
                $academic->forceFill([
                    'delivery_status' => DeliveryStatus::Sent,
                    'last_sent_at' => $sentAt,
                ])->save();
            });

            activity()
                ->performedOn($academic)
                ->causedBy($actor)
                ->event('course-document-email-sent')
                ->withProperties(['delivery_id' => $delivery->id])
                ->log('Entrega de documento académico por correo registrada');
        } catch (Throwable) {
            DB::transaction(function () use ($delivery, $academic): void {
                $delivery->forceFill([
                    'status' => OutboundDelivery::STATUS_FAILED,
                    'last_error' => 'No fue posible enviar el correo.',
                ])->save();
                $academic->forceFill(['delivery_status' => DeliveryStatus::Failed])->save();
            });

            activity()
                ->performedOn($academic)
                ->causedBy($actor)
                ->event('course-document-email-failed')
                ->withProperties(['delivery_id' => $delivery->id])
                ->log('Intento de entrega de documento académico por correo falló');
        }

        return $delivery->fresh();
    }

    public function sendCommercialEmail(CourseCommercialDocument $commercial, string $recipient, User $actor, string $operationKey): OutboundDelivery
    {
        $recipient = strtolower(trim($recipient));
        $this->authorizeEnrollmentCommercialDelivery($commercial, $actor, $recipient, $operationKey);
        $existing = $this->matchingCommercialDelivery($commercial, $operationKey, OutboundDelivery::CHANNEL_MAIL, $recipient);
        if ($existing !== null) {
            return $existing;
        }

        $delivery = OutboundDelivery::query()->create([
            'channel' => OutboundDelivery::CHANNEL_MAIL,
            'recipient_ref' => $recipient,
            'related_entity_type' => CourseCommercialDocument::class,
            'related_entity_id' => $commercial->id,
            'status' => OutboundDelivery::STATUS_SENDING,
            'attempts' => 1,
            'idempotency_key' => $operationKey,
        ]);

        try {
            if (($this->mailOperation)() !== true) {
                throw new \RuntimeException('Mail operation did not confirm delivery.');
            }
            $sentAt = ($this->clock)();
            DB::transaction(function () use ($delivery, $commercial, $sentAt): void {
                $delivery->forceFill(['status' => OutboundDelivery::STATUS_SENT, 'last_error' => null])->save();
                $commercial->forceFill(['delivery_status' => DeliveryStatus::Sent, 'last_sent_at' => $sentAt])->save();
            });
        } catch (Throwable) {
            DB::transaction(function () use ($delivery, $commercial): void {
                $delivery->forceFill(['status' => OutboundDelivery::STATUS_FAILED, 'last_error' => 'No fue posible enviar el correo.'])->save();
                $commercial->forceFill(['delivery_status' => DeliveryStatus::Failed])->save();
            });
        }

        return $delivery->fresh();
    }

    /** @return array{delivery: OutboundDelivery, phone: string, text: string, url: string} */
    public function openCommercialWhatsAppHandoff(CourseCommercialDocument $commercial, string $recipientPhone, User $actor, string $operationKey): array
    {
        $phone = preg_replace('/\D+/', '', $recipientPhone) ?? '';
        $this->authorizeEnrollmentCommercialDelivery($commercial, $actor, $phone, $operationKey, true);
        $documentUrl = $this->secureCommercialDocumentUrl($commercial);
        $delivery = $this->matchingCommercialDelivery($commercial, $operationKey, OutboundDelivery::CHANNEL_WHATSAPP, $phone)
            ?? OutboundDelivery::query()->create([
                'channel' => OutboundDelivery::CHANNEL_WHATSAPP,
                'recipient_ref' => $phone,
                'related_entity_type' => CourseCommercialDocument::class,
                'related_entity_id' => $commercial->id,
                'status' => OutboundDelivery::STATUS_QUEUED,
                'attempts' => 1,
                'idempotency_key' => $operationKey,
            ]);
        $text = 'Hola, le escribimos de Maia Consultores. Puede descargar su comprobante aquí: '
            .$documentUrl;

        return ['delivery' => $delivery->fresh(), 'phone' => $delivery->recipient_ref, 'text' => $text, 'url' => 'https://wa.me/'.$delivery->recipient_ref.'?text='.rawurlencode($text)];
    }

    public function secureCommercialDocumentUrl(CourseCommercialDocument $commercial, int $minutes = 60): string
    {
        $commercial->loadMissing('document');
        if (! $this->hasStreamableCommercialDocument($commercial)) {
            throw new InvalidArgumentException('A registered private commercial document is required.');
        }

        return URL::temporarySignedRoute('commercial-documents.documents.show', now()->addMinutes($minutes), [
            'commercialDocument' => $commercial->id,
        ]);
    }

    public function confirmCommercialWhatsAppSent(CourseCommercialDocument $commercial, OutboundDelivery $handoff, string $recipientPhone, User $actor, string $operationKey): OutboundDelivery
    {
        $phone = preg_replace('/\D+/', '', $recipientPhone) ?? '';
        $this->authorizeEnrollmentCommercialDelivery($commercial, $actor, $phone, $operationKey, true);
        $existing = $this->matchingCommercialDelivery($commercial, $operationKey, OutboundDelivery::CHANNEL_WHATSAPP, $phone);
        if ($existing !== null) {
            return $existing;
        }
        if (! $handoff->exists || $handoff->channel !== OutboundDelivery::CHANNEL_WHATSAPP || $handoff->related_entity_type !== CourseCommercialDocument::class || $handoff->related_entity_id !== $commercial->id || $handoff->recipient_ref !== $phone) {
            throw new InvalidArgumentException('A matching WhatsApp handoff is required.');
        }

        $sentAt = ($this->clock)();
        $confirmation = DB::transaction(function () use ($commercial, $phone, $operationKey, $sentAt): OutboundDelivery {
            $confirmation = OutboundDelivery::query()->create(['channel' => OutboundDelivery::CHANNEL_WHATSAPP, 'recipient_ref' => $phone, 'related_entity_type' => CourseCommercialDocument::class, 'related_entity_id' => $commercial->id, 'status' => OutboundDelivery::STATUS_SENT, 'attempts' => 1, 'idempotency_key' => $operationKey]);
            $commercial->forceFill(['delivery_status' => DeliveryStatus::Sent, 'last_sent_at' => $sentAt])->save();
            return $confirmation;
        });

        activity()->performedOn($commercial)->causedBy($actor)->event('course-commercial-document-whatsapp-confirmed')->withProperties(['delivery_id' => $confirmation->id])->log('Confirmación manual de entrega de comprobante por WhatsApp');

        return $confirmation->fresh();
    }

    private function matchingAcademicDelivery(CourseAcademicDocument $academic, string $operationKey, string $channel, string $recipient): ?OutboundDelivery
    {
        return $this->matchingDelivery($operationKey, CourseAcademicDocument::class, (int) $academic->id, $channel, $recipient);
    }

    private function matchingCommercialDelivery(CourseCommercialDocument $commercial, string $operationKey, string $channel, string $recipient): ?OutboundDelivery
    {
        return $this->matchingDelivery($operationKey, CourseCommercialDocument::class, (int) $commercial->id, $channel, $recipient);
    }

    private function matchingDelivery(string $operationKey, string $entityType, int $entityId, string $channel, string $recipient): ?OutboundDelivery
    {
        $delivery = OutboundDelivery::query()->where('idempotency_key', $operationKey)->first();
        if ($delivery === null) {
            return null;
        }

        if ($delivery->related_entity_type !== $entityType
            || (int) $delivery->related_entity_id !== $entityId
            || $delivery->channel !== $channel
            || $delivery->recipient_ref !== $recipient) {
            throw new InvalidArgumentException('Invalid delivery operation.');
        }

        return $delivery;
    }

    private function authorizeEnrollmentCommercialDelivery(CourseCommercialDocument $commercial, User $actor, string $recipient, string $operationKey, bool $phone = false): void
    {
        Gate::forUser($actor)->authorize('send', CourseCommercialDocument::class);
        $isLinkedToOneCommercialTarget = (($commercial->course_enrollment_id !== null) xor ($commercial->course_enrollment_group_id !== null));
        if (! $isLinkedToOneCommercialTarget || $recipient === '' || $operationKey === '' || strlen($operationKey) > 64 || ($phone && (strlen($recipient) < 8 || strlen($recipient) > 15))) {
            throw new InvalidArgumentException('A commercial document, valid recipient, and operation key are required.');
        }
    }

    private function hasStreamableCommercialDocument(CourseCommercialDocument $commercial): bool
    {
        return $commercial->document !== null
            && in_array($commercial->status, ['registered', 'sent'], true)
            && $commercial->document->docable_type === CourseCommercialDocument::class
            && (int) $commercial->document->docable_id === (int) $commercial->id
            && Storage::disk($commercial->document->disk)->exists($commercial->document->path);
    }

    /**
     * The single deliverability predicate for an academic document: current
     * status, QR not revoked and its private file actually present on the
     * configured disk. Every entry point (email, WhatsApp handoff and the signed
     * URL builder) reads the rule from here, so it lives in exactly one place.
     */
    private function hasDeliverableAcademicDocument(CourseAcademicDocument $academic): bool
    {
        $academic->loadMissing('document');

        return $academic->document !== null
            && $academic->status === AcademicDocumentStatus::Current
            && $academic->qr_token_revoked_at === null
            && Storage::disk($academic->document->disk)->exists($academic->document->path);
    }

    private function assertDeliverableAcademicDocument(CourseAcademicDocument $academic): void
    {
        if (! $this->hasDeliverableAcademicDocument($academic)) {
            throw new InvalidArgumentException('A current non-revoked private document is required.');
        }
    }

    /**
     * An emailed link is opened hours or days after the message was sent, so the
     * WhatsApp 60-minute window would be broken for email. The default lives in
     * `config/courses.php` next to the other course defaults; the route keeps
     * re-validating document currency and revocation, so a live link still stops
     * serving a document that was annulled or replaced afterwards.
     */
    private function emailDocumentLinkMinutes(): int
    {
        return max(1, (int) config('courses.email_document_link_minutes', 10080));
    }

    private function academicDocumentTypeLabel(CourseAcademicDocument $academic): string
    {
        return match ($academic->type) {
            AcademicDocumentType::ApprovalCertificate => 'Certificado de aprobación',
            AcademicDocumentType::ParticipationConstancy => 'Constancia de participación',
            AcademicDocumentType::TalkCertificate => 'Certificado de charla',
        };
    }

    private function academicEmailSubject(CourseAcademicDocument $academic): string
    {
        return 'Documento académico: '.$this->academicDocumentTypeLabel($academic).' ('.$academic->code.')';
    }

    /**
     * Person-readable Spanish message carrying the controlled temporary/read
     * route. It never includes the raw QR token, a private storage path or
     * unrelated PII.
     */
    private function academicEmailBodyText(CourseAcademicDocument $academic, string $documentUrl): string
    {
        return implode("\n", [
            'Hola,',
            '',
            'Le compartimos por correo su documento académico: '.$this->academicDocumentTypeLabel($academic).' (código '.$academic->code.').',
            '',
            'Puede descargarlo de forma segura desde el siguiente enlace:',
            $documentUrl,
            '',
            'Por seguridad, el enlace de descarga tiene vigencia limitada. Si ya venció o necesita una nueva copia, responda a este correo y se lo enviaremos nuevamente.',
            '',
            'Atentamente,',
            'Maia Consultores',
        ]);
    }

    private function academicEmailBodyHtml(CourseAcademicDocument $academic, string $documentUrl): string
    {
        return nl2br((string) e($this->academicEmailBodyText($academic, $documentUrl)));
    }
}
