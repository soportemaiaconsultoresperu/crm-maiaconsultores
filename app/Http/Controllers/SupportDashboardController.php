<?php

namespace App\Http\Controllers;

use App\Models\{Customer, SupportCategory, SupportPriority, SupportStatus, SupportTicketType, Team, User};
use App\Services\SupportDashboardService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupportDashboardController extends Controller
{
    public function __construct(private readonly SupportDashboardService $dashboard) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()->can('support.reports.view'), 403);
        $filters=$this->filters($request);
        return view('support.dashboard', ['metrics'=>$this->dashboard->metrics($filters), 'filters'=>$filters, 'customers'=>Customer::query()->orderBy('code')->get(['id','code']), 'users'=>User::query()->where('is_active',true)->orderBy('name')->get(['id','name']), 'teams'=>Team::query()->where('is_active',true)->orderBy('name')->get(['id','name']), 'types'=>SupportTicketType::query()->orderBy('sort')->get(['id','name']), 'categories'=>SupportCategory::query()->orderBy('sort')->get(['id','name']), 'priorities'=>SupportPriority::query()->orderBy('sort')->get(['id','name']), 'statuses'=>SupportStatus::query()->orderBy('sort')->get(['id','name'])]);
    }

    public function export(Request $request): StreamedResponse
    {
        abort_unless($request->user()->can('support.reports.view'), 403);
        $tickets=$this->dashboard->tickets($this->filters($request));
        return response()->streamDownload(function () use ($tickets): void { $out=fopen('php://output','w'); fputcsv($out,['Code','Created at','Customer','Responsible','Type','Category','Priority','Status','Modality']); foreach($tickets as $ticket) fputcsv($out,[$ticket->code,$ticket->created_at?->format('Y-m-d H:i:s'),$ticket->customer?->code,$ticket->responsible?->name,$ticket->type?->name,$ticket->category?->name,$ticket->priority?->name,$ticket->status?->name,$ticket->sessionDetails->first()?->modality]); fclose($out); }, 'support-report.csv', ['Content-Type'=>'text/csv; charset=UTF-8']);
    }

    /** @return array<string,mixed> */
    private function filters(Request $request): array { return $request->validate(['from'=>['nullable','date'],'to'=>['nullable','date','after_or_equal:from'],'customer_id'=>['nullable','integer'],'responsible_id'=>['nullable','integer'],'team_id'=>['nullable','integer'],'type_id'=>['nullable','integer'],'category_id'=>['nullable','integer'],'priority_id'=>['nullable','integer'],'status_id'=>['nullable','integer'],'modality'=>['nullable','in:virtual,presential,phone,not_applicable']]); }
}
