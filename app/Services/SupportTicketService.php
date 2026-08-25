<?php

namespace App\Services;

use App\Exceptions\InvalidOperationException;
use App\Models\Contact;
use App\Models\SupportAssignment;
use App\Models\SupportCategory;
use App\Models\SupportChannel;
use App\Models\SupportPriority;
use App\Models\SupportStatus;
use App\Models\SupportTicket;
use App\Models\SupportTicketType;
use App\Models\SupportTicketUpdate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupportTicketService
{
    public function __construct(private readonly CodeGeneratorService $codes) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): SupportTicket
    {
        $this->assertCreatable($data);
        $this->assertActiveCatalogs($data);
        $this->assertRequesterBelongsToCustomer((int) $data['customer_id'], (int) $data['requester_contact_id']);

        return DB::transaction(function () use ($data, $actor): SupportTicket {
            $ticket = new SupportTicket($this->baseTicketData($data));
            $ticket->code = $this->codes->next('support_ticket');
            $ticket->status_id = $this->statusBySlug(SupportStatus::SLUG_NEW)->id;
            $ticket->responsible_id = null;
            $ticket->assigned_at = null;
            $ticket->created_by = $actor->id;
            $ticket->updated_by = $actor->id;
            $ticket->save();

            $this->log($ticket, $actor, 'support-ticket-created', 'Ticket de soporte creado', [
                'status_slug' => SupportStatus::SLUG_NEW,
            ]);

            return $ticket->refresh();
        });
    }

    public function assign(SupportTicket $ticket, User $newResponsible, User $actor, ?int $teamId = null, ?string $reason = null): SupportTicket
    {
        if ($ticket->status?->slug === SupportStatus::SLUG_CANCELLED) {
            throw new InvalidOperationException('No se puede asignar un ticket cancelado.');
        }

        $wasAssigned = $ticket->responsible_id !== null;

        if ($wasAssigned && trim((string) $reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'El motivo de reasignación es obligatorio.',
            ]);
        }

        return DB::transaction(function () use ($ticket, $newResponsible, $actor, $teamId, $reason, $wasAssigned): SupportTicket {
            $previousResponsibleId = $ticket->responsible_id;
            $previousTeamId = $ticket->team_id;

            $ticket->responsible_id = $newResponsible->id;
            $ticket->team_id = $teamId;
            $ticket->status_id = $this->statusBySlug(SupportStatus::SLUG_ASSIGNED)->id;
            if ($ticket->assigned_at === null) {
                $ticket->assigned_at = now();
            }
            $ticket->updated_by = $actor->id;
            $ticket->save();

            SupportAssignment::query()->create([
                'ticket_id' => $ticket->id,
                'previous_responsible_id' => $previousResponsibleId,
                'new_responsible_id' => $newResponsible->id,
                'previous_team_id' => $previousTeamId,
                'new_team_id' => $teamId,
                'reason' => $reason,
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
            ]);

            $this->log($ticket, $actor, $wasAssigned ? 'support-ticket-reassigned' : 'support-ticket-assigned', $wasAssigned ? 'Ticket de soporte reasignado' : 'Ticket de soporte asignado', [
                'previous_responsible_id' => $previousResponsibleId,
                'new_responsible_id' => $newResponsible->id,
                'previous_team_id' => $previousTeamId,
                'new_team_id' => $teamId,
                'reason' => $reason,
            ]);

            return $ticket->refresh();
        });
    }

    public function cancel(SupportTicket $ticket, User $actor, string $reason): SupportTicket
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'El motivo de cancelación es obligatorio.']);
        }

        if (in_array($ticket->status?->slug, [SupportStatus::SLUG_CANCELLED, SupportStatus::SLUG_CLOSED], true)) {
            throw new InvalidOperationException('El ticket no admite cancelación desde su estado actual.');
        }

        return DB::transaction(function () use ($ticket, $actor, $reason): SupportTicket {
            $oldStatus = $ticket->status?->slug;
            $ticket->status_id = $this->statusBySlug(SupportStatus::SLUG_CANCELLED)->id;
            $ticket->cancel_reason = $reason;
            $ticket->updated_by = $actor->id;
            $ticket->save();

            $this->log($ticket, $actor, 'support-ticket-cancelled', 'Ticket de soporte cancelado', [
                'old_status_slug' => $oldStatus,
                'new_status_slug' => SupportStatus::SLUG_CANCELLED,
                'reason' => $reason,
            ]);

            return $ticket->refresh();
        });
    }

    public function addInternalNote(SupportTicket $ticket, string $body, User $actor): SupportTicketUpdate
    {
        return $this->addUpdate($ticket, $body, $actor, true, false, SupportTicketUpdate::TYPE_INTERNAL_NOTE);
    }

    public function addCustomerResponse(SupportTicket $ticket, string $body, User $actor): SupportTicketUpdate
    {
        return $this->addUpdate($ticket, $body, $actor, false, true, SupportTicketUpdate::TYPE_CUSTOMER_RESPONSE);
    }

    private function addUpdate(SupportTicket $ticket, string $body, User $actor, bool $internal, bool $customerResponse, string $type): SupportTicketUpdate
    {
        $body = trim($body);
        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'La actualización es obligatoria.']);
        }

        return DB::transaction(function () use ($ticket, $body, $actor, $internal, $customerResponse, $type): SupportTicketUpdate {
            $update = SupportTicketUpdate::query()->create([
                'ticket_id' => $ticket->id,
                'type' => $type,
                'body' => $body,
                'is_internal' => $internal,
                'is_customer_response' => $customerResponse,
                'created_by' => $actor->id,
            ]);

            if ($customerResponse && ! $internal && $ticket->first_responded_at === null) {
                $ticket->first_responded_at = now();
                $ticket->updated_by = $actor->id;
                $ticket->save();
            }

            $this->log($ticket, $actor, $customerResponse ? 'support-ticket-customer-response-added' : 'support-ticket-internal-note-added', $customerResponse ? 'Respuesta al cliente registrada' : 'Nota interna de soporte registrada', [
                'update_id' => $update->id,
                'is_internal' => $internal,
                'is_customer_response' => $customerResponse,
            ]);

            return $update->refresh();
        });
    }

    private function statusBySlug(string $slug): SupportStatus
    {
        $status = SupportStatus::query()->where('slug', $slug)->first();

        if ($status === null) {
            throw ValidationException::withMessages(['status' => "El estado base [{$slug}] no está disponible."]);
        }

        return $status;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertCreatable(array $data): void
    {
        foreach (['title', 'description', 'customer_id', 'requester_contact_id', 'type_id', 'category_id', 'channel_id', 'priority_id'] as $field) {
            if (! isset($data[$field]) || trim((string) $data[$field]) === '') {
                throw ValidationException::withMessages([$field => 'Este campo es obligatorio.']);
            }
        }

        if (isset($data['responsible_id']) && $data['responsible_id'] !== null && $data['responsible_id'] !== '') {
            throw ValidationException::withMessages([
                'responsible_id' => 'Un ticket nuevo no debe tener responsable; use asignación para pasar a Asignado.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertActiveCatalogs(array $data): void
    {
        $checks = [
            'type_id' => [SupportTicketType::class, 'El tipo de ticket no está activo.'],
            'category_id' => [SupportCategory::class, 'La categoría de soporte no está activa.'],
            'channel_id' => [SupportChannel::class, 'El canal de ingreso no está activo.'],
            'priority_id' => [SupportPriority::class, 'La prioridad de soporte no está activa.'],
        ];

        foreach ($checks as $field => [$model, $message]) {
            $exists = $model::query()
                ->whereKey((int) $data[$field])
                ->where('is_active', true)
                ->exists();

            if (! $exists) {
                throw ValidationException::withMessages([$field => $message]);
            }
        }
    }

    private function assertRequesterBelongsToCustomer(int $customerId, int $contactId): void
    {
        $belongs = Contact::query()->whereKey($contactId)->where('customer_id', $customerId)->exists();

        if (! $belongs) {
            throw ValidationException::withMessages([
                'requester_contact_id' => 'El contacto solicitante no pertenece al cliente seleccionado.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function baseTicketData(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'title',
            'customer_id',
            'requester_contact_id',
            'type_id',
            'category_id',
            'channel_id',
            'priority_id',
            'team_id',
            'description',
            'impact',
            'general_observations',
        ]));
    }

    /** @param  array<string, mixed>  $properties */
    private function log(SupportTicket $ticket, User $actor, string $event, string $description, array $properties = []): void
    {
        activity()
            ->performedOn($ticket)
            ->causedBy($actor)
            ->event($event)
            ->withProperties(array_merge([
                'ticket_id' => $ticket->id,
                'code' => $ticket->code,
                'customer_id' => $ticket->customer_id,
                'responsible_id' => $ticket->responsible_id,
                'team_id' => $ticket->team_id,
                'status_slug' => $ticket->status?->slug,
            ], $properties))
            ->log($description);
    }
}
