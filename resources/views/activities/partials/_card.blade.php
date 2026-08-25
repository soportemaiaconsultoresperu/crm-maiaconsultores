{{--
    Compact activity card used in the activity show page and inside subject
    show pages (leads, customers, opportunities). Receives the activity with
    its owner / type / subject relations eager-loaded.
--}}
@php
    $subject = $activity->subject;
    $subjectLabel = '—';
    $subjectRoute = null;
    $subjectKey = null;

    if ($subject) {
        $subjectKey = match (true) {
            $subject instanceof \App\Models\Lead => 'lead',
            $subject instanceof \App\Models\Customer => 'customer',
            $subject instanceof \App\Models\Opportunity => 'opportunity',
            default => null,
        };

        $subjectRoute = match ($subjectKey) {
            'lead' => route('leads.show', $subject),
            'customer' => route('customers.show', $subject),
            'opportunity' => route('opportunities.show', $subject),
            default => null,
        };

        $subjectLabel = \App\Models\Activity::subjectDisplayLabel($subject);
    }
@endphp

<div class="d-flex flex-column gap-1" data-testid="activity-card">
    <div class="d-flex align-items-center gap-2">
        <i class="bi {{ $activity->type?->slug === 'reunion' ? 'bi-people' : 'bi-chat-left-text' }} text-secondary" aria-hidden="true"></i>
        <span class="fw-medium">{{ $activity->title }}</span>
        <x-badge-status :status="$activity->status" class="ms-auto"/>
    </div>
    <div class="small text-secondary">
        <i class="bi bi-calendar-event me-1" aria-hidden="true"></i>
        {{ $activity->scheduled_at?->format('d/m/Y H:i') ?? '—' }}
        — {{ $activity->type?->name ?? '—' }}
    </div>
    <div class="small text-secondary">
        <i class="bi bi-person me-1" aria-hidden="true"></i>
        {{ $activity->owner?->name ?? '—' }}
    </div>
    @if ($subject && $subjectRoute)
        <div class="small">
            <span class="text-secondary">Sujeto:</span>
            <a href="{{ $subjectRoute }}">{{ $subjectLabel }}</a>
        </div>
    @endif
    @if ($activity->result)
        <div class="small text-secondary">
            <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>
            {{ $activity->result }}
        </div>
    @endif
    <div>
        <a href="{{ route('activities.show', $activity) }}" class="btn btn-sm btn-outline-secondary mt-1">
            <i class="bi bi-eye me-1" aria-hidden="true"></i> Ver actividad
        </a>
    </div>
</div>
