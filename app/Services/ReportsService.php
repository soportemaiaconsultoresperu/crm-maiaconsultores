<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Quotation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reports aggregation service (RF-REP-001..006).
 *
 * - Data is filtered through the requester's data scope (ADR-006 /
 *   RF-REP-006) using DataScopeService::appliesTo.
 * - Multimoneda is preserved (ADR-004 / RF-REP-002): every monetary total
 *   is returned as a `{currency_code => amount}` or grouped
 *   `{currency_code, count, amount}` shape, never collapsed into a single
 *   number.
 * - Date and owner filters are optional and stackable.
 *
 * Each public method is a self-contained report the controller layer
 * (Tanda B) can render or export independently.
 */
class ReportsService
{
    public function __construct(private readonly DataScopeService $scope) {}

    /**
     * Leads grouped by source with count + percentage.
     *
     * Filters: from, to, owner_id.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function prospectosPorOrigen(User $viewer, array $filters = []): array
    {
        $query = $this->scope->appliesTo(Lead::query(), $viewer);
        $this->applyDateRange($query, $filters, 'entered_at');
        $this->applyOwnerFilter($query, $filters, 'owner_id');

        $total = (clone $query)->count();

        $rows = $query
            ->select(
                'source_id',
                DB::raw('COUNT(*) as count'),
            )
            ->groupBy('source_id')
            ->with('source')
            ->get();

        return $rows->map(function ($row) use ($total): array {
            $count = (int) $row->count;
            $percentage = $total > 0 ? round(($count / $total) * 100, 2) : 0.0;

            return [
                'source_id' => $row->source_id,
                'source' => $row->source?->name ?? 'Sin origen',
                'count' => $count,
                'percentage' => $percentage,
            ];
        })->all();
    }

    /**
     * Leads grouped by owner, with status mix (counts per status).
     *
     * Filters: from, to, source_id.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function prospectosPorVendedor(User $viewer, array $filters = []): array
    {
        $query = $this->scope->appliesTo(Lead::query(), $viewer);
        $this->applyDateRange($query, $filters, 'entered_at');

        if (! empty($filters['source_id'])) {
            $query->where('source_id', $filters['source_id']);
        }

        $owners = $query
            ->select('owner_id', DB::raw('COUNT(*) as count'))
            ->groupBy('owner_id')
            ->with('owner')
            ->orderByDesc('count')
            ->get();

        return $owners->map(function ($row) use ($filters): array {
            $ownerId = (int) $row->owner_id;
            $statusQuery = Lead::query()->where('owner_id', $ownerId);

            $this->applyDateRange($statusQuery, $filters, 'entered_at');

            if (! empty($filters['source_id'])) {
                $statusQuery->where('source_id', $filters['source_id']);
            }

            $statuses = $statusQuery
                ->select('status_id', DB::raw('COUNT(*) as count'))
                ->groupBy('status_id')
                ->with('status')
                ->get()
                ->map(fn ($s) => [
                    'status' => $s->status?->name ?? 'Sin estado',
                    'count' => (int) $s->count,
                ])
                ->all();

            return [
                'owner_id' => $ownerId,
                'owner' => $row->owner?->name ?? 'Sin responsable',
                'count' => (int) $row->count,
                'statuses' => $statuses,
            ];
        })->all();
    }

    /**
     * Lead → customer conversion rate grouped by year-month (entered_at).
     * Only successful conversions are counted (customers.converted_at
     * matches the lead window).
     *
     * Filters: from, to, owner_id.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function conversionDeProspectos(User $viewer, array $filters = []): array
    {
        $start = ! empty($filters['from']) ? Carbon::parse($filters['from'])->startOfMonth() : null;
        $end = ! empty($filters['to']) ? Carbon::parse($filters['to'])->endOfMonth() : null;

        $leadsQuery = $this->scope->appliesTo(Lead::query(), $viewer);
        $this->applyOwnerFilter($leadsQuery, $filters, 'owner_id');

        $customersQuery = $this->scope->appliesTo(Customer::query(), $viewer);
        $this->applyOwnerFilter($customersQuery, $filters, 'owner_id');

        if ($start !== null) {
            $leadsQuery->where('entered_at', '>=', $start);
            $customersQuery->where('converted_at', '>=', $start);
        }
        if ($end !== null) {
            $leadsQuery->where('entered_at', '<=', $end);
            $customersQuery->where('converted_at', '<=', $end);
        }

        $leadsByMonth = $leadsQuery
            ->selectRaw("strftime('%Y-%m', entered_at) as month, COUNT(*) as count")
            ->groupBy('month')
            ->pluck('count', 'month');

        $customersByMonth = $customersQuery
            ->selectRaw("strftime('%Y-%m', converted_at) as month, COUNT(*) as count")
            ->groupBy('month')
            ->pluck('count', 'month');

        $months = collect($leadsByMonth->keys()->merge($customersByMonth->keys())->unique())->sort();

        return $months->map(function (string $month) use ($leadsByMonth, $customersByMonth): array {
            $leads = (int) ($leadsByMonth[$month] ?? 0);
            $converted = (int) ($customersByMonth[$month] ?? 0);
            $rate = $leads > 0 ? round(($converted / $leads) * 100, 2) : 0.0;

            return [
                'month' => $month,
                'leads' => $leads,
                'converted' => $converted,
                'rate' => $rate,
            ];
        })->values()->all();
    }

    /**
     * Opportunities grouped by stage with count + amount by currency.
     *
     * Filters: from, to, owner_id, status (open|won|lost).
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function oportunidadesPorEtapa(User $viewer, array $filters = []): array
    {
        $query = $this->scopedOpportunities($viewer);
        $this->applyDateRange($query, $filters, 'expected_close_at', 'created_at');
        $this->applyOwnerFilter($query, $filters, 'owner_id');

        if (! empty($filters['status']) && in_array($filters['status'], ['open', 'won', 'lost'], true)) {
            $query->whereHas('stage', fn (Builder $q) => $q->where('stage_type', $filters['status']));
        }

        $rows = $query
            ->select(
                'stage_id',
                'currency_code',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(estimated_amount) as amount'),
            )
            ->groupBy('stage_id', 'currency_code')
            ->with('stage')
            ->get();

        return $rows->map(function ($row): array {
            return [
                'stage_id' => $row->stage_id,
                'stage' => $row->stage?->name ?? 'Sin etapa',
                'stage_type' => $row->stage?->stage_type,
                'currency_code' => $row->currency_code,
                'count' => (int) $row->count,
                'amount' => (float) $row->amount,
            ];
        })->values()->all();
    }

    /**
     * Open opportunities: sum of estimated_amount grouped by owner and
     * currency (ADR-004).
     *
     * Filters: from, to.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function valorDelEmbudo(User $viewer, array $filters = []): array
    {
        $query = $this->scopedOpportunities($viewer)
            ->whereHas('stage', fn (Builder $q) => $q->where('stage_type', 'open'));
        $this->applyDateRange($query, $filters, 'expected_close_at', 'created_at');

        $rows = $query
            ->select(
                'owner_id',
                'currency_code',
                DB::raw('SUM(estimated_amount) as amount'),
                DB::raw('COUNT(*) as count'),
            )
            ->groupBy('owner_id', 'currency_code')
            ->with('owner')
            ->get();

        return $rows->map(function ($row): array {
            return [
                'owner_id' => (int) $row->owner_id,
                'owner' => $row->owner?->name ?? 'Sin responsable',
                'currency_code' => $row->currency_code,
                'count' => (int) $row->count,
                'amount' => (float) $row->amount,
            ];
        })->values()->all();
    }

    /**
     * Won vs lost by month (year-month of closed_at), count + amount by
     * currency. The output is grouped by outcome so the UI can render the
     * two series side by side.
     *
     * Filters: from, to, owner_id.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function ventasGanadasYPerdidas(User $viewer, array $filters = []): array
    {
        $query = $this->scopedOpportunities($viewer)
            ->whereHas('stage', fn (Builder $q) => $q->whereIn('stage_type', ['won', 'lost']));
        $this->applyDateRange($query, $filters, 'closed_at');
        $this->applyOwnerFilter($query, $filters, 'owner_id');

        $rows = $query
            ->select(
                'stage_id',
                'currency_code',
                DB::raw('COUNT(*) as count'),
                DB::raw('COALESCE(SUM(final_amount), SUM(estimated_amount)) as amount'),
            )
            ->groupBy('stage_id', 'currency_code')
            ->with('stage')
            ->get();

        return $rows->map(function ($row): array {
            $type = $row->stage?->stage_type;

            return [
                'stage_id' => $row->stage_id,
                'stage' => $row->stage?->name ?? 'Sin etapa',
                'outcome' => $type === 'won' ? 'won' : ($type === 'lost' ? 'lost' : 'other'),
                'currency_code' => $row->currency_code,
                'count' => (int) $row->count,
                'amount' => (float) $row->amount,
            ];
        })->values()->all();
    }

    /**
     * Lost opportunities grouped by loss reason with count + amount by
     * currency.
     *
     * Filters: from, to, owner_id.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function motivosDePerdida(User $viewer, array $filters = []): array
    {
        $query = $this->scopedOpportunities($viewer)
            ->whereHas('stage', fn (Builder $q) => $q->where('stage_type', 'lost'));
        $this->applyDateRange($query, $filters, 'closed_at');
        $this->applyOwnerFilter($query, $filters, 'owner_id');

        $rows = $query
            ->select(
                'loss_reason_id',
                'currency_code',
                DB::raw('COUNT(*) as count'),
                DB::raw('COALESCE(SUM(final_amount), SUM(estimated_amount)) as amount'),
            )
            ->groupBy('loss_reason_id', 'currency_code')
            ->with('lossReason')
            ->get();

        return $rows->map(function ($row): array {
            return [
                'loss_reason_id' => $row->loss_reason_id,
                'loss_reason' => $row->lossReason?->name ?? 'Sin motivo',
                'currency_code' => $row->currency_code,
                'count' => (int) $row->count,
                'amount' => (float) $row->amount,
            ];
        })->values()->all();
    }

    /**
     * Activities grouped by owner with status mix (counts per status).
     *
     * Filters: from, to.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function actividadesPorVendedor(User $viewer, array $filters = []): array
    {
        $query = $this->scopedActivities($viewer);
        $this->applyDateRange($query, $filters, 'scheduled_at');

        $owners = $query
            ->select('owner_id', DB::raw('COUNT(*) as count'))
            ->groupBy('owner_id')
            ->with('owner')
            ->orderByDesc('count')
            ->get();

        return $owners->map(function ($row) use ($filters): array {
            $ownerId = (int) $row->owner_id;

            $statusQuery = Activity::query()->where('owner_id', $ownerId);
            $this->applyDateRange($statusQuery, $filters, 'scheduled_at');

            $statuses = $statusQuery
                ->select('status', DB::raw('COUNT(*) as count'))
                ->groupBy('status')
                ->orderBy('status')
                ->get()
                ->map(fn ($s) => [
                    'status' => $s->status,
                    'count' => (int) $s->count,
                ])
                ->all();

            return [
                'owner_id' => $ownerId,
                'owner' => $row->owner?->name ?? 'Sin responsable',
                'count' => (int) $row->count,
                'statuses' => $statuses,
            ];
        })->values()->all();
    }

    /**
     * Overdue activities per owner with an "age" bucket (days past).
     * Age buckets: 0-1, 2-3, 4-7, 8-30, 31+.
     *
     * Filters: from, to (override scheduled_at range).
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function actividadesVencidas(User $viewer, array $filters = []): array
    {
        $now = now();

        $query = $this->scopedActivities($viewer)
            ->where(function (Builder $q) use ($now): void {
                $q->where('status', 'overdue')
                    ->orWhere(function (Builder $inner) use ($now): void {
                        $inner->where('status', 'pending')
                            ->where('scheduled_at', '<', $now);
                    });
            });

        if (! empty($filters['from'])) {
            $query->where('scheduled_at', '>=', Carbon::parse($filters['from']));
        }
        if (! empty($filters['to'])) {
            $query->where('scheduled_at', '<=', Carbon::parse($filters['to']));
        }

        $rows = $query
            ->select(
                'owner_id',
                DB::raw('COUNT(*) as count'),
                DB::raw('MIN(scheduled_at) as oldest'),
            )
            ->groupBy('owner_id')
            ->with('owner')
            ->orderByDesc('count')
            ->get();

        return $rows->map(function ($row) use ($now): array {
            $oldest = $row->oldest ? Carbon::parse($row->oldest) : null;
            $ageDays = $oldest ? (int) $oldest->diffInDays($now, false) : 0;

            return [
                'owner_id' => (int) $row->owner_id,
                'owner' => $row->owner?->name ?? 'Sin responsable',
                'count' => (int) $row->count,
                'oldest_scheduled_at' => $oldest?->toIso8601String(),
                'age_days' => $ageDays,
                'age_bucket' => $this->ageBucket($ageDays),
            ];
        })->values()->all();
    }

    /**
     * Quotations issued: count + total grouped by status and currency.
     *
     * Filters: from, to, owner_id.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function cotizacionesEmitidas(User $viewer, array $filters = []): array
    {
        $query = $this->scopedQuotations($viewer);
        $this->applyDateRange($query, $filters, 'issued_at');
        $this->applyOwnerFilter($query, $filters, 'owner_id');

        $rows = $query
            ->select(
                'status',
                'currency_code',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(total) as amount'),
            )
            ->groupBy('status', 'currency_code')
            ->orderBy('status')
            ->orderBy('currency_code')
            ->get();

        return $rows->map(fn ($row): array => [
            'status' => $row->status,
            'currency_code' => $row->currency_code,
            'count' => (int) $row->count,
            'amount' => (float) $row->amount,
        ])->all();
    }

    /**
     * Quotations: accepted vs rejected counts + totals grouped by currency.
     * Both outcomes use the quotation currency (ADR-004).
     *
     * Filters: from, to, owner_id.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function cotizacionesAceptadasYRechazadas(User $viewer, array $filters = []): array
    {
        $query = $this->scopedQuotations($viewer)
            ->whereIn('status', ['accepted', 'rejected']);
        $this->applyDateRange($query, $filters, 'issued_at');
        $this->applyOwnerFilter($query, $filters, 'owner_id');

        $rows = $query
            ->select(
                'status',
                'currency_code',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(total) as amount'),
            )
            ->groupBy('status', 'currency_code')
            ->orderBy('status')
            ->orderBy('currency_code')
            ->get();

        return $rows->map(fn ($row): array => [
            'outcome' => $row->status,
            'currency_code' => $row->currency_code,
            'count' => (int) $row->count,
            'amount' => (float) $row->amount,
        ])->all();
    }

    /**
     * Per-salesperson commercial performance: leads, converted customers,
     * opportunity counts (open/won/lost), won amount by currency, and
     * quotations accepted. One row per owner; monetary fields stay
     * grouped by currency (ADR-004).
     *
     * Filters: from, to.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function rendimientoComercial(User $viewer, array $filters = []): array
    {
        $leadBase = $this->scopedLeads($viewer);
        $this->applyDateRange($leadBase, $filters, 'entered_at');

        $leadRows = $leadBase
            ->select('owner_id', DB::raw('COUNT(*) as count'))
            ->groupBy('owner_id')
            ->pluck('count', 'owner_id');

        $customerQuery = $this->scope->appliesTo(Customer::query(), $viewer);
        $this->applyDateRange($customerQuery, $filters, 'converted_at');
        $customerRows = $customerQuery
            ->select('owner_id', DB::raw('COUNT(*) as count'))
            ->groupBy('owner_id')
            ->pluck('count', 'owner_id');

        $oppBase = $this->scopedOpportunities($viewer);
        $this->applyDateRange($oppBase, $filters, 'expected_close_at', 'created_at');

        $oppCounts = $oppBase
            ->select('owner_id', 'stage_id', DB::raw('COUNT(*) as count'))
            ->groupBy('owner_id', 'stage_id')
            ->with('stage')
            ->get();

        $wonFrom = ! empty($filters['from'])
            ? Carbon::parse($filters['from'])->startOfMonth()
            : now()->startOfMonth();
        $wonTo = ! empty($filters['to'])
            ? Carbon::parse($filters['to'])->endOfMonth()
            : now()->endOfMonth();

        $wonAmountRows = $this->scopedOpportunities($viewer)
            ->whereHas('stage', fn (Builder $q) => $q->where('stage_type', 'won'))
            ->whereBetween('closed_at', [$wonFrom, $wonTo])
            ->select(
                'owner_id',
                'currency_code',
                DB::raw('COUNT(*) as won_count'),
                DB::raw('COALESCE(SUM(final_amount), SUM(estimated_amount)) as won_amount'),
            )
            ->groupBy('owner_id', 'currency_code')
            ->get();

        $quotBase = $this->scopedQuotations($viewer);
        $this->applyDateRange($quotBase, $filters, 'issued_at');

        $quotRows = $quotBase
            ->select('owner_id', DB::raw("SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted_count"))
            ->groupBy('owner_id')
            ->pluck('accepted_count', 'owner_id');

        $ownerIds = collect([
            ...array_keys($leadRows->all()),
            ...array_keys($customerRows->all()),
            ...$oppCounts->pluck('owner_id')->all(),
            ...$wonAmountRows->pluck('owner_id')->all(),
            ...array_keys($quotRows->all()),
        ])->unique()->map(fn ($id) => (int) $id);

        $users = User::query()->whereIn('id', $ownerIds)->get()->keyBy('id');

        return $ownerIds->sort()->map(function (int $ownerId) use ($leadRows, $customerRows, $oppCounts, $wonAmountRows, $quotRows, $users): array {
            $oppByType = ['open' => 0, 'won' => 0, 'lost' => 0];

            foreach ($oppCounts as $row) {
                if ((int) $row->owner_id !== $ownerId) {
                    continue;
                }

                $type = $row->stage?->stage_type;

                if (in_array($type, ['open', 'won', 'lost'], true)) {
                    $oppByType[$type] = (int) $row->count;
                }
            }

            $wonByCurrency = [];

            foreach ($wonAmountRows as $row) {
                if ((int) $row->owner_id !== $ownerId) {
                    continue;
                }
                $wonByCurrency[$row->currency_code] = [
                    'count' => (int) $row->won_count,
                    'amount' => (float) $row->won_amount,
                ];
            }

            return [
                'owner_id' => $ownerId,
                'owner' => $users[$ownerId]?->name ?? 'Sin responsable',
                'leads_count' => (int) ($leadRows[$ownerId] ?? 0),
                'customers_count' => (int) ($customerRows[$ownerId] ?? 0),
                'opportunities_open' => $oppByType['open'],
                'opportunities_won' => $oppByType['won'],
                'opportunities_lost' => $oppByType['lost'],
                'won_amount_by_currency' => $wonByCurrency,
                'quotations_accepted_count' => (int) ($quotRows[$ownerId] ?? 0),
            ];
        })->values()->all();
    }

    /**
     * Apply an inclusive date range to the given column. Falls back to a
     * secondary column when the primary is missing (some metrics only
     * have created_at).
     */
    private function applyDateRange(Builder $query, array $filters, string $column, ?string $fallback = null): void
    {
        if (empty($filters['from']) && empty($filters['to'])) {
            return;
        }

        $resolved = $this->resolveColumn($query, $column, $fallback);

        if ($resolved === null) {
            return;
        }

        if (! empty($filters['from'])) {
            $query->where($resolved, '>=', Carbon::parse($filters['from']));
        }

        if (! empty($filters['to'])) {
            $query->where($resolved, '<=', Carbon::parse($filters['to']));
        }
    }

    private function applyOwnerFilter(Builder $query, array $filters, string $column): void
    {
        if (! empty($filters[$column])) {
            $query->where($column, $filters[$column]);
        }
    }

    /**
     * Resolve a date column against the model's table; if the primary
     * column does not exist on the queried table, fall back to the
     * secondary one (e.g. expected_close_at -> created_at).
     */
    private function resolveColumn(Builder $query, string $column, ?string $fallback): ?string
    {
        $table = $query->getModel()->getTable();

        if (Schema::hasColumn($table, $column)) {
            return $column;
        }

        if ($fallback !== null && Schema::hasColumn($table, $fallback)) {
            return $fallback;
        }

        return null;
    }

    private function scopedOpportunities(User $viewer): Builder
    {
        return $this->scope->appliesTo(Opportunity::query(), $viewer);
    }

    private function scopedLeads(User $viewer): Builder
    {
        return $this->scope->appliesTo(Lead::query(), $viewer);
    }

    private function scopedActivities(User $viewer): Builder
    {
        return $this->scope->appliesTo(Activity::query(), $viewer);
    }

    private function scopedQuotations(User $viewer): Builder
    {
        return $this->scope->appliesTo(Quotation::query(), $viewer);
    }

    private function ageBucket(int $days): string
    {
        return match (true) {
            $days <= 1 => '0-1',
            $days <= 3 => '2-3',
            $days <= 7 => '4-7',
            $days <= 30 => '8-30',
            default => '31+',
        };
    }
}
