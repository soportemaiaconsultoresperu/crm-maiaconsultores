@extends('layouts.app')

@section('title', 'Nueva edición')
@section('page-title', 'Nueva edición')

@section('content')
    @php
        $modalityOptions = collect($modalities)->mapWithKeys(fn ($modality) => [$modality->value => $modality->label()])->all();
        $syllabus = old('syllabus_override_json', []);
        $syllabus = is_array($syllabus) && $syllabus !== [] ? array_values($syllabus) : [''];
        // A nested-array entry survives StoreCourseEditionRequest::prepareForValidation()
        // so the `string` rule can reject it; on the re-rendered form it must still
        // print as text instead of reaching htmlspecialchars() as an array (which
        // would return a 500 instead of the intended validation error).
        $syllabus = array_map(
            static fn ($topic): string => match (true) {
                $topic === null => '',
                is_scalar($topic) => (string) $topic,
                default => (json_encode($topic, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
            },
            $syllabus,
        );
    @endphp

    <a href="{{ route('course-talks.activities.show', $activity) }}" class="btn btn-outline-secondary mb-3">Volver a {{ $activity->name }}</a>

    {{-- Field-less InvalidCourseEditionData failures are reported on the generic
         `edition` error key, which no form field renders. Without this block
         they would be invisible to the user. --}}
    @if ($errors->has('edition'))
        <x-alert type="error" data-testid="course-talks-edition-error">{{ $errors->first('edition') }}</x-alert>
    @endif

    <form method="POST" action="{{ route('course-talks.editions.store', $activity) }}" data-testid="course-talks-edition-create-form">
        @csrf

        <div class="card">
            <div class="card-header">
                <h3 class="card-title mb-0">Nueva edición de {{ $activity->name }}</h3>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <x-text-input name="code" label="Código" maxlength="60" help="Opcional. Debe ser único entre todas las ediciones."/>
                    </div>
                    <div class="col-md-4">
                        <x-select name="modality" label="Modalidad" :options="$modalityOptions" :value="old('modality')" placeholder="Seleccione" :required="true"/>
                    </div>
                    <div class="col-md-4">
                        <x-text-input name="price_amount" type="number" label="Precio"
                                      :value="old('price_amount')" step="0.01" min="0"/>
                    </div>
                    <div class="col-md-3">
                        <x-text-input name="starts_on" type="date" label="Fecha de inicio" :value="old('starts_on')"/>
                    </div>
                    <div class="col-md-3">
                        <x-text-input name="ends_on" type="date" label="Fecha de fin" :value="old('ends_on')"/>
                    </div>
                    <div class="col-md-6">
                        <x-text-input name="address" label="Dirección" maxlength="255"
                                      help="Requerida para modalidad Presencial o Híbrida."/>
                    </div>
                    <div class="col-md-6">
                        <x-text-input name="access_url" label="Enlace de acceso virtual" maxlength="255"
                                      help="Requerido para modalidad Virtual o Híbrida."/>
                    </div>
                    <div class="col-12">
                        <x-label for="syllabus_override_json" label="Temario de la edición"/>
                        @foreach ($syllabus as $topic)
                            <input type="text" name="syllabus_override_json[]" value="{{ $topic }}"
                                   class="form-control mb-2 @error('syllabus_override_json.*') is-invalid @enderror"
                                   maxlength="255" placeholder="Tema {{ $loop->iteration }}">
                        @endforeach
                        <x-validation-error name="syllabus_override_json.*"/>
                        <div class="form-text">Opcional. Deje los temas vacíos que no utilice; no se guardan.</div>
                    </div>
                </div>
            </div>
            <div class="card-footer d-flex gap-2">
                <button type="submit" class="btn btn-primary" data-testid="btn-save-course-edition">Crear edición</button>
                <a href="{{ route('course-talks.activities.show', $activity) }}" class="btn btn-outline-secondary">Cancelar</a>
            </div>
        </div>
    </form>
@endsection
