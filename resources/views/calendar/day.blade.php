@extends('layouts.app')

@section('title', 'Calendario — Día')
@section('page-title', 'Calendario (día)')

@section('content')
    @include('calendar.partials._nav', ['view' => $view, 'anchor' => $anchor, 'prevAnchor' => $prevAnchor, 'nextAnchor' => $nextAnchor, 'filters' => $filters, 'owners' => $owners, 'types' => $types])

    <div class="card" data-testid="calendar-day">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h3 class="card-title mb-0">{{ $anchor->format('l, d \\d\\e F \\d\\e Y') }}</h3>
            <span class="badge text-bg-light">{{ $events->count() }} actividad(es)</span>
        </div>
        <div class="card-body p-0">
            @php
                $eventsByHour = $events->groupBy(fn ($event) => $event->scheduled_at->format('H'));
            @endphp
            <ul class="list-group list-group-flush">
                @for ($hour = 0; $hour < 24; $hour++)
                    @php
                        $hourKey = str_pad((string) $hour, 2, '0', STR_PAD_LEFT);
                        $hourEvents = $eventsByHour->get($hourKey, collect());
                    @endphp
                    <li class="list-group-item">
                        <div class="d-flex gap-3">
                            <div class="text-secondary small" style="min-width: 4rem;">{{ $hourKey }}:00</div>
                            <div class="flex-grow-1">
                                @forelse ($hourEvents as $event)
                                    <a href="{{ route('activities.show', $event) }}" class="d-block mb-1 calendar-event calendar-event-{{ $event->status }}">
                                        <strong>{{ $event->scheduled_at->format('H:i') }}</strong>
                                        — {{ $event->title }}
                                        <span class="text-secondary">({{ $event->type?->name ?? '—' }})</span>
                                    </a>
                                @empty
                                    <span class="text-secondary small">—</span>
                                @endforelse
                            </div>
                        </div>
                    </li>
                @endfor
            </ul>
        </div>
    </div>
@endsection
