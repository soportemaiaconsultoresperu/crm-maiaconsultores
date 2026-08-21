@extends('layouts.app')

@section('title', 'Entrada de auditoría #'.$entry->id)
@section('page-title', 'Auditoría — entrada #'.$entry->id)

@section('content')
    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><h3 class="card-title mb-0">Cabecera</h3></div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-sm-4">Fecha</dt>
                        <dd class="col-sm-8">{{ $entry->created_at->format('d/m/Y H:i:s') }}</dd>

                        <dt class="col-sm-4">Evento</dt>
                        <dd class="col-sm-8"><span class="badge text-bg-secondary">{{ $entry->event }}</span></dd>

                        <dt class="col-sm-4">Sujeto</dt>
                        <dd class="col-sm-8">
                            {{ $entry->subject_type }}
                            @if ($entry->subject_id) <span class="text-secondary">#{{ $entry->subject_id }}</span> @endif
                        </dd>

                        <dt class="col-sm-4">Usuario</dt>
                        <dd class="col-sm-8">
                            {{ $entry->causer?->name ?? '—' }}
                            @if ($entry->causer_id) <span class="text-secondary">#{{ $entry->causer_id }}</span> @endif
                        </dd>

                        <dt class="col-sm-4">Descripción</dt>
                        <dd class="col-sm-8">{{ $entry->description }}</dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><h3 class="card-title mb-0">Propiedades</h3></div>
                <div class="card-body">
                    @php($oldProps = $properties->get('old', collect()))
                    @php($newProps = $properties->get('attributes', collect()))

                    @if ($oldProps->isNotEmpty() || $newProps->isNotEmpty())
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead>
                                    <tr>
                                        <th>Atributo</th>
                                        <th>Antes</th>
                                        <th>Después</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach (collect(array_merge((array) $oldProps, (array) $newProps))->keys() as $key)
                                        <tr>
                                            <td class="small">{{ $key }}</td>
                                            <td class="small text-danger">{{ $oldProps[$key] ?? '—' }}</td>
                                            <td class="small text-success">{{ $newProps[$key] ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @if ($properties->isNotEmpty())
                        <h4 class="h6 mt-3">Payload completo</h4>
                        <pre class="bg-light p-2 small rounded">{{ json_encode($properties->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                    @else
                        <p class="text-secondary small mb-0">Sin propiedades adicionales.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="mt-3">
        <a href="{{ route('admin.audit.index', request()->query()) }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Volver al listado
        </a>
    </div>
@endsection