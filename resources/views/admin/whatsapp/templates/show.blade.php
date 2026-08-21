@extends('layouts.app')

@section('title', 'Plantilla '.$template->name)
@section('page-title', $template->name.' ('.$template->language.')')

@section('content')
    <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="{{ route('whatsapp.templates.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Plantillas
        </a>
        <a href="{{ route('whatsapp.accounts.show', $template->account_id) }}" class="btn btn-outline-info">
            <i class="bi bi-phone me-1" aria-hidden="true"></i>
            Ver cuenta
        </a>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title mb-0">
                        {{ $template->name }}
                        <span class="badge bg-{{ $template->status === 'approved' ? 'success' : ($template->status === 'rejected' ? 'danger' : 'secondary') }} ms-2">
                            {{ $template->status }}
                        </span>
                    </h3>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Idioma</dt>
                        <dd class="col-sm-8">{{ $template->language }}</dd>

                        <dt class="col-sm-4">Categoría</dt>
                        <dd class="col-sm-8">{{ $template->category ?: '—' }}</dd>

                        <dt class="col-sm-4">Header</dt>
                        <dd class="col-sm-8">
                            {{ $template->header_kind ?: '—' }}
                            @if ($template->header_text)
                                <div class="text-muted small">{{ $template->header_text }}</div>
                            @endif
                        </dd>

                        <dt class="col-sm-4">Footer</dt>
                        <dd class="col-sm-8">{{ $template->footer_text ?: '—' }}</dd>

                        <dt class="col-sm-4">Variables</dt>
                        <dd class="col-sm-8">
                            @forelse ($template->variables_json ?? [] as $var)
                                <code>{{ $var }}</code>@if (! $loop->last), @endif
                            @empty
                                <span class="text-muted">—</span>
                            @endforelse
                        </dd>

                        <dt class="col-sm-4">Aprobada</dt>
                        <dd class="col-sm-8">{{ optional($template->approved_at)->format('Y-m-d H:i') ?: '—' }}</dd>

                        <dt class="col-sm-4">Sincronizada</dt>
                        <dd class="col-sm-8">{{ optional($template->synced_at)->format('Y-m-d H:i') ?: '—' }}</dd>

                        @if ($template->rejected_reason)
                            <dt class="col-sm-4">Motivo de rechazo</dt>
                            <dd class="col-sm-8 text-danger">{{ $template->rejected_reason }}</dd>
                        @endif
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><h3 class="card-title mb-0">Cuerpo</h3></div>
                <div class="card-body">
                    <pre class="mb-0" style="white-space: pre-wrap;">{{ $template->body ?: '—' }}</pre>
                </div>
            </div>
        </div>
    </div>

    <x-table title="Mensajes recientes usando esta plantilla">
        @slot('headers')
            <tr>
                <th>ID</th>
                <th>Conversación</th>
                <th>Dirección</th>
                <th>Estado</th>
                <th>Enviado</th>
            </tr>
        @endslot

        @slot('rows')
            @forelse ($recentMessages as $message)
                <tr>
                    <td>{{ $message->id }}</td>
                    <td>
                        <a href="{{ route('whatsapp.conversations.show', $message->conversation_id) }}">
                            {{ $message->conversation?->contact_name ?: $message->conversation?->phone_number ?? '—' }}
                        </a>
                    </td>
                    <td>{{ $message->direction }}</td>
                    <td>
                        <span class="badge bg-{{ $message->status === 'sent' || $message->status === 'delivered' || $message->status === 'read' ? 'success' : ($message->status === 'failed' ? 'danger' : 'secondary') }}">
                            {{ $message->status }}
                        </span>
                    </td>
                    <td>{{ optional($message->sent_at)->format('Y-m-d H:i') ?: '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="text-center text-muted">
                        Esta plantilla aún no se ha enviado.
                    </td>
                </tr>
            @endforelse
        @endslot
    </x-table>
@endsection