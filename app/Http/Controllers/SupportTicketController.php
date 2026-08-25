<?php

namespace App\Http\Controllers;

use App\Http\Requests\SupportTicketStoreRequest;
use App\Models\{Activity, ActivityType, Contact, Customer, SupportCategory, SupportChannel, SupportObservation, SupportPriority, SupportSessionDetail, SupportStatus, SupportTicket, SupportTicketType, Team, User};
use App\Services\{SupportTicketLifecycleService, SupportTicketScopeService, SupportTicketService};
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SupportTicketController extends Controller
{
    public function __construct(private readonly SupportTicketService $tickets, private readonly SupportTicketScopeService $scope, private readonly SupportTicketLifecycleService $lifecycle) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', SupportTicket::class);
        $query = $this->scope->apply(SupportTicket::query(), $request->user())->with(['customer:id,code,legal_name,trade_name', 'requester:id,first_name,last_name,email', 'type:id,name', 'priority:id,name,color', 'status:id,name,slug', 'responsible:id,name', 'team:id,name'])->latest();
        foreach (['status_id', 'priority_id'] as $filter) if ($request->filled($filter)) $query->where($filter, (int) $request->query($filter));
        $pageSize = (int) \App\Models\Setting::query()->where('key', 'pagination_size')->value('value');
        return view('support.tickets.index', ['tickets' => $query->paginate(max(1, $pageSize ?: 25))->withQueryString(), 'statuses' => SupportStatus::query()->orderBy('sort')->get(), 'priorities' => SupportPriority::query()->orderBy('sort')->get(), 'filters' => $request->only(['status_id', 'priority_id'])]);
    }

    public function create(): View { Gate::authorize('create', SupportTicket::class); return view('support.tickets.create', $this->formData()); }
    public function store(SupportTicketStoreRequest $request): RedirectResponse { $ticket = $this->tickets->create($request->validated(), $request->user()); return redirect()->route('support.tickets.show', $ticket)->with('status', 'Ticket de soporte creado.'); }

    public function show(SupportTicket $ticket): View
    {
        Gate::authorize('view', $ticket);
        $ticket->load(['customer', 'requester', 'type', 'category', 'channel', 'priority', 'status', 'responsible', 'team', 'assignments', 'updates.author', 'documents', 'activities.type', 'sessionDetails.participants', 'sessionDetails.documents', 'incidentDetail.documents', 'observations.documents', 'observations.histories']);
        return view('support.tickets.show', ['ticket' => $ticket, 'users' => User::query()->where('is_active', true)->orderBy('name')->get(['id','name']), 'teams' => Team::query()->where('is_active', true)->orderBy('name')->get(['id','name']), 'activityTypes' => ActivityType::query()->where('is_active', true)->orderBy('name')->get(['id','name'])]);
    }

    public function assign(SupportTicket $ticket, Request $request): RedirectResponse
    {
        Gate::authorize($ticket->responsible_id === null ? 'assign' : 'reassign', $ticket);

        $wasAssigned = $ticket->responsible_id !== null;

        $data = $request->validate([
            'responsible_id' => ['required', 'integer', 'exists:users,id'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'reason' => [$wasAssigned ? 'required' : 'nullable', 'string', 'max:10000'],
        ], [
            'reason.required' => 'El motivo de reasignación es obligatorio.',
        ]);

        $responsible = User::query()->findOrFail((int) $data['responsible_id']);

        $this->tickets->assign(
            $ticket,
            $responsible,
            $request->user(),
            isset($data['team_id']) ? (int) $data['team_id'] : null,
            $data['reason'] ?? null,
        );

        return $this->back($wasAssigned ? 'Responsable reasignado.' : 'Responsable asignado.');
    }

    public function start(SupportTicket $ticket, Request $request): RedirectResponse
    {
        $this->authorizeAction($request, $ticket, 'support.attention.start');

        if ($ticket->status?->slug === SupportStatus::SLUG_IN_PROGRESS) {
            return $this->back('La atención ya está en marcha.');
        }

        if ($ticket->status?->slug === SupportStatus::SLUG_NEW && $ticket->responsible_id === null) {
            $this->tickets->assign($ticket, $request->user(), $request->user(), $ticket->team_id, 'Inicio de atención por el responsable');
            $ticket->refresh();
        }

        $this->lifecycle->transition($ticket, SupportStatus::SLUG_IN_PROGRESS, $request->user());

        return $this->back('Atención iniciada.');
    }
    public function resolve(SupportTicket $ticket, Request $request): RedirectResponse { $this->authorizeAction($request, $ticket, 'support.resolve'); $data=$request->validate(['solution_summary'=>['required','string','max:10000']]); $ticket->update(['solution_summary'=>$data['solution_summary'], 'updated_by'=>$request->user()->id]); $this->lifecycle->transition($ticket->fresh(), 'resuelto', $request->user()); return $this->back('Ticket resuelto.'); }
    public function close(SupportTicket $ticket, Request $request): RedirectResponse { $this->authorizeAction($request, $ticket, 'support.close'); $data=$request->validate(['reason'=>['required','string','max:10000'], 'close_pending_exception'=>['nullable','boolean']]); $this->lifecycle->transition($ticket, 'cerrado', $request->user(), $data['reason'], (bool)($data['close_pending_exception'] ?? false)); return $this->back('Ticket cerrado.'); }
    public function reopen(SupportTicket $ticket, Request $request): RedirectResponse { $this->authorizeAction($request, $ticket, 'support.reopen'); $data=$request->validate(['reason'=>['required','string','max:10000']]); $this->lifecycle->transition($ticket, 'reabierto', $request->user(), $data['reason']); return $this->back('Ticket reabierto.'); }
    public function cancel(SupportTicket $ticket, Request $request): RedirectResponse { Gate::authorize('cancel', $ticket); $data=$request->validate(['reason'=>['required','string','max:10000']]); $this->tickets->cancel($ticket, $request->user(), $data['reason']); return $this->back('Ticket cancelado.'); }
    public function addInternalNote(SupportTicket $ticket, Request $request): RedirectResponse { Gate::authorize('addUpdate', $ticket); $data=$request->validate(['body'=>['required','string','max:10000']]); $this->tickets->addInternalNote($ticket, $data['body'], $request->user()); return $this->back('Nota interna agregada.'); }
    public function addCustomerResponse(SupportTicket $ticket, Request $request): RedirectResponse { Gate::authorize('addUpdate', $ticket); $data=$request->validate(['body'=>['required','string','max:10000']]); $this->tickets->addCustomerResponse($ticket, $data['body'], $request->user()); return $this->back('Respuesta al cliente registrada.'); }

    public function schedule(SupportTicket $ticket, Request $request): RedirectResponse
    {
        $this->authorizeAction($request, $ticket, 'support.schedule');
        $data=$request->validate(['type_id'=>['required','integer','exists:activity_types,id'], 'title'=>['required','string','max:255'], 'scheduled_at'=>['required','date'], 'owner_id'=>['nullable','integer','exists:users,id'], 'modality'=>['required','in:virtual,presential,phone,not_applicable'], 'topic'=>['nullable','string','max:255'], 'objective'=>['nullable','string'], 'agenda'=>['nullable','string']]);

        if ($ticket->status?->slug === SupportStatus::SLUG_NEW && $ticket->responsible_id === null) {
            $this->tickets->assign($ticket, $request->user(), $request->user(), $ticket->team_id, 'Programación de atención por el responsable');
            $ticket->refresh();
        }

        $activityData = array_intersect_key($data, array_flip(['type_id', 'title', 'scheduled_at', 'owner_id']));
        $sessionData = array_intersect_key($data, array_flip(['modality', 'topic', 'objective', 'agenda']));

        $this->lifecycle->schedule($ticket, $activityData, $sessionData, $request->user()); return $this->back('Atención programada.');
    }
    public function reschedule(SupportTicket $ticket, Activity $activity, Request $request): RedirectResponse
    {
        $this->authorizeAction($request, $ticket, 'support.reschedule'); abort_unless((int)$activity->subject_id === (int)$ticket->id && $activity->subject_type === SupportTicket::class, 404);
        $data=$request->validate(['scheduled_at'=>['required','date'], 'reason'=>['required','string','max:10000']]); $this->lifecycle->reschedule($activity, $data['scheduled_at'], $data['reason'], $request->user()); return $this->back('Atención reprogramada.');
    }
    public function saveIncident(SupportTicket $ticket, Request $request): RedirectResponse { $this->authorizeAction($request, $ticket, 'support.update'); $data=$request->validate(['system'=>['nullable','string','max:255'],'module'=>['nullable','string','max:255'],'environment'=>['nullable','string','max:255'],'version'=>['nullable','string','max:255'],'steps_to_reproduce'=>['nullable','string'],'expected_result'=>['nullable','string'],'actual_result'=>['nullable','string'],'severity'=>['nullable','string','max:255'],'browser'=>['nullable','string','max:255'],'operating_system'=>['nullable','string','max:255'],'device'=>['nullable','string','max:255'],'diagnosis'=>['nullable','string'],'root_cause'=>['nullable','string'],'technical_solution'=>['nullable','string'],'post_fix_tests'=>['nullable','string']]); $this->lifecycle->saveIncident($ticket, $data); return $this->back('Detalle de incidente guardado.'); }
    public function createObservation(SupportTicket $ticket, Request $request): RedirectResponse { $this->authorizeAction($request, $ticket, 'support.observations.create'); $data=$request->validate(['title'=>['required','string','max:255'],'description'=>['nullable','string'],'priority'=>['nullable','string','max:30'],'responsible_id'=>['nullable','integer','exists:users,id'],'due_at'=>['nullable','date']]); $this->lifecycle->createObservation($ticket, $data, $request->user()); return $this->back('Observación creada.'); }
    public function transitionObservation(SupportTicket $ticket, SupportObservation $observation, Request $request): RedirectResponse { Gate::authorize('view', $ticket); abort_unless((int)$observation->ticket_id === (int)$ticket->id, 404); $data=$request->validate(['state'=>['required','in:pending,in_process,lifted,validated,rejected,reopened,not_applicable'],'reason'=>['nullable','string','max:10000']]); $permission=match($data['state']) {'lifted'=>'support.observations.lift','validated'=>'support.observations.validate','rejected'=>'support.observations.reject',default=>'support.observations.create'}; abort_unless($request->user()->can($permission),403); $this->lifecycle->transitionObservation($observation, $data['state'], $request->user(), $data['reason'] ?? null); return $this->back('Observación actualizada.'); }
    public function addParticipant(SupportTicket $ticket, SupportSessionDetail $session, Request $request): RedirectResponse { $this->authorizeAction($request, $ticket, 'support.schedule'); abort_unless((int)$session->ticket_id === (int)$ticket->id, 404); $data=$request->validate(['name'=>['required','string','max:255'],'email'=>['nullable','email','max:255'],'attended'=>['required','boolean']]); $session->participants()->create($data); return $this->back('Participante registrado.'); }

    private function authorizeAction(Request $request, SupportTicket $ticket, string $permission): void { Gate::authorize('view', $ticket); abort_unless($request->user()->can($permission), 403); }
    private function back(string $message): RedirectResponse { return redirect()->back()->with('status', $message); }
    private function formData(): array { return ['customers'=>Customer::query()->orderBy('code')->get(['id','code','legal_name','trade_name']),'contacts'=>Contact::query()->orderBy('last_name')->orderBy('first_name')->get(['id','customer_id','first_name','last_name','email']),'types'=>SupportTicketType::query()->active()->orderBy('sort')->get(),'categories'=>SupportCategory::query()->active()->orderBy('sort')->get(),'channels'=>SupportChannel::query()->active()->orderBy('sort')->get(),'priorities'=>SupportPriority::query()->active()->orderBy('sort')->get()]; }
}
