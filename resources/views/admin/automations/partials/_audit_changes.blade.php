{{--
    B12-UI / PR 5 — Audit contextual partial (HIST-08, PERM-05, AC-9, SCN-PERM-03).

    Rendered from `admin.automations.show` inside an `@can('automations.audit')`
    guard. Lists `Spatie\Activitylog\Models\Activity` rows for the given rule.

    Expected variable (caller MUST scope this):
        - $auditEntries : LengthAwarePaginator of Spatie\Activitylog\Models\Activity

    Layout uses the V1 `<x-table>` component:
        - title: "Cambios"
        - headers: <th>Fecha</th> <th>Usuario</th> <th>Descripción</th>
        - rows    : one row per activity entry; empty-state when none exist.
--}}

@php
    use Illuminate\Contracts\Pagination\LengthAwarePaginator;
    use Spatie\Activitylog\Models\Activity;

    /** @var LengthAwarePaginator<int, Activity>|null $auditEntries */
    $entries = $auditEntries ?? null;
@endphp

<div id="audit-changes-block" data-testid="audit-changes-block">
    <x-table title="Cambios">
        @slot('headers')
            <tr>
                <th style="width: 14rem;">Fecha</th>
                <th style="width: 16rem;">Usuario</th>
                <th>Descripción</th>
            </tr>
        @endslot

        @slot('rows')
            @forelse ($entries ?? collect() as $activity)
                <tr data-testid="audit-entry-row">
                    <td>
                        <small class="font-monospace">
                            {{ optional($activity->created_at)->setTimezone('America/Lima')->format('Y-m-d H:i:s') }}
                        </small>
                    </td>
                    <td>
                        @if ($activity->causer)
                            <span class="d-inline-block">
                                {{ $activity->causer->name ?? '—' }}
                            </span>
                            <span class="text-muted small">
                                #{{ $activity->causer_id }}
                            </span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td>
                        <span>{{ $activity->description }}</span>
                        @php
                            $properties = $activity->properties?->toArray() ?? [];
                            $attributes = $properties['attributes'] ?? null;
                        @endphp
                        @if (is_array($attributes) && $attributes !== [])
                            <pre class="mt-2 mb-0 small bg-light p-2 rounded border"
                                 style="max-width: 720px; overflow-x: auto;"><code>{{ json_encode($attributes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</code></pre>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="3" class="text-center text-muted">
                        Esta regla aún no tiene cambios registrados en la bitácora.
                    </td>
                </tr>
            @endforelse
        @endslot

        @slot('pagination')
            @include('layouts.partials.pagination', ['paginator' => $entries])
        @endslot
    </x-table>
</div>
