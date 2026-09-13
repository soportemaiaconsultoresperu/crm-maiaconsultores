@extends('layouts.app')

@section('title', 'Participantes del dictado')
@section('page-title', 'Participantes del dictado')

@section('content')
    @php
        // The enrollment-state and payment-status enums carry no display labels
        // (unlike edition state or modality), so the Spanish wording used by this
        // surface lives here instead of leaking into the controller.
        $stateLabels = [
            'enrolled' => ['Inscrito', 'text-bg-info'],
            'confirmed' => ['Confirmado', 'text-bg-primary'],
            'in_progress' => ['En curso', 'text-bg-info'],
            'completed' => ['Completado', 'text-bg-success'],
            'withdrawn' => ['Retirado', 'text-bg-secondary'],
            'no_show' => ['No asistió', 'text-bg-danger'],
        ];
        $paymentLabels = [
            'pending' => ['Pendiente', 'text-bg-warning'],
            'partial' => ['Parcial', 'text-bg-warning'],
            'paid' => ['Pagado', 'text-bg-success'],
            'waived' => ['Exonerado', 'text-bg-info'],
            'refunded' => ['Reembolsado', 'text-bg-secondary'],
        ];
        $fallback = static fn (string $value): array => [str_replace('_', ' ', $value), 'text-bg-secondary'];
    @endphp

    <a href="{{ route('course-talks.editions.show', $edition) }}" class="btn btn-outline-secondary mb-3">Volver al dictado</a>

    <div class="card mb-3" data-testid="course-talks-enrollment-edition">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3">Curso o charla</dt>
                <dd class="col-sm-9">{{ $edition->activity->name }}</dd>
                <dt class="col-sm-3">Dictado</dt>
                <dd class="col-sm-9"><code>{{ $edition->code ?: '—' }}</code> · {{ $edition->modality->label() }}</dd>
            </dl>
        </div>
    </div>

    {{-- A field-less InvalidCourseEditionData (duplicate participant, rejected
         payment transition) is mapped to the generic `enrollment` error key,
         which no form field renders. Without this block the failure would be
         invisible to the user. --}}
    @if ($errors->has('enrollment'))
        <x-alert type="error" data-testid="course-talks-enrollment-error">{{ $errors->first('enrollment') }}</x-alert>
    @endif

    <x-table title="Participantes inscritos" data-testid="course-talks-enrollments-table">
        @slot('filters')
            @can('create', App\Models\Courses\CourseEnrollment::class)
                <a class="btn btn-sm btn-primary" data-testid="course-talks-enrollment-create-link" href="{{ route('course-talks.enrollments.create', $edition) }}">Inscribir participante</a>
            @endcan
        @endslot
        @slot('headers')
            <tr>
                <th scope="col">Participante</th>
                <th scope="col">Estado</th>
                <th scope="col">Estado de pago</th>
                <th scope="col">Importe</th>
                <th scope="col">Grupo pagador</th>
                <th scope="col" class="text-end">Acciones</th>
            </tr>
        @endslot
        @slot('rows')
            @forelse ($enrollments as $enrollment)
                @php
                    $state = $stateLabels[$enrollment->state->value] ?? $fallback($enrollment->state->value);
                    $payment = $paymentLabels[$enrollment->payment_status->value] ?? $fallback($enrollment->payment_status->value);
                @endphp
                <tr data-testid="course-talks-enrollment-{{ $enrollment->id }}">
                    <td>
                        <strong>{{ $enrollment->participant->last_name }}, {{ $enrollment->participant->first_name }}</strong>
                        <div class="text-secondary small">{{ $enrollment->participant->document_type }} {{ $enrollment->participant->document_number }}</div>
                        <div class="text-secondary small">{{ $enrollment->participant->email }}</div>
                    </td>
                    <td>
                        <span class="badge {{ $state[1] }}" data-testid="course-talks-enrollment-state-{{ $enrollment->id }}">{{ $state[0] }}</span>
                    </td>
                    <td>
                        <span class="badge {{ $payment[1] }}" data-testid="course-talks-enrollment-payment-{{ $enrollment->id }}">{{ $payment[0] }}</span>
                    </td>
                    <td>{{ $enrollment->subtotal_amount }} {{ $enrollment->currency }}</td>
                    {{-- Blank for the participants of a group purchase only when the
                         enrollment really belongs to no payer group. --}}
                    <td data-testid="course-talks-enrollment-payer-{{ $enrollment->id }}">{{ $enrollment->group?->payer_name ?: '—' }}</td>
                    <td class="text-end">
                        @can('update', $enrollment)
                            <form method="POST" action="{{ route('course-talks.enrollments.payment-status.update', $enrollment) }}" class="d-flex gap-2 justify-content-end" data-testid="course-talks-enrollment-payment-form-{{ $enrollment->id }}">
                                @csrf
                                @method('PATCH')

                                <label class="visually-hidden" for="payment-status-{{ $enrollment->id }}">Nuevo estado de pago de {{ $enrollment->participant->last_name }}, {{ $enrollment->participant->first_name }}</label>
                                <select class="form-select form-select-sm w-auto" id="payment-status-{{ $enrollment->id }}" name="payment_status">
                                    @foreach ($paymentStatuses as $status)
                                        <option value="{{ $status->value }}" @selected($status === $enrollment->payment_status)>{{ $paymentLabels[$status->value][0] ?? $status->value }}</option>
                                    @endforeach
                                </select>

                                <button type="submit" class="btn btn-sm btn-outline-primary">Actualizar pago</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center text-secondary py-4" data-testid="course-talks-enrollment-empty">Todavía no hay participantes inscritos en este dictado.</td>
                </tr>
            @endforelse
        @endslot
    </x-table>
@endsection
