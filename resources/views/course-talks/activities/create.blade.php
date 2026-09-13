@extends('layouts.app')

@section('title', 'Nuevo curso o charla')
@section('page-title', 'Nuevo curso o charla')

@section('content')
    @php
        $typeOptions = collect($types)->mapWithKeys(fn ($type) => [$type->value => $type->label()])->all();
        $topics = old('base_syllabus_json', []);
        $topics = is_array($topics) && $topics !== [] ? array_values($topics) : [''];
        // A nested-array entry survives StoreCourseActivityRequest::prepareForValidation()
        // so the `string` rule can reject it; on the re-rendered form it must still
        // print as text instead of reaching htmlspecialchars() as an array (which
        // would return a 500 instead of the intended validation error).
        $topics = array_map(
            static fn ($topic): string => match (true) {
                $topic === null => '',
                is_scalar($topic) => (string) $topic,
                default => (json_encode($topic, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
            },
            $topics,
        );
    @endphp

    <a href="{{ route('course-talks.activities.index') }}" class="btn btn-outline-secondary mb-3">Volver al catálogo</a>

    <form method="POST" action="{{ route('course-talks.activities.store') }}" data-testid="course-talks-activity-create-form">
        @csrf

        <div class="card">
            <div class="card-header">
                <h3 class="card-title mb-0">Nuevo curso o charla</h3>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <x-select name="type" label="Tipo" :options="$typeOptions" :value="old('type')" placeholder="Seleccione" :required="true"/>
                    </div>
                    <div class="col-md-4">
                        <x-text-input name="code" label="Código" :required="true" maxlength="60" help="El código es único entre todos los cursos y charlas."/>
                    </div>
                    <div class="col-md-4">
                        <x-text-input name="name" label="Nombre" :required="true" maxlength="255"/>
                    </div>
                    <div class="col-md-4">
                        <x-text-input name="official_academic_hours" type="number" label="Horas académicas"
                                      :value="old('official_academic_hours')" step="0.01" min="0" :required="true"/>
                    </div>
                    <div class="col-md-4">
                        <x-text-input name="talk_certificate_price" type="number" label="Precio del certificado de charla"
                                      :value="old('talk_certificate_price')" step="0.01" min="0"/>
                    </div>
                    <div class="col-md-4 d-flex flex-column justify-content-end">
                        <div class="form-check mb-2">
                            <input type="hidden" name="talk_includes_certificate" value="0">
                            <input type="checkbox" name="talk_includes_certificate" id="talk_includes_certificate" value="1"
                                   class="form-check-input @error('talk_includes_certificate') is-invalid @enderror"
                                   @checked((string) old('talk_includes_certificate', '0') === '1')>
                            <label class="form-check-label" for="talk_includes_certificate">Charla con certificado</label>
                            <x-validation-error name="talk_includes_certificate"/>
                        </div>
                        <div class="form-check">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" id="is_active" value="1"
                                   class="form-check-input @error('is_active') is-invalid @enderror"
                                   @checked((string) old('is_active', '1') === '1')>
                            <label class="form-check-label" for="is_active">Activa</label>
                            <x-validation-error name="is_active"/>
                        </div>
                    </div>
                    <div class="col-12">
                        <x-label for="base_syllabus_json" label="Temario base"/>
                        @foreach ($topics as $topic)
                            <input type="text" name="base_syllabus_json[]" value="{{ $topic }}"
                                   class="form-control mb-2 @error('base_syllabus_json.*') is-invalid @enderror"
                                   maxlength="255" placeholder="Tema {{ $loop->iteration }}">
                        @endforeach
                        <x-validation-error name="base_syllabus_json.*"/>
                        <div class="form-text">Deje los temas vacíos que no utilice; no se guardan.</div>
                    </div>
                </div>
            </div>
            <div class="card-footer d-flex gap-2">
                <button type="submit" class="btn btn-primary" data-testid="btn-save-course-activity">Crear curso o charla</button>
                <a href="{{ route('course-talks.activities.index') }}" class="btn btn-outline-secondary">Cancelar</a>
            </div>
        </div>
    </form>
@endsection
