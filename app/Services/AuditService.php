<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

/**
 * Audit viewer (B08 / RF-USR-007 + ADR-008).
 *
 * The audit log itself is owned by spatie/laravel-activitylog: every
 * domain service writes Activity rows through the `activity()` helper
 * or directly via the Activity model. This service is the read-only
 * side: it knows how to filter the activity_log table by subject,
 * causer, event and date range, paginate the result, and eagerly load
 * the related records so the Blade view doesn't trigger N+1 queries.
 *
 * Audit rows are never physically mutated or deleted by this service:
 * ADR-008 states the log is append-only, and the cleanup is owned by
 * the `activitylog:clean` command configured at 365 days.
 */
class AuditService
{
    /**
     * Page size for the audit viewer (RF-USR-007). Mirrors
     * settings.pagination_size default.
     */
    public const PER_PAGE = 25;

    /**
     * Build the filtered, paginated query for the audit viewer.
     *
     * Supported filters:
     *  - subject_type: FQCN of the affected model (e.g. App\Models\Lead).
     *  - subject_id: numeric id within that subject type.
     *  - user_id: causer user id (the actor that triggered the change).
     *  - event: the activity_log.event column (user-created, lead-reassigned, ...).
     *  - date_from / date_to: ISO date (Y-m-d) bounds on created_at.
     *
     * @param  array<string, mixed>  $filters
     */
    public function query(array $filters, User $viewer): LengthAwarePaginator
    {
        $query = $this->baseQuery();

        $this->applyFilters($query, $filters);

        return $query
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * Return a fully-loaded audit row for the "show" view. The
     * `properties` column is already cast to a Collection by the
     * vendor model; we just refresh the relation graph (causer +
     * subject) so the controller never has to call ->load() itself.
     */
    public function show(Activity $entry): Activity
    {
        return $entry->loadMissing(['causer', 'subject']);
    }

    /**
     * Return the underlying query builder so other services (exports,
     * reports) can reuse the same filtering logic without paginating.
     */
    public function builder(array $filters): Builder
    {
        $query = $this->baseQuery();
        $this->applyFilters($query, $filters);

        return $query;
    }

    /**
     * Base query: ordered newest-first, eager-loading the morph relations
     * so the listing view renders without N+1 queries.
     */
    private function baseQuery(): Builder
    {
        return Activity::query()
            ->with(['causer', 'subject'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * Apply each present filter with safe binding. Empty values are
     * skipped so the test suite can pass an empty array and still get
     * the full list.
     *
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['subject_type'])) {
            $query->where('subject_type', (string) $filters['subject_type']);
        }

        if (! empty($filters['subject_id'])) {
            $query->where('subject_id', (int) $filters['subject_id']);
        }

        if (! empty($filters['user_id'])) {
            $query->where('causer_id', (int) $filters['user_id']);
        }

        if (! empty($filters['event'])) {
            $query->where('event', (string) $filters['event']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', (string) $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', (string) $filters['date_to']);
        }

        if (! empty($filters['causer_type'])) {
            $query->where('causer_type', (string) $filters['causer_type']);
        }
    }
}