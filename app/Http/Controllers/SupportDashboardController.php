<?php

namespace App\Http\Controllers;

use App\Models\{Customer, SupportCategory, SupportPriority, SupportStatus, SupportTicket, SupportTicketType, Team, User};
use App\Services\{SupportDashboardService, SupportTicketScopeService};
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupportDashboardController extends Controller
{
    public function __construct(private readonly SupportDashboardService $dashboard, private readonly SupportTicketScopeService $scope) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()->can('support.reports.view'), 403);
        $filters=$this->filters($request);
        return view('support.dashboard', array_merge(['metrics'=>$this->dashboard->metrics($filters, $request->user()), 'filters'=>$filters], $this->scopedOptions($request->user()), ['types'=>SupportTicketType::query()->orderBy('sort')->get(['id','name']), 'categories'=>SupportCategory::query()->orderBy('sort')->get(['id','name']), 'priorities'=>SupportPriority::query()->orderBy('sort')->get(['id','name']), 'statuses'=>SupportStatus::query()->orderBy('sort')->get(['id','name'])]));
    }

    /**
     * Customer/responsible/team dropdowns: everything for support.view.any, otherwise only
     * what appears in the tickets the user can see (plus the user), so filters never leak other scopes.
     *
     * @return array{customers:\Illuminate\Support\Collection,users:\Illuminate\Support\Collection,teams:\Illuminate\Support\Collection}
     */
    private function scopedOptions(User $user): array
    {
        $customers = Customer::query()->orderBy('code');
        $users = User::query()->where('is_active', true)->orderBy('name');
        $teams = Team::query()->where('is_active', true)->orderBy('name');

        if (! $user->can('support.view.any')) {
            $visible = fn (string $column) => $this->scope->apply(SupportTicket::query(), $user)->whereNotNull($column)->distinct()->select($column);
            $customers->whereIn('id', $visible('customer_id'));
            $users->where(fn ($q) => $q->whereIn('id', $visible('responsible_id'))->orWhere('id', $user->id));
            $teams->whereIn('id', $visible('team_id'));
        }

        return ['customers' => $customers->get(['id', 'code']), 'users' => $users->get(['id', 'name']), 'teams' => $teams->get(['id', 'name'])];
    }

    public function export(Request $request): StreamedResponse
    {
        abort_unless($request->user()->can('support.reports.view'), 403);
        $tickets=$this->dashboard->tickets($this->filters($request), $request->user());
        $modalities=['virtual'=>'Virtual','presential'=>'Presencial','phone'=>'Teléfono','not_applicable'=>'No aplica'];
        return response()->streamDownload(function () use ($tickets, $modalities): void {
            $out=fopen('php://output','w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out,['Código','Fecha de creación','Cliente','Responsable','Tipo','Categoría','Prioridad','Estado','Modalidad']);
            foreach($tickets as $ticket) { $modality=$ticket->sessionDetails->first()?->modality; fputcsv($out,[$ticket->code,$ticket->created_at?->format('Y-m-d H:i:s'),$ticket->customer?->code,$ticket->responsible?->name,$ticket->type?->name,$ticket->category?->name,$ticket->priority?->name,$ticket->status?->name,$modality === null ? '' : ($modalities[$modality] ?? $modality)]); }
            fclose($out);
        }, 'reporte-soporte.csv', ['Content-Type'=>'text/csv; charset=UTF-8']);
    }

    /** @return array<string,mixed> */
    private function filters(Request $request): array { return $request->validate(['from'=>['nullable','date'],'to'=>['nullable','date','after_or_equal:from'],'customer_id'=>['nullable','integer'],'responsible_id'=>['nullable','integer'],'team_id'=>['nullable','integer'],'type_id'=>['nullable','integer'],'category_id'=>['nullable','integer'],'priority_id'=>['nullable','integer'],'status_id'=>['nullable','integer'],'modality'=>['nullable','in:virtual,presential,phone,not_applicable']]); }
}
