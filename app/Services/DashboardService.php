<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Opportunity;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard aggregation service (RF-DASH-001..003).
 *
 * All aggregates are computed against the requester's data scope
 * (ADR-006) and respect multimoneda (ADR-004): amounts are returned
 * grouped by currency code, never converted nor consolidated.
 *
 * The payload is plain associative arrays so the UI layer (Tanda B)
 * can consume it without coupling to Eloquent collections.
 */
class DashboardService
{
    public function __construct(private readonly DataScopeService $scope) {}

    /**
     * Build the dashboard payload for the given viewer.
     *
     * @return array<string, mixed>
     */
    public function forUser(User $viewer): array
    {
        return [
            'prospectos_nuevos' => $this->prospectosNuevos($viewer),
            'prospectos_sin_contactar' => $this->prospectosSinContactar($viewer),
            'oportunidades_abiertas' => $this->oportunidadesAbiertas($viewer),
            'valor_embudo_by_currency' => $this->valorEmbudoByCurrency($viewer),
            'ventas_ganadas_count' => $this->ventasGanadasCount($viewer),
            'monto_ganado_by_currency' => $this->montoGanadoByCurrency($viewer),
            'oportunidades_perdidas_count' => $this->oportunidadesPerdidasCount($viewer),
            'actividades_pendientes' => $this->actividadesPendientes($viewer),
            'actividades_vencidas' => $this->actividadesVencidas($viewer),
            'proximas_reuniones' => $this->proximasReuniones($viewer),
            'conversiones_por_etapa' => $this->conversionesPorEtapa($viewer),
            'rendimiento_por_vendedor' => $this->rendimientoPorVendedor($viewer),
            'actividad_por_dia' => $this->actividadPorDia($viewer),
            'prospectos_por_origen' => $this->prospectosPorOrigen($viewer),
        ];
    }

    /**
     * Leads in "nuevo" status whose entered_at falls inside the current
     * ISO week (scoped by ADR-006).
     */
    private function prospectosNuevos(User $viewer): int
    {
        $query = $this->scopedLeads($viewer)
            ->whereHas('status', fn (Builder $q) => $q->where('slug', 'nuevo'));

        $start = now()->startOfWeek();
        $end = now()->endOfWeek();

        return $query->whereBetween('entered_at', [$start, $end])->count();
    }

    /**
     * Leads in "nuevo" status with no activities attached yet.
     */
    private function prospectosSinContactar(User $viewer): int
    {
        $query = $this->scopedLeads($viewer)
            ->whereHas('status', fn (Builder $q) => $q->where('slug', 'nuevo'))
            ->whereDoesntHave('activities');

        return $query->count();
    }

    /**
     * Open opportunities (stage.stage_type = open).
     */
    private function oportunidadesAbiertas(User $viewer): int
    {
        return $this->scopedOpportunities($viewer)
            ->whereHas('stage', fn (Builder $q) => $q->where('stage_type', 'open'))
            ->count();
    }

    /**
     * Sum of estimated_amount grouped by currency_code for open
     * opportunities (the funnel value).
     *
     * @return array<string, float>
     */
    private function valorEmbudoByCurrency(User $viewer): array
    {
        $rows = $this->scopedOpportunities($viewer)
            ->whereHas('stage', fn (Builder $q) => $q->where('stage_type', 'open'))
            ->select('currency_code', DB::raw('SUM(estimated_amount) as total'))
            ->groupBy('currency_code')
            ->get();

        return $this->normalizeBuckets($rows);
    }

    /**
     * Opportunities won in the current month.
     */
    private function ventasGanadasCount(User $viewer): int
    {
        return $this->scopedOpportunities($viewer)
            ->whereHas('stage', fn (Builder $q) => $q->where('stage_type', 'won'))
            ->whereBetween('closed_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();
    }

    /**
     * Sum of final_amount (or estimated_amount as fallback) for opportunities
     * won in the current month, grouped by currency (ADR-004).
     *
     * @return array<string, float>
     */
    private function montoGanadoByCurrency(User $viewer): array
    {
        $rows = $this->scopedOpportunities($viewer)
            ->whereHas('stage', fn (Builder $q) => $q->where('stage_type', 'won'))
            ->whereBetween('closed_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->select('currency_code', DB::raw('COALESCE(SUM(final_amount), SUM(estimated_amount)) as total'))
            ->groupBy('currency_code')
            ->get();

        return $this->normalizeBuckets($rows);
    }

    /**
     * Opportunities lost in the current month.
     */
    private function oportunidadesPerdidasCount(User $viewer): int
    {
        return $this->scopedOpportunities($viewer)
            ->whereHas('stage', fn (Builder $q) => $q->where('stage_type', 'lost'))
            ->whereBetween('closed_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();
    }

    /**
     * Activities still pending execution in the future.
     */
    private function actividadesPendientes(User $viewer): int
    {
        return $this->scopedActivities($viewer)
            ->where('status', 'pending')
            ->where('scheduled_at', '>=', now())
            ->count();
    }

    /**
     * Overdue activities: pending in the past + status=overdue.
     * The persisted status may lag behind the scheduler (double source per
     * docs/BASE_DATOS.md §3.5); the WHERE re-derives both conditions.
     */
    private function actividadesVencidas(User $viewer): int
    {
        $now = now();

        return $this->scopedActivities($viewer)
            ->where(function (Builder $q) use ($now): void {
                $q->where('status', 'overdue')
                    ->orWhere(function (Builder $inner) use ($now): void {
                        $inner->where('status', 'pending')
                            ->where('scheduled_at', '<', $now);
                    });
            })
            ->count();
    }

    /**
     * Next 5 future meetings (reuniones / visitas) ordered asc by
     * scheduled_at. "reunion" matches both `title LIKE reunion%` (spanish
     * display) and the `reunion` / `visita` activity_type slugs.
     *
     * @return list<array<string, mixed>>
     */
    private function proximasReuniones(User $viewer): array
    {
        $typeIds = ActivityType::query()
            ->whereIn('slug', ['reunion', 'visita'])
            ->pluck('id')
            ->all();

        $query = $this->scopedActivities($viewer)
            ->where('scheduled_at', '>=', now())
            ->where('status', 'pending')
            ->where(function (Builder $q) use ($typeIds): void {
                $q->whereIn('type_id', $typeIds)
                    ->orWhere('title', 'like', '%reunion%');
            })
            ->orderBy('scheduled_at', 'asc')
            ->limit(5)
            ->with(['owner', 'type']);

        return $query->get()->map(function (Activity $activity): array {
            return [
                'id' => $activity->id,
                'title' => $activity->title,
                'scheduled_at' => $activity->scheduled_at?->toIso8601String(),
                'owner' => $activity->owner?->name,
                'type' => $activity->type?->name,
            ];
        })->all();
    }

    /**
     * Opportunities count by pipeline stage (RF-DASH-002). Open + closed:
     * each stage reports its current opportunity count regardless of
     * stage_type, which is what the funnel visualization needs.
     *
     * @return list<array<string, mixed>>
     */
    private function conversionesPorEtapa(User $viewer): array
    {
        return PipelineStage::query()
            ->where('is_active', true)
            ->orderBy('sort')
            ->withCount(['opportunities' => function (Builder $q) use ($viewer): void {
                $this->scope->appliesTo($q, $viewer);
            }])
            ->get()
            ->map(fn (PipelineStage $stage): array => [
                'stage_id' => $stage->id,
                'name' => $stage->name,
                'stage_type' => $stage->stage_type,
                'count' => (int) $stage->opportunities_count,
            ])
            ->all();
    }

    /**
     * Last seven days of commercial activity as counts only. This powers the
     * dependency-free line chart without mixing monetary currencies.
     *
     * @return list<array<string, mixed>>
     */
    private function actividadPorDia(User $viewer): array
    {
        $days = [];
        $start = now()->subDays(6)->startOfDay();
        $end = now()->endOfDay();
        $leadCounts = $this->countsByDay($this->scopedLeads($viewer), 'entered_at', $start, $end);
        $opportunityCounts = $this->countsByDay($this->scopedOpportunities($viewer), 'created_at', $start, $end);
        $activityCounts = $this->countsByDay($this->scopedActivities($viewer), 'scheduled_at', $start, $end);

        for ($offset = 0; $offset < 7; $offset++) {
            $day = $start->copy()->addDays($offset);
            $key = $day->toDateString();
            $leadsCount = $leadCounts[$key] ?? 0;
            $opportunitiesCount = $opportunityCounts[$key] ?? 0;
            $activitiesCount = $activityCounts[$key] ?? 0;

            $days[] = [
                'date' => $key,
                'label' => $day->translatedFormat('d M'),
                'leads_count' => $leadsCount,
                'opportunities_count' => $opportunitiesCount,
                'activities_count' => $activitiesCount,
                'total_count' => $leadsCount + $opportunitiesCount + $activitiesCount,
            ];
        }

        return $days;
    }

    /**
     * Top lead sources for the current month as counts only. Source labels are
     * kept separate from any monetary amount to preserve multimoneda safety.
     *
     * @return list<array<string, mixed>>
     */
    private function prospectosPorOrigen(User $viewer): array
    {
        $start = now()->startOfMonth();
        $end = now()->endOfMonth();

        return LeadSource::query()
            ->where('is_active', true)
            ->withCount(['leads as leads_count' => function (Builder $q) use ($viewer, $start, $end): void {
                $this->scope->appliesTo($q, $viewer);
                $q->whereBetween('entered_at', [$start, $end]);
            }])
            ->orderByDesc('leads_count')
            ->orderBy('name')
            ->limit(6)
            ->get()
            ->map(fn (LeadSource $source): array => [
                'source_id' => $source->id,
                'name' => $source->name,
                'count' => (int) $source->leads_count,
            ])
            ->all();
    }

    /**
     * Per-salesperson won count + won amount by currency for the current
     * month (RF-DASH-003). The amount stays grouped by currency (ADR-004).
     *
     * @return list<array<string, mixed>>
     */
    private function rendimientoPorVendedor(User $viewer): array
    {
        $start = now()->startOfMonth();
        $end = now()->endOfMonth();

        $rows = $this->scopedOpportunities($viewer)
            ->whereHas('stage', fn (Builder $q) => $q->where('stage_type', 'won'))
            ->whereBetween('closed_at', [$start, $end])
            ->select(
                'owner_id',
                'currency_code',
                DB::raw('COUNT(*) as won_count'),
                DB::raw('COALESCE(SUM(final_amount), SUM(estimated_amount)) as won_amount'),
            )
            ->groupBy('owner_id', 'currency_code')
            ->orderBy('owner_id')
            ->get();

        return $rows->map(function ($row): array {
            $owner = User::find($row->owner_id);

            return [
                'owner_id' => (int) $row->owner_id,
                'owner' => $owner?->name,
                'currency_code' => $row->currency_code,
                'won_count' => (int) $row->won_count,
                'won_amount' => (float) $row->won_amount,
            ];
        })->all();
    }

    /**
     * @return array<string, int>
     */
    private function countsByDay(Builder $query, string $column, $start, $end): array
    {
        $dateExpression = "DATE({$column})";

        return $query
            ->whereBetween($column, [$start, $end])
            ->selectRaw("{$dateExpression} as day, COUNT(*) as aggregate_count")
            ->groupBy(DB::raw($dateExpression))
            ->pluck('aggregate_count', 'day')
            ->mapWithKeys(fn ($count, $day): array => [(string) $day => (int) $count])
            ->all();
    }

    /**
     * @return Builder<Lead>
     */
    private function scopedLeads(User $viewer): Builder
    {
        return $this->scope->appliesTo(Lead::query(), $viewer);
    }

    /**
     * @return Builder<Opportunity>
     */
    private function scopedOpportunities(User $viewer): Builder
    {
        return $this->scope->appliesTo(Opportunity::query(), $viewer);
    }

    /**
     * @return Builder<Activity>
     */
    private function scopedActivities(User $viewer): Builder
    {
        return $this->scope->appliesTo(Activity::query(), $viewer);
    }

    /**
     * Cast a query result with `currency_code` and `total` columns to a
     * plain associative array keyed by ISO code.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @return array<string, float>
     */
    private function normalizeBuckets($rows): array
    {
        $buckets = [];

        foreach ($rows as $row) {
            $code = (string) ($row->currency_code ?? '');
            $buckets[$code] = (float) $row->total;
        }

        return $buckets;
    }
}
