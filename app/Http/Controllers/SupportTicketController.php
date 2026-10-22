<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidOperationException;
use App\Http\Requests\{SupportTicketStoreRequest, SupportTicketUpdateRequest};
use App\Models\{Activity, ActivityType, Document, Contact, Customer, SupportCategory, SupportChannel, SupportObservation, SupportPriority, SupportReschedule, SupportSessionDetail, SupportStatus, SupportTicket, SupportTicketType, Team, User};
use App\Services\{SupportTicketLifecycleService, SupportTicketScopeService, SupportTicketService};
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SupportTicketController extends Controller
{
    public function __construct(private readonly SupportTicketService $tickets, private readonly SupportTicketScopeService $scope, private readonly SupportTicketLifecycleService $lifecycle) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', SupportTicket::class);
        $query = $this->scope->apply(SupportTicket::query(), $request->user())->with(['customer:id,code,legal_name,trade_name', 'requester:id,first_name,last_name,email', 'type:id,name', 'priority:id,name,color', 'status:id,name,slug', 'responsible:id,name', 'team:id,name'])->latest();
        foreach (['status_id', 'priority_id'] as $filter) if ($request->filled($filter)) $query->where($filter, (int) $request->query($filter));
        if ($request->boolean('mine')) $query->where('responsible_id', $request->user()->id);
        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            // `%` and `_` typed by the user are literal: escaped with '!' (works on SQLite and MySQL).
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(fn ($q) => $q->whereRaw("code LIKE ? ESCAPE '!'", [$like])->orWhereRaw("title LIKE ? ESCAPE '!'", [$like])
                ->orWhereHas('customer', fn ($c) => $c->whereRaw("code LIKE ? ESCAPE '!'", [$like])->orWhereRaw("legal_name LIKE ? ESCAPE '!'", [$like])->orWhereRaw("trade_name LIKE ? ESCAPE '!'", [$like])));
        }
        $pageSize = (int) \App\Models\Setting::query()->where('key', 'pagination_size')->value('value');
        return view('support.tickets.index', ['tickets' => $query->paginate(max(1, $pageSize ?: 25))->withQueryString(), 'statuses' => SupportStatus::query()->orderBy('sort')->get(), 'priorities' => SupportPriority::query()->orderBy('sort')->get(), 'filters' => $request->only(['status_id', 'priority_id', 'q', 'mine'])]);
    }

    public function create(Request $request): View { Gate::authorize('create', SupportTicket::class); return view('support.tickets.create', $this->formData($request->user())); }
    public function store(SupportTicketStoreRequest $request): RedirectResponse { $ticket = $this->tickets->create($request->validated(), $request->user()); return redirect()->route('support.tickets.show', $ticket)->with('status', 'Ticket de soporte creado.'); }

    public function show(SupportTicket $ticket): View
    {
        Gate::authorize('view', $ticket);
        $ticket->load(['customer', 'requester', 'type', 'category', 'channel', 'priority', 'status', 'responsible', 'team', 'assignments' => fn ($q) => $q->orderBy('assigned_at')->orderBy('id'), 'statusPeriods' => fn ($q) => $q->orderBy('started_at')->orderBy('id'), 'updates.author', 'documents', 'activities.type', 'sessionDetails.participants', 'sessionDetails.documents', 'incidentDetail.documents' => fn ($q) => $q->orderByDesc('uploaded_at'), 'observations.documents', 'observations.histories']);
        $reschedules = SupportReschedule::query()->where('ticket_id', $ticket->id)->with('activity:id,title')->orderBy('created_at')->orderBy('id')->get();
        $userIds = $ticket->assignments->flatMap(fn ($a) => [$a->previous_responsible_id, $a->new_responsible_id, $a->assigned_by])
            ->merge($reschedules->flatMap(fn ($r) => [$r->rescheduled_by, $r->responsible_id]))
            ->merge($ticket->observations->pluck('responsible_id'))->filter()->unique();
        $teamIds = $ticket->assignments->flatMap(fn ($a) => [$a->previous_team_id, $a->new_team_id])->filter()->unique();

        return view('support.tickets.show', [
            'ticket' => $ticket,
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(['id','name']),
            'teams' => Team::query()->where('is_active', true)->orderBy('name')->get(['id','name']),
            'activityTypes' => ActivityType::query()->where('is_active', true)->orderBy('name')->get(['id','name']),
            'priorities' => SupportPriority::query()->active()->orderBy('sort')->get(['id','name']),
            'reschedules' => $reschedules,
            'userNames' => User::query()->whereIn('id', $userIds)->pluck('name', 'id'),
            'teamNames' => Team::query()->whereIn('id', $teamIds)->pluck('name', 'id'),
            'statusNames' => SupportStatus::query()->pluck('name', 'id'),
            'allowed' => SupportTicketLifecycleService::allowedTransitions($ticket->status?->slug),
        ]);
    }

    public function edit(SupportTicket $ticket): View|RedirectResponse
    {
        Gate::authorize('update', $ticket);
        if (in_array($ticket->status?->slug, [SupportStatus::SLUG_CLOSED, SupportStatus::SLUG_CANCELLED], true)) {
            return redirect()->route('support.tickets.show', $ticket)->with('error', 'No se puede editar un ticket cerrado o cancelado.');
        }

        return view('support.tickets.edit', ['ticket' => $ticket, 'types' => SupportTicketType::query()->active()->orderBy('sort')->get(), 'categories' => SupportCategory::query()->active()->orderBy('sort')->get(), 'channels' => SupportChannel::query()->active()->orderBy('sort')->get()]);
    }

    public function update(SupportTicket $ticket, SupportTicketUpdateRequest $request): RedirectResponse
    {
        Gate::authorize('view', $ticket);

        try {
            $this->tickets->updateDetails($ticket, $request->validated(), $request->user());
        } catch (InvalidOperationException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->route('support.tickets.show', $ticket)->with('status', 'Ticket actualizado.');
    }

    public function changePriority(SupportTicket $ticket, Request $request): RedirectResponse
    {
        $this->authorizeAction($request, $ticket, 'support.priority.update');
        $data = $request->validateWithBag('priority', [
            'priority_id' => ['required', 'integer', Rule::exists('support_priorities', 'id')->where('is_active', true)],
            'reason' => ['nullable', 'string', 'max:10000'],
        ], [
            'priority_id.required' => 'Seleccione una prioridad.',
            'priority_id.exists' => 'La prioridad de soporte no está activa.',
        ], ['reason' => 'motivo']);

        return $this->attempt(fn () => $this->tickets->changePriority($ticket, (int) $data['priority_id'], $request->user(), $data['reason'] ?? null), 'Prioridad actualizada.', 'priority');
    }

    public function hold(SupportTicket $ticket, Request $request): RedirectResponse
    {
        $this->authorizeAction($request, $ticket, 'support.attention.start');
        $data = $request->validateWithBag('hold', [
            'kind' => ['required', Rule::in(array_keys(SupportTicketLifecycleService::HOLD_STATES))],
            'reason' => ['nullable', 'string', 'max:10000'],
        ], [
            'kind.required' => 'Seleccione el tipo de espera: cliente o interna.',
            'kind.in' => 'Seleccione el tipo de espera: cliente o interna.',
        ], ['reason' => 'motivo de la espera']);

        return $this->attempt(fn () => $this->lifecycle->putOnHold($ticket, $data['kind'], $request->user(), $data['reason'] ?? null), $data['kind'] === 'cliente' ? 'Ticket en espera del cliente.' : 'Ticket en espera interna.', 'hold');
    }

    public function resume(SupportTicket $ticket, Request $request): RedirectResponse
    {
        $this->authorizeAction($request, $ticket, 'support.attention.start');

        return $this->attempt(fn () => $this->lifecycle->resumeFromHold($ticket, $request->user()), 'Atención reanudada.');
    }

    public function assign(SupportTicket $ticket, Request $request): RedirectResponse
    {
        Gate::authorize($ticket->responsible_id === null ? 'assign' : 'reassign', $ticket);

        $wasAssigned = $ticket->responsible_id !== null;

        $data = $request->validateWithBag('assign', [
            'responsible_id' => ['required', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'team_id' => ['nullable', 'integer', Rule::exists('teams', 'id')->where('is_active', true)],
            'reason' => [$wasAssigned ? 'required' : 'nullable', 'string', 'max:10000'],
        ], [
            'reason.required' => 'El motivo de reasignación es obligatorio.',
            'responsible_id.required' => 'Seleccione un responsable.',
            'responsible_id.exists' => 'El responsable debe ser un usuario activo.',
            'team_id.exists' => SupportTicketService::INACTIVE_TEAM_MESSAGE,
        ], ['reason' => 'motivo', 'team_id' => 'equipo', 'responsible_id' => 'responsable']);

        $responsible = User::query()->findOrFail((int) $data['responsible_id']);

        return $this->attempt(fn () => $this->tickets->assign(
            $ticket,
            $responsible,
            $request->user(),
            isset($data['team_id']) ? (int) $data['team_id'] : null,
            $data['reason'] ?? null,
        ), $wasAssigned ? 'Responsable reasignado.' : 'Responsable asignado.', 'assign');
    }

    public function start(SupportTicket $ticket, Request $request): RedirectResponse
    {
        $this->authorizeAction($request, $ticket, 'support.attention.start');

        if ($ticket->status?->slug === SupportStatus::SLUG_IN_PROGRESS) {
            return redirect()->back()->with('info', 'La atención ya estaba en marcha; no se realizó ningún cambio.');
        }

        // A new unassigned ticket is self-assigned (support.attention.start suffices), atomically with the start.
        return $this->attempt(fn () => $this->tickets->startAttention($ticket, $request->user()), 'Atención iniciada.');
    }
    public function resolve(SupportTicket $ticket, Request $request): RedirectResponse { $this->authorizeAction($request, $ticket, 'support.resolve'); $data=$request->validateWithBag('resolve', ['solution_summary'=>['required','string','max:10000']], [], ['solution_summary'=>'resumen de solución']); return $this->attempt(fn () => $this->lifecycle->resolve($ticket, $data['solution_summary'], $request->user()), 'Ticket resuelto.', 'resolve'); }
    public function close(SupportTicket $ticket, Request $request): RedirectResponse { $this->authorizeAction($request, $ticket, 'support.close'); $data=$request->validateWithBag('close', ['reason'=>['required','string','max:10000'], 'close_pending_exception'=>['nullable','boolean'], 'customer_validated'=>['nullable','boolean']], [], ['reason'=>'validación o motivo de cierre']); return $this->attempt(fn () => $this->lifecycle->transition($ticket, SupportStatus::SLUG_CLOSED, $request->user(), $data['reason'], (bool)($data['close_pending_exception'] ?? false), (bool)($data['customer_validated'] ?? false)), 'Ticket cerrado.', 'close'); }
    public function reopen(SupportTicket $ticket, Request $request): RedirectResponse { $this->authorizeAction($request, $ticket, 'support.reopen'); $data=$request->validateWithBag('reopen', ['reason'=>['required','string','max:10000']], [], ['reason'=>'motivo de reapertura']); return $this->attempt(fn () => $this->lifecycle->transition($ticket, SupportStatus::SLUG_REOPENED, $request->user(), $data['reason']), 'Ticket reabierto.', 'reopen'); }
    public function cancel(SupportTicket $ticket, Request $request): RedirectResponse { Gate::authorize('cancel', $ticket); $data=$request->validateWithBag('cancel', ['reason'=>['required','string','max:10000']], [], ['reason'=>'motivo de cancelación']); return $this->attempt(fn () => $this->tickets->cancel($ticket, $request->user(), $data['reason']), 'Ticket cancelado.', 'cancel'); }
    public function addInternalNote(SupportTicket $ticket, Request $request): RedirectResponse { Gate::authorize('addUpdate', $ticket); $data=$request->validate(['body'=>['required','string','max:10000']], [], ['body'=>'nota interna']); $this->tickets->addInternalNote($ticket, $data['body'], $request->user()); return $this->back('Nota interna agregada.'); }
    public function addCustomerResponse(SupportTicket $ticket, Request $request): RedirectResponse { Gate::authorize('addUpdate', $ticket); $data=$request->validate(['body'=>['required','string','max:10000']], [], ['body'=>'respuesta al cliente']); $this->tickets->addCustomerResponse($ticket, $data['body'], $request->user()); return $this->back('Respuesta al cliente registrada.'); }

    public function schedule(SupportTicket $ticket, Request $request): RedirectResponse
    {
        $this->authorizeAction($request, $ticket, 'support.schedule');
        $data=$request->validateWithBag('schedule', ['type_id'=>['required','integer','exists:activity_types,id'], 'title'=>['required','string','max:255'], 'scheduled_at'=>['required','date'], 'owner_id'=>['nullable','integer','exists:users,id'], 'modality'=>['required', Rule::in(array_keys(SupportTicketLifecycleService::MODALITIES))], 'topic'=>['nullable','string','max:255'], 'objective'=>['nullable','string'], 'agenda'=>['nullable','string']], [], ['type_id'=>'tipo de actividad', 'title'=>'título', 'scheduled_at'=>'fecha y hora', 'owner_id'=>'responsable de la actividad', 'modality'=>'modalidad', 'topic'=>'tema', 'objective'=>'objetivo', 'agenda'=>'agenda']);

        $activityData = array_intersect_key($data, array_flip(['type_id', 'title', 'scheduled_at', 'owner_id']));
        $sessionData = array_intersect_key($data, array_flip(['modality', 'topic', 'objective', 'agenda']));

        return $this->attempt(fn () => $this->tickets->scheduleAttention($ticket, $activityData, $sessionData, $request->user()), 'Atención programada.', 'schedule');
    }
    public function reschedule(SupportTicket $ticket, Activity $activity, Request $request): RedirectResponse
    {
        $this->authorizeAction($request, $ticket, 'support.reschedule'); abort_unless((int)$activity->subject_id === (int)$ticket->id && $activity->subject_type === SupportTicket::class, 404);
        $data=$request->validateWithBag('reschedule'.$activity->id, ['scheduled_at'=>['required','date'], 'reason'=>['required','string','max:10000']], [], ['scheduled_at'=>'nueva fecha', 'reason'=>'motivo']); return $this->attempt(fn () => $this->lifecycle->reschedule($activity, $data['scheduled_at'], $data['reason'], $request->user()), 'Atención reprogramada.', 'reschedule'.$activity->id);
    }
    public function saveIncident(SupportTicket $ticket, Request $request): RedirectResponse { $this->authorizeAction($request, $ticket, 'support.update'); $data=$request->validate(['system'=>['nullable','string','max:255'],'module'=>['nullable','string','max:255'],'environment'=>['nullable','string','max:255'],'version'=>['nullable','string','max:255'],'steps_to_reproduce'=>['nullable','string'],'expected_result'=>['nullable','string'],'actual_result'=>['nullable','string'],'severity'=>['nullable','string','max:255'],'browser'=>['nullable','string','max:255'],'operating_system'=>['nullable','string','max:255'],'device'=>['nullable','string','max:255'],'diagnosis'=>['nullable','string'],'root_cause'=>['nullable','string'],'technical_solution'=>['nullable','string'],'post_fix_tests'=>['nullable','string']], [], ['system'=>'sistema', 'actual_result'=>'resultado obtenido', 'technical_solution'=>'solución técnica']); return $this->attempt(fn () => $this->lifecycle->saveIncident($ticket, $data), 'Detalle de incidente guardado.', 'incident'); }
    public function createObservation(SupportTicket $ticket, Request $request): RedirectResponse { $this->authorizeAction($request, $ticket, 'support.observations.create'); $data=$request->validateWithBag('observation', ['title'=>['required','string','max:255'],'description'=>['nullable','string'],'priority'=>['nullable','string','max:30'],'responsible_id'=>['nullable','integer','exists:users,id'],'due_at'=>['nullable','date']], [], ['title'=>'título de la observación', 'description'=>'detalle', 'priority'=>'prioridad', 'responsible_id'=>'responsable', 'due_at'=>'fecha límite']); return $this->attempt(fn () => $this->lifecycle->createObservation($ticket, $data, $request->user()), 'Observación creada.', 'observation'); }
    public function transitionObservation(SupportTicket $ticket, SupportObservation $observation, Request $request): RedirectResponse { Gate::authorize('view', $ticket); abort_unless((int)$observation->ticket_id === (int)$ticket->id, 404); $data=$request->validateWithBag('observation'.$observation->id, ['state'=>['required', Rule::in(array_keys(SupportObservation::TARGET_PERMISSIONS))],'reason'=>['nullable','string','max:10000']], ['state.in'=>'Estado de observación inválido.'], ['state'=>'estado', 'reason'=>'motivo']); abort_unless($request->user()->can(SupportObservation::TARGET_PERMISSIONS[$data['state']]),403); return $this->attempt(fn () => $this->lifecycle->transitionObservation($observation, $data['state'], $request->user(), $data['reason'] ?? null), 'Observación actualizada.', 'observation'.$observation->id); }
    public function addParticipant(SupportTicket $ticket, SupportSessionDetail $session, Request $request): RedirectResponse { $this->authorizeAction($request, $ticket, 'support.schedule'); abort_unless((int)$session->ticket_id === (int)$ticket->id, 404); $data=$request->validateWithBag('participant'.$session->id, ['name'=>['required','string','max:255'],'email'=>['nullable','email','max:255'],'attended'=>['required','boolean']], [], ['name'=>'nombre', 'email'=>'correo electrónico', 'attended'=>'asistencia']); return $this->attempt(fn () => $this->lifecycle->addParticipant($session, $data), 'Participante registrado.', 'participant'.$session->id); }

    private function authorizeAction(Request $request, SupportTicket $ticket, string $permission): void { Gate::authorize('view', $ticket); abort_unless($request->user()->can($permission), 403); }
    private function back(string $message): RedirectResponse { return redirect()->back()->with('status', $message); }

    /**
     * Runs a domain action; a refused state change returns to the page with its Spanish message instead of a 500.
     * Validation errors raised by the service land in the form's own error bag.
     */
    private function attempt(callable $action, string $message, string $errorBag = 'default'): RedirectResponse
    {
        try {
            $action();
        } catch (InvalidOperationException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            throw $exception->errorBag($errorBag);
        }

        return $this->back($message);
    }
    /**
     * Teams offered on the create form: every active team for support.view.any, otherwise the
     * active teams the user supervises or belongs to.
     */
    private function formData(User $user): array { $teams = Team::query()->where('is_active', true)->orderBy('name'); if (! $user->can('support.view.any')) $teams->where(fn ($q) => $q->where('supervisor_id', $user->id)->orWhereHas('members', fn ($m) => $m->whereKey($user->id))); return ['teams'=>$teams->get(['id','name']),'customers'=>Customer::query()->orderBy('code')->get(['id','code','legal_name','trade_name']),'contacts'=>Contact::query()->orderBy('last_name')->orderBy('first_name')->get(['id','customer_id','first_name','last_name','email']),'types'=>SupportTicketType::query()->active()->orderBy('sort')->get(),'categories'=>SupportCategory::query()->active()->orderBy('sort')->get(),'channels'=>SupportChannel::query()->active()->orderBy('sort')->get(),'priorities'=>SupportPriority::query()->active()->orderBy('sort')->get()]; }
}
