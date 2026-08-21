{{--
    Internal notifications (RF-NOT-001): database-channel list for the
    authenticated user, unread items highlighted, mark-one / mark-all as
    read. Reached from the navbar bell.
--}}
@extends('layouts.app')

@section('title', 'Notificaciones')
@section('page-title', 'Notificaciones')

@section('content')
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h3 class="card-title mb-0">
                Notificaciones
                @if ($unreadCount > 0)
                    <span class="badge text-bg-danger ms-1" data-testid="unread-badge">{{ $unreadCount }} sin leer</span>
                @endif
            </h3>
            @if ($unreadCount > 0)
                <form method="POST" action="{{ route('notifications.mark-read') }}" data-testid="mark-all-form">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-check2-all me-1" aria-hidden="true"></i> Marcar todas como leídas
                    </button>
                </form>
            @endif
        </div>

        <ul class="list-group list-group-flush" data-testid="notifications-list">
            @forelse ($notifications as $notification)
                @php($data = $notification->data)
                <li class="list-group-item d-flex justify-content-between align-items-start gap-3 {{ $notification->read_at === null ? 'fw-medium bg-body-tertiary' : '' }}"
                    data-testid="notification-item" data-unread="{{ $notification->read_at === null ? 'true' : 'false' }}">
                    <div>
                        <div>{{ $data['message'] ?? ($data['title'] ?? 'Notificación') }}</div>
                        <div class="small text-secondary fw-normal">{{ $notification->created_at?->format('d/m/Y H:i') }}</div>
                    </div>
                    @if ($notification->read_at === null)
                        <form method="POST" action="{{ route('notifications.mark-read') }}">
                            @csrf
                            <input type="hidden" name="id" value="{{ $notification->id }}">
                            <button type="submit" class="btn btn-sm btn-outline-secondary" data-testid="btn-mark-read">
                                Marcar leída
                            </button>
                        </form>
                    @endif
                </li>
            @empty
                <li class="list-group-item">
                    @include('layouts.partials.empty-state', [
                        'message' => 'No tiene notificaciones.',
                        'hint' => 'Aquí aparecerán avisos de oportunidades asignadas o cambios de etapa.',
                    ])
                </li>
            @endforelse
        </ul>

        <div class="card-footer">
            @include('layouts.partials.pagination', ['paginator' => $notifications])
        </div>
    </div>
@endsection
