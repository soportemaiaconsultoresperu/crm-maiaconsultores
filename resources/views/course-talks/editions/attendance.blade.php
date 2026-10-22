@extends('layouts.app')

@section('title', 'Asistencia del dictado')
@section('page-title', 'Asistencia del dictado')

@section('content')
    @php
        // The attendance statuses are owned by CourseAttendanceService, which
        // exposes no enum for them, so the Spanish wording and badge colors of
        // this surface live here. The controller and the request never decide
        // which statuses exist or which ones are valid.
        $statusMeta = [
            'present' => ['Presente', 'text-bg-success'],
            'absent' => ['Ausente', 'text-bg-danger'],
            'late' => ['Tardanza', 'text-bg-warning'],
            'excused' => ['Justificado', 'text-bg-info'],
            'unmarked' => ['Sin marcar', 'text-bg-secondary'],
        ];
        $statusFor = static fn (string $value): array => $statusMeta[$value] ?? [str_replace('_', ' ', $value), 'text-bg-secondary'];
        $isTalk = $edition->activity->type === App\Enums\Courses\CourseActivityType::Talk;
        // Nothing to mark when the edition has no sessions or no enrollments, so
        // the matrix degrades to its read-only empty state.
        $editable = Gate::allows('manageAttendance', $edition)
            && $sessions->isNotEmpty()
            && $enrollments->isNotEmpty();
    @endphp

    <a href="{{ route('course-talks.editions.show', $edition) }}" class="btn btn-outline-secondary mb-3">Volver al dictado</a>

    <div class="card mb-3" data-testid="course-talks-attendance-edition">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3">Curso o charla</dt>
                <dd class="col-sm-9">{{ $edition->activity->name }} · {{ $edition->activity->type->label() }}</dd>
                <dt class="col-sm-3">Dictado</dt>
                <dd class="col-sm-9"><code>{{ $edition->code ?: '—' }}</code> · {{ $edition->modality->label() }}</dd>
                <dt class="col-sm-3">Sesiones</dt>
                <dd class="col-sm-9">{{ $sessions->count() }}</dd>
            </dl>
        </div>
    </div>

    {{-- A rejected submission is reported under the `attendance` key (the
         service message for a mismatched cell, or the stale-row message), and
         the cell-shape errors are reported under `cells.*` keys that no form
         field renders. Listing every message keeps both visible. --}}
    @if ($errors->any())
        <x-alert type="error" data-testid="course-talks-attendance-errors">
            <ul class="mb-0">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </x-alert>
    @endif

    @if ($isTalk)
        <x-alert type="info" data-testid="course-talks-attendance-talk-note">
            Este dictado es una charla: la asistencia de cada participante determina su participación. Los estados Presente, Tardanza y Justificado confirman la participación, que junto con el pago y las validaciones habilita el certificado de la charla.
        </x-alert>
    @else
        <x-alert type="info" data-testid="course-talks-attendance-course-note">
            Este dictado es un curso: la asistencia es informativa y no modifica la nota ni el resultado final del participante.
        </x-alert>
    @endif

    @if ($sessions->isEmpty())
        <x-alert type="info" data-testid="course-talks-attendance-no-sessions">
            Este dictado todavía no tiene sesiones registradas. Registre las sesiones del dictado para poder marcar la asistencia.
        </x-alert>
    @else
        @if ($editable)
            <form method="POST" action="{{ route('course-talks.attendance.store', $edition) }}" data-testid="course-talks-attendance-form">
                @csrf
        @endif

        <x-table title="Matriz de asistencia">
            @slot('headers')
                <tr>
                    <th scope="col">Participante</th>
                    @if ($isTalk)
                        <th scope="col">Participación</th>
                    @endif
                    @foreach ($sessions as $session)
                        <th scope="col" data-testid="course-talks-attendance-column-{{ $session->id }}">
                            <span class="badge text-bg-light">{{ $session->sort_order }}</span>
                            <span class="ms-1">{{ $session->session_date?->format('d/m/Y') }}</span>
                            <div class="fw-normal small text-secondary">{{ $session->topic }}</div>
                        </th>
                    @endforeach
                </tr>
            @endslot
            @slot('rows')
                @php $cell = 0; @endphp
                @forelse ($enrollments as $enrollment)
                    <tr data-testid="course-talks-attendance-row-{{ $enrollment->id }}">
                        <td>
                            <strong>{{ $enrollment->participant->last_name }}, {{ $enrollment->participant->first_name }}</strong>
                            @if ($document = $enrollment->participant->displayDocument())
                                <div class="text-secondary small">{{ $document }}</div>
                            @endif
                        </td>
                        @if ($isTalk)
                            @php $confirmed = $enrollment->participation_confirmed_at !== null; @endphp
                            <td data-testid="course-talks-attendance-participation-{{ $enrollment->id }}">
                                @if ($confirmed)
                                    <span class="badge text-bg-success">Participación confirmada</span>
                                @else
                                    <span class="badge text-bg-secondary">Participación pendiente</span>
                                @endif
                            </td>
                        @endif
                        @foreach ($sessions as $session)
                            @php $value = (string) ($attendance[$enrollment->id][$session->id] ?? 'unmarked'); @endphp
                            <td data-testid="course-talks-attendance-cell-{{ $enrollment->id }}-{{ $session->id }}">
                                @if ($editable)
                                    <label class="visually-hidden" for="attendance-cell-{{ $enrollment->id }}-{{ $session->id }}">Asistencia de {{ $enrollment->participant->last_name }}, {{ $enrollment->participant->first_name }} en la sesión {{ $session->sort_order }}</label>
                                    <select class="form-select form-select-sm" id="attendance-cell-{{ $enrollment->id }}-{{ $session->id }}" name="cells[{{ $cell }}][status]">
                                        @foreach ($statusMeta as $status => $meta)
                                            <option value="{{ $status }}" @selected($value === $status)>{{ $meta[0] }}</option>
                                        @endforeach
                                    </select>
                                    <input type="hidden" name="cells[{{ $cell }}][enrollment_id]" value="{{ $enrollment->id }}">
                                    <input type="hidden" name="cells[{{ $cell }}][session_id]" value="{{ $session->id }}">
                                @else
                                    @php $meta = $statusFor($value); @endphp
                                    <span class="badge {{ $meta[1] }}">{{ $meta[0] }}</span>
                                @endif
                            </td>
                            @php $cell++; @endphp
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $sessions->count() + ($isTalk ? 2 : 1) }}" class="text-center text-secondary py-4" data-testid="course-talks-attendance-empty">Todavía no hay participantes inscritos en este dictado.</td>
                    </tr>
                @endforelse
            @endslot
        </x-table>

        @if ($editable)
                <div class="mb-3">
                    <button type="submit" class="btn btn-primary" data-testid="btn-save-course-attendance">Guardar asistencia</button>
                </div>
            </form>
        @endif
    @endif
@endsection
