@extends('layouts.app')

@section('title', 'Calendario — Semana')
@section('page-title', 'Calendario (semana)')

@section('content')
    @include('calendar.partials._nav', ['view' => $view, 'anchor' => $anchor, 'prevAnchor' => $prevAnchor, 'nextAnchor' => $nextAnchor, 'filters' => $filters, 'owners' => $owners, 'types' => $types])

    <div class="card" data-testid="calendar-week">
        <div class="card-body p-0">
            @php
                $start = $range->start()->startOfWeek(\Carbon\CarbonInterface::MONDAY);
                $eventsByDay = $events->groupBy(fn ($event) => $event->scheduled_at->toDateString());
                $weekDays = [];
                for ($i = 0; $i < 7; $i++) {
                    $weekDays[] = $start->copy()->addDays($i);
                }
            @endphp
            <div class="row g-0">
                @foreach ($weekDays as $day)
                    @php
                        $dayKey = $day->toDateString();
                        $dayEvents = $eventsByDay->get($dayKey, collect())->sortBy('scheduled_at');
                        $isToday = $day->isToday();
                    @endphp
                    <div class="col border-end {{ $isToday ? 'bg-warning-subtle' : '' }}" data-testid="week-day">
                        <div class="p-2 border-bottom text-center fw-medium">{{ $day->format('D d/m') }}</div>
                        <div class="p-2" style="min-height: 18rem;">
                            @forelse ($dayEvents as $event)
                                <a href="{{ route('activities.show', $event) }}" class="d-block small mb-1 calendar-event calendar-event-{{ $event->status }}">
                                    <span class="text-secondary">{{ $event->scheduled_at->format('H:i') }}</span>
                                    {{ $event->title }}
                                </a>
                            @empty
                                <p class="text-secondary small mb-0">Sin actividades.</p>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endsection
