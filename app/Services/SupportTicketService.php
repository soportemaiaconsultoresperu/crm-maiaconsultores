<?php

namespace App\Services;

use App\Exceptions\InvalidOperationException;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\SupportAssignment;
use App\Models\SupportCategory;
use App\Models\SupportChannel;
use App\Models\SupportPriority;
use App\Models\SupportStatus;
use App\Models\SupportTicket;
use App\Models\SupportTicketType;
use App\Models\SupportTicketUpdate;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupportTicketService
{
    /** Descriptive fields editable through updateDetails(); status, customer and responsible have their own flows. */
    public const EDITABLE_FIELDS = ['title', 'description', 'impact', 'general_observations', 'type_id', 'category_id', 'channel_id'];

    /** States where priority and details can no longer change. */
    private const FROZEN_STATES = [SupportStatus::SLUG_CLOSED, SupportStatus::SLUG_CANCELLED];

    public const INACTIVE_TEAM_MESSAGE = 'El equipo seleccionado no existe o no está activo.';

    public function __construct(private readonly CodeGeneratorService $codes, private readonly SupportTicketLifecycleService $lifecycle) {}

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

    /**
     * Nuevo/Reabierto move to Asignado through the lifecycle; other open states
     * keep their status and only record the (re)assignment history.
     */
    public function assign(SupportTicket $ticket, User $newResponsible, User $actor, ?int $teamId = null, ?string $reason = null): SupportTicket
    {
        if (! User::query()->whereKey($newResponsible->id)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['responsible_id' => 'El responsable debe ser un usuario activo.']);
        }

        if ($teamId !== null && ! Team::query()->whereKey($teamId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['team_id' => self::INACTIVE_TEAM_MESSAGE]);
        }

        return DB::transaction(function () use ($ticket, $newResponsible, $actor, $teamId, $reason): SupportTicket {
            $ticket = $this->lifecycle->lockTicket($ticket);
            $statusSlug = $ticket->status?->slug;

            if (in_array($statusSlug, [SupportStatus::SLUG_RESOLVED, SupportStatus::SLUG_CLOSED, SupportStatus::SLUG_CANCELLED], true)) {
                throw new InvalidOperationException('No se puede asignar un ticket resuelto, cerrado o cancelado.');
            }

            $movesToAssigned = in_array($statusSlug, [SupportStatus::SLUG_NEW, SupportStatus::SLUG_REOPENED], true);

            if (! $movesToAssigned && (int) $ticket->responsible_id === $newResponsible->id && $ticket->team_id === $teamId) {
                return $ticket;
            }

            $wasAssigned = $ticket->responsible_id !== null;

            if ($wasAssigned && trim((string) $reason) === '') {
                throw ValidationException::withMessages([
                    'reason' => 'El motivo de reasignación es obligatorio.',
                ]);
            }

            $previousResponsibleId = $ticket->responsible_id;
            $previousTeamId = $ticket->team_id;

            $ticket->responsible_id = $newResponsible->id;
            $ticket->team_id = $teamId;
            if ($ticket->assigned_at === null) {
                $ticket->assigned_at = now();
            }
            $ticket->updated_by = $actor->id;
            $ticket->save();

            if ($movesToAssigned) {
                $ticket = $this->lifecycle->transition($ticket, SupportStatus::SLUG_ASSIGNED, $actor);
            }

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

    /**
     * Starts the attention; a new unassigned ticket is first assigned to the actor.
     * Both steps share one transaction, so a refused start leaves the ticket untouched.
     */
    public function startAttention(SupportTicket $ticket, User $actor): SupportTicket
    {
        return DB::transaction(function () use ($ticket, $actor): SupportTicket {
            $this->selfAssignIfNew($ticket, $actor, 'Inicio de atención por el responsable');

            return $this->lifecycle->transition($ticket, SupportStatus::SLUG_IN_PROGRESS, $actor);
        });
    }

    /**
     * Schedules a session; a new unassigned ticket is first assigned to the actor, atomically.
     *
     * @param  array<string, mixed>  $activityData
     * @param  array<string, mixed>  $sessionData
     */
    public function scheduleAttention(SupportTicket $ticket, array $activityData, array $sessionData, User $actor): Activity
    {
        return DB::transaction(function () use ($ticket, $activityData, $sessionData, $actor): Activity {
            $this->selfAssignIfNew($ticket, $actor, 'Programación de atención por el responsable');

            return $this->lifecycle->schedule($ticket, $activityData, $sessionData, $actor);
        });
    }

    public function cancel(SupportTicket $ticket, User $actor, string $reason): SupportTicket
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'El motivo de cancelación es obligatorio.']);
        }

        return DB::transaction(function () use ($ticket, $actor, $reason): SupportTicket {
            $ticket = $this->lifecycle->lockTicket($ticket);
            $oldStatus = $ticket->status?->slug;

            if ($oldStatus === SupportStatus::SLUG_CANCELLED) {
                return $ticket;
            }

            if (! in_array(SupportStatus::SLUG_CANCELLED, SupportTicketLifecycleService::allowedTransitions($oldStatus), true)) {
                throw new InvalidOperationException('El ticket no admite cancelación desde su estado actual.');
            }

            $ticket = $this->lifecycle->transition($ticket, SupportStatus::SLUG_CANCELLED, $actor, $reason);

            $this->log($ticket, $actor, 'support-ticket-cancelled', 'Ticket de soporte cancelado', [
                'old_status_slug' => $oldStatus,
                'new_status_slug' => SupportStatus::SLUG_CANCELLED,
                'reason' => $reason,
            ]);

            return $ticket->refresh();
        });
    }

    public function changePriority(SupportTicket $ticket, SupportPriority|int $priority, User $actor, ?string $reason = null): SupportTicket
    {
        $this->authorize($actor, 'support.priority.update', 'No tiene permiso para cambiar la prioridad.');
        $priorityId = $priority instanceof SupportPriority ? $priority->id : $priority;

        if (! SupportPriority::query()->whereKey($priorityId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['priority_id' => 'La prioridad de soporte no está activa.']);
        }

        return DB::transaction(function () use ($ticket, $priorityId, $actor, $reason): SupportTicket {
            $ticket = $this->lifecycle->lockTicket($ticket);
            $this->assertNotFrozen($ticket, 'No se puede cambiar la prioridad de un ticket cerrado o cancelado.');

            if ((int) $ticket->priority_id === (int) $priorityId) {
                return $ticket;
            }

            $previousPriorityId = $ticket->priority_id;
            $ticket->priority_id = $priorityId;
            $ticket->updated_by = $actor->id;
            $ticket->save();

            $this->log($ticket, $actor, 'support-ticket-priority-changed', 'Prioridad del ticket de soporte actualizada', [
                'previous_priority_id' => $previousPriorityId,
                'new_priority_id' => $priorityId,
                'reason' => trim((string) $reason) ?: null,
            ]);

            return $ticket->refresh();
        });
    }

    /** @param  array<string, mixed>  $data  only EDITABLE_FIELDS are applied */
    public function updateDetails(SupportTicket $ticket, array $data, User $actor): SupportTicket
    {
        $this->authorize($actor, 'support.update', 'No tiene permiso para editar el ticket.');
        $data = array_intersect_key($data, array_flip(self::EDITABLE_FIELDS));

        foreach (['title', 'description', 'type_id', 'category_id', 'channel_id'] as $field) {
            if (array_key_exists($field, $data) && trim((string) $data[$field]) === '') {
                throw ValidationException::withMessages([$field => 'Este campo es obligatorio.']);
            }
        }

        $this->assertActiveCatalogs($data);

        return DB::transaction(function () use ($ticket, $data, $actor): SupportTicket {
            $ticket = $this->lifecycle->lockTicket($ticket);
            $this->assertNotFrozen($ticket, 'No se puede editar un ticket cerrado o cancelado.');

            $ticket->fill($data);
            $changed = array_keys($ticket->getDirty());

            if ($changed === []) {
                return $ticket;
            }

            $ticket->updated_by = $actor->id;
            $ticket->save();

            $this->log($ticket, $actor, 'support-ticket-details-updated', 'Datos del ticket de soporte actualizados', [
                'changed_fields' => $changed,
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

    private function selfAssignIfNew(SupportTicket $ticket, User $actor, string $reason): void
    {
        $locked = $this->lifecycle->lockTicket($ticket);

        if ($locked->status?->slug === SupportStatus::SLUG_NEW && $locked->responsible_id === null) {
            $this->assign($locked, $actor, $actor, $locked->team_id, $reason);
        }
    }

    private function statusBySlug(string $slug): SupportStatus
    {
        $status = SupportStatus::query()->where('slug', $slug)->first();

        if ($status === null) {
            throw ValidationException::withMessages(['status' => "El estado base [{$slug}] no está disponible."]);
        }

        return $status;
    }

    private function authorize(User $actor, string $permission, string $message): void
    {
        if (! $actor->can($permission)) {
            throw new AuthorizationException($message);
        }
    }

    private function assertNotFrozen(SupportTicket $ticket, string $message): void
    {
        if (in_array($ticket->status?->slug, self::FROZEN_STATES, true)) {
            throw new InvalidOperationException($message);
        }
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
            if (! array_key_exists($field, $data)) {
                continue;
            }

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
