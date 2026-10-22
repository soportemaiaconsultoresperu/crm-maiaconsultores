@extends('layouts.app')

@section('title', 'Editar '.$ticket->code)
@section('page-title', 'Editar ticket '.$ticket->code)

@section('content')
    <form method="POST" action="{{ route('support.tickets.update', $ticket) }}" data-swal-loading="Guardando cambios...">
        @csrf
        @method('PUT')

        <div class="card">
            <div class="card-header">
                <h2 class="h6 mb-0">Editar ticket</h2>
                <p class="text-muted small mb-0">Cliente, contacto, prioridad, responsable y estado se gestionan desde la ficha del ticket.</p>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12">
                        <x-text-input name="title" label="Título" :value="$ticket->title" required maxlength="200" />
                    </div>

                    @foreach (['type_id' => ['Tipo', $types, $ticket->type_id], 'category_id' => ['Categoría', $categories, $ticket->category_id], 'channel_id' => ['Canal', $channels, $ticket->channel_id]] as $field => [$label, $options, $current])
                        <div class="col-md-4">
                            <label class="form-label" for="{{ $field }}">{{ $label }} <span class="text-danger">*</span></label>
                            <select id="{{ $field }}" name="{{ $field }}" class="form-select @error($field) is-invalid @enderror" required>
                                <option value="">Seleccione</option>
                                @foreach ($options as $option)
                                    <option value="{{ $option->id }}" @selected(old($field, $current) == $option->id)>{{ $option->name }}</option>
                                @endforeach
                            </select>
                            <x-validation-error :name="$field" />
                        </div>
                    @endforeach

                    <div class="col-12">
                        <label class="form-label" for="description">Descripción <span class="text-danger">*</span></label>
                        <textarea id="description" name="description" rows="5" class="form-control @error('description') is-invalid @enderror" required>{{ old('description', $ticket->description) }}</textarea>
                        <x-validation-error name="description" />
                    </div>

                    <div class="col-md-4">
                        <x-text-input name="impact" label="Impacto" :value="$ticket->impact" maxlength="100" />
                    </div>
                    <div class="col-md-8">
                        <label class="form-label" for="general_observations">Observaciones generales</label>
                        <textarea id="general_observations" name="general_observations" rows="2" class="form-control @error('general_observations') is-invalid @enderror">{{ old('general_observations', $ticket->general_observations) }}</textarea>
                        <x-validation-error name="general_observations" />
                    </div>
                </div>
            </div>
            <div class="card-footer d-flex justify-content-end gap-2">
                <a href="{{ route('support.tickets.show', $ticket) }}" class="btn btn-outline-secondary">Volver</a>
                <button type="submit" class="btn btn-primary">Guardar cambios</button>
            </div>
        </div>
    </form>
@endsection
