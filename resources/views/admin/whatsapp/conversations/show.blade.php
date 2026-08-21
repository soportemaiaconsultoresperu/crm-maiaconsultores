@extends('layouts.app')

@section('title', 'Conversación '.$conversation->phone_number)
@section('page-title', 'Conversación '.$conversation->phone_number)

@section('content')
    <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="{{ route('whatsapp.conversations.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Bandeja
        </a>

        @can('whatsapp.conversation.assign')
            <button type="button" class="btn btn-outline-secondary"
                    data-bs-toggle="modal" data-bs-target="#assign-modal">
                <i class="bi bi-person-arrows me-1" aria-hidden="true"></i>
                Asignar
            </button>
        @endcan

        @can('whatsapp.send')
            @if ($conversation->status !== 'closed')
                <form method="POST" action="{{ route('whatsapp.conversations.close', $conversation) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-outline-warning"
                            onclick="return confirm('¿Cerrar la conversación?')">
                        <i class="bi bi-x-circle me-1" aria-hidden="true"></i>
                        Cerrar conversación
                    </button>
                </form>
            @endif

            @if ($conversation->opt_out_at === null)
                <form method="POST" action="{{ route('whatsapp.conversations.opt_out', $conversation) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-outline-danger"
                            onclick="return confirm('¿Registrar opt-out para esta conversación? Meta bloqueará futuros envíos.')">
                        <i class="bi bi-shield-slash me-1" aria-hidden="true"></i>
                        Registrar opt-out
                    </button>
                </form>
            @endif
        @endcan
    </div>

    <div class="card mb-3">
        <div class="card-header">
            <h3 class="card-title mb-0">
                {{ $conversation->contact_name ?: $conversation->phone_number }}
            </h3>
        </div>
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3">Estado</dt>
                <dd class="col-sm-9">
                    <span class="badge bg-{{ $conversation->status === 'open' ? 'success' : ($conversation->status === 'opted_out' ? 'danger' : 'secondary') }}">
                        {{ $conversation->status }}
                    </span>
                </dd>

                <dt class="col-sm-3">Teléfono</dt>
                <dd class="col-sm-9"><code>{{ $conversation->phone_number }}</code></dd>

                <dt class="col-sm-3">Cuenta</dt>
                <dd class="col-sm-9">
                    <a href="{{ route('whatsapp.accounts.show', $conversation->account_id) }}">
                        {{ $conversation->account?->display_name ?? '—' }}
                    </a>
                </dd>

                <dt class="col-sm-3">Asignado a</dt>
                <dd class="col-sm-9">{{ $conversation->assignee?->name ?? '—' }}</dd>

                <dt class="col-sm-3">Último mensaje</dt>
                <dd class="col-sm-9">{{ optional($conversation->last_message_at)->format('Y-m-d H:i') ?: '—' }}</dd>

                @if ($conversation->opt_out_at)
                    <dt class="col-sm-3">Opt-out</dt>
                    <dd class="col-sm-9">
                        <span class="badge bg-danger">{{ $conversation->opt_out_at->format('Y-m-d H:i') }}</span>
                    </dd>
                @endif
            </dl>
        </div>
    </div>

    <livewire:admin.whatsapp.message-list :conversation-id="$conversation->id" />

    @can('whatsapp.conversation.assign')
        <x-modal id="assign-modal" title="Asignar conversación">
            <form method="POST" action="{{ route('whatsapp.conversations.assign', $conversation) }}">
                @csrf
                <p class="text-secondary">
                    Asignar la conversación a un vendedor o supervisor. El
                    actor debe estar en el equipo del asignado (decisión 9:
                    asignación respeta <code>DataScopeService</code>).
                </p>
                <div class="mb-3">
                    <x-label for="assigned_to" label="Nuevo asignado" :required="true"/>
                    <select name="assigned_to" id="assigned_to"
                            class="form-select @error('assigned_to') is-invalid @enderror"
                            required>
                        @foreach (\App\Models\User::query()->where('is_active', true)->orderBy('name')->get() as $user)
                            <option value="{{ $user->id }}" @selected(old('assigned_to', $conversation->assigned_to) == $user->id)>
                                {{ $user->name }}
                            </option>
                        @endforeach
                    </select>
                    <x-validation-error name="assigned_to" />
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Asignar</button>
                </div>
            </form>
        </x-modal>
    @endcan
@endsection