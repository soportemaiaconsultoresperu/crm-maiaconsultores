@extends('layouts.app')

@section('title', 'Calendario')
@section('page-title', 'Calendario')

@section('content')
    <div data-calendar-page>
        @include('calendar.partials._nav', ['view' => $view, 'anchor' => $anchor, 'prevAnchor' => $prevAnchor, 'nextAnchor' => $nextAnchor, 'filters' => $filters, 'owners' => $owners, 'types' => $types])

        <div class="card" data-testid="calendar-month">
        <div class="card-body p-0">
            <table class="table table-bordered mb-0 calendar-month-grid" data-testid="calendar-grid">
                <thead class="table-light">
                    <tr>
                        @foreach (['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'] as $day)
                            <th class="text-center small">{{ $day }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @php
                        $start = $range->start()->startOfWeek(\Carbon\CarbonInterface::MONDAY);
                        $end = $range->end()->endOfWeek(\Carbon\CarbonInterface::SUNDAY);
                        $cursor = $start->copy();
                        $eventsByDay = $events->groupBy(fn ($event) => $event->scheduled_at->toDateString());

                        $newActivityBase = route('activities.create');
                    @endphp
                    @while ($cursor->lte($end))
                        <tr>
                            @for ($i = 0; $i < 7; $i++)
                                @php
                                    $isCurrentMonth = $cursor->month === $anchor->month;
                                    $dayKey = $cursor->toDateString();
                                    $dayEvents = $eventsByDay->get($dayKey, collect());
                                    $cellAnchor = $cursor->format('Y-m-d');
                                @endphp
                                <td class="calendar-cell {{ $isCurrentMonth ? '' : 'bg-light text-secondary' }} {{ $cursor->isToday() ? 'calendar-today' : '' }}"
                                    data-date="{{ $cellAnchor }}" data-testid="calendar-cell">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span class="small fw-medium">{{ $cursor->day }}</span>
                                        <a href="{{ $newActivityBase }}?subject_date={{ $cellAnchor }}"
                                           class="btn btn-sm btn-link p-0 text-secondary" title="Nueva actividad">
                                            <i class="bi bi-plus-lg" aria-hidden="true"></i>
                                        </a>
                                    </div>
                                    @foreach ($dayEvents->take(3) as $event)
                                        <a href="{{ $event->url }}" class="d-block text-truncate small calendar-event calendar-event-{{ $event->status }} calendar-event-{{ $event->kind }}"
                                           data-testid="calendar-event">
                                            <span class="text-secondary">{{ $event->scheduled_at->format('H:i') }}</span>
                                            <span class="badge text-bg-light">{{ $event->typeLabel }}</span>
                                            {{ $event->title }}
                                        </a>
                                    @endforeach
                                    @if ($dayEvents->count() > 3)
                                        <span class="small text-secondary">+{{ $dayEvents->count() - 3 }} más</span>
                                    @endif
                                </td>
                                @php($cursor = $cursor->addDay())
                            @endfor
                        </tr>
                    @endwhile
                </tbody>
            </table>
            </div>
        </div>
    </div>
@endsection
