@extends('layouts.app')

@section('title', 'Notas de la edición')
@section('page-title', 'Notas de la edición')

@section('content')
    @php
        // Presentation-only maps. The grade value contract (0 a 20, hasta dos
        // decimales), the rounding and the final result are owned by
        // CourseGradeCalculator and CourseGradeService; this surface only
        // renders their values in Spanish and never decides which grades or
        // results are valid. FinalResult exposes no label(), so the wording
        // lives here next to the badge colors, exactly like the attendance
        // matrix does for its statuses.
        $resultMeta = [
            'approved' => ['Aprobado', 'text-bg-success'],
            'participation' => ['Participación', 'text-bg-info'],
            'pending' => ['Pendiente', 'text-bg-secondary'],
            'not_applicable' => ['No aplica', 'text-bg-light'],
        ];
        $isTalk = $edition->activity->type === App\Enums\Courses\CourseActivityType::Talk;
        // Defense in depth: the route already requires manageGrades, so this
        // only guards against a future change of that authorization. A talk
        // edition never offers grade inputs.
        $canEnterGrades = ! $isTalk && Gate::allows('manageGrades', $edition);
        $hasMatrix = $sessions->isNotEmpty() && $enrollments->isNotEmpty();
    @endphp

    <a href="{{ route('course-talks.editions.show', $edition) }}" class="btn btn-outline-secondary mb-3">Volver a la edición</a>

    <div class="card mb-3" data-testid="course-talks-grades-edition">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3">Actividad</dt>
                <dd class="col-sm-9">{{ $edition->activity->name }} · {{ $edition->activity->type->label() }}</dd>
                <dt class="col-sm-3">Edición</dt>
                <dd class="col-sm-9"><code>{{ $edition->code ?: '—' }}</code> · {{ $edition->modality->label() }}</dd>
                <dt class="col-sm-3">Sesiones</dt>
                <dd class="col-sm-9">{{ $sessions->count() }}</dd>
            </dl>
        </div>
    </div>

    {{-- A rejected submission is reported under the `grades` key (the service's
         own message) and the cell-shape errors are reported under `grades.*`
         keys that no form field renders. Listing every message keeps both
         visible. --}}
    @if ($errors->any())
        <x-alert type="error" data-testid="course-talks-grades-errors">
            <ul class="mb-0">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </x-alert>
    @endif

    @if ($isTalk)
        <x-alert type="info" data-testid="course-talks-grades-talk-note">
            Esta edición es una charla: las charlas no usan notas en esta versión. El resultado de cada participante se define por su participación confirmada, junto con el pago y las validaciones de la edición.
        </x-alert>

        <x-table title="Participación de la charla">
            @slot('headers')
                <tr>
                    <th scope="col">Participante</th>
                    <th scope="col">Participación</th>
                </tr>
            @endslot
            @slot('rows')
                @forelse ($enrollments as $enrollment)
                    <tr data-testid="course-talks-grades-participation-row-{{ $enrollment->id }}">
                        <td>
                            <strong>{{ $enrollment->participant->last_name }}, {{ $enrollment->participant->first_name }}</strong>
                            <div class="text-secondary small">{{ $enrollment->participant->document_type }} {{ $enrollment->participant->document_number }}</div>
                        </td>
                        <td data-testid="course-talks-grades-participation-{{ $enrollment->id }}">
                            @if ($enrollment->participation_confirmed_at !== null)
                                <span class="badge text-bg-success">Participación confirmada</span>
                            @else
                                <span class="badge text-bg-secondary">Participación pendiente</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="2" class="text-center text-secondary py-4" data-testid="course-talks-grades-empty">Todavía no hay participantes inscritos en esta edición.</td>
                    </tr>
                @endforelse
            @endslot
        </x-table>
    @else
        <x-alert type="info" data-testid="course-talks-grades-course-note">
            Esta edición es un curso: cada sesión acepta una nota de 0 a 20 con hasta dos decimales, todas con el mismo peso. El resultado final se recalcula al guardar y se muestra en cada fila.
        </x-alert>

        @if (! $hasMatrix)
            <x-alert type="info" data-testid="course-talks-grades-no-matrix">
                @if ($sessions->isEmpty())
                    Esta edición todavía no tiene sesiones registradas. Registre las sesiones de la edición para poder ingresar notas.
                @else
                    Todavía no hay participantes inscritos en esta edición.
                @endif
            </x-alert>
        @else
            @if ($canEnterGrades)
                {{-- Plain HTML inputs on purpose: interpolation inside a Blade
                     component's attributes is not evaluated, so a `name`
                     attribute built with {{ }} would silently render a cell the
                     validator never resolves. --}}
                <form method="POST" action="{{ route('course-talks.grades.store', $edition) }}" data-testid="course-talks-grades-form">
                    @csrf
            @endif

            <x-table title="Matriz de notas">
                @slot('headers')
                    <tr>
                        <th scope="col">Participante</th>
                        @foreach ($sessions as $session)
                            <th scope="col" data-testid="course-talks-grades-column-{{ $session->id }}">
                                <span class="badge text-bg-light">{{ $session->sort_order }}</span>
                                <span class="ms-1">{{ $session->session_date?->format('d/m/Y') }}</span>
                                <div class="fw-normal small text-secondary">{{ $session->topic }}</div>
                            </th>
                        @endforeach
                        <th scope="col">Resultado</th>
                    </tr>
                @endslot
                @slot('rows')
                    @php $cell = 0; @endphp
                    @forelse ($enrollments as $enrollment)
                        <tr data-testid="course-talks-grades-row-{{ $enrollment->id }}">
                            <td>
                                <strong>{{ $enrollment->participant->last_name }}, {{ $enrollment->participant->first_name }}</strong>
                                <div class="text-secondary small">{{ $enrollment->participant->document_type }} {{ $enrollment->participant->document_number }}</div>
                            </td>
                            @foreach ($sessions as $session)
                                @php $value = (string) ($grades[$enrollment->id][$session->id] ?? ''); @endphp
                                <td data-testid="course-talks-grades-cell-{{ $enrollment->id }}-{{ $session->id }}">
                                    @if ($canEnterGrades)
                                        <label class="visually-hidden" for="grade-cell-{{ $enrollment->id }}-{{ $session->id }}">Nota de {{ $enrollment->participant->last_name }}, {{ $enrollment->participant->first_name }} en la sesión {{ $session->sort_order }}</label>
                                        <input type="text" inputmode="decimal" autocomplete="off" maxlength="5" placeholder="—" class="form-control form-control-sm" id="grade-cell-{{ $enrollment->id }}-{{ $session->id }}" name="grades[{{ $cell }}][grade]" value="{{ old('grades.'.$cell.'.grade', $value) }}">
                                        <input type="hidden" name="grades[{{ $cell }}][enrollment_id]" value="{{ $enrollment->id }}">
                                        <input type="hidden" name="grades[{{ $cell }}][session_id]" value="{{ $session->id }}">
                                    @else
                                        <span class="badge text-bg-light">{{ $value !== '' ? $value : 'Sin nota' }}</span>
                                    @endif
                                </td>
                                @php $cell++; @endphp
                            @endforeach
                            @php $meta = $resultMeta[$enrollment->final_result?->value ?? 'pending'] ?? ['Pendiente', 'text-bg-secondary']; @endphp
                            <td data-testid="course-talks-grades-result-{{ $enrollment->id }}">
                                <span class="badge {{ $meta[1] }}">{{ $meta[0] }}</span>
                                <div class="small text-secondary">Promedio exacto: {{ $enrollment->exact_average ?? '—' }}</div>
                                <div class="small text-secondary">Promedio: {{ $enrollment->display_average ?? '—' }}</div>
                                <div class="small text-secondary">Redondeado: {{ $enrollment->rounded_result ?? '—' }}</div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $sessions->count() + 2 }}" class="text-center text-secondary py-4" data-testid="course-talks-grades-empty">Todavía no hay participantes inscritos en esta edición.</td>
                        </tr>
                    @endforelse
                @endslot
            </x-table>

            @if ($canEnterGrades)
                    <div class="mb-3">
                        <button type="submit" class="btn btn-primary" data-testid="btn-save-course-grades">Guardar notas</button>
                    </div>
                </form>
            @endif
        @endif
    @endif
@endsection
