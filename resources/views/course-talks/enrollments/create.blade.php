@extends('layouts.app')

@section('title', 'Nueva inscripción')
@section('page-title', 'Nueva inscripción')

@section('content')
    @php
        $contactOptions = $contacts->mapWithKeys(fn ($contact): array => [
            $contact->id => trim($contact->last_name.' '.$contact->first_name).($contact->email ? ' — '.$contact->email : ''),
        ])->all();

        // The label shows the person, and their document only when it is a real one:
        // a participant built from a CRM contact carries a fabricated document that
        // would read as "contact contact-12" to the operator.
        $participantOptions = $participants->mapWithKeys(function ($participant): array {
            $name = trim($participant->last_name.' '.$participant->first_name);
            $document = $participant->displayDocument();

            return [$participant->id => $document === null ? $name : $name.' — '.$document];
        })->all();

        $customerOptions = $customers->mapWithKeys(fn ($customer): array => [
            $customer->id => trim(($customer->code ? $customer->code.' · ' : '').($customer->legal_name ?: $customer->trade_name)),
        ])->all();

        // Fixed block of participant rows: blank rows are dropped by
        // StoreCourseEnrollmentGroupRequest, so a partially filled block is a
        // valid submission. A rejected submission that carried more rows keeps
        // them so no typed participant is lost.
        $previousParticipants = old('participants', []);
        $previousParticipants = is_array($previousParticipants)
            ? array_values(array_filter($previousParticipants, 'is_array'))
            : [];
        $participantRows = max(3, count($previousParticipants));
        $rowValue = static fn (array $row, string $key): string => is_scalar($row[$key] ?? null)
            ? (string) $row[$key]
            : '';
    @endphp

    <a href="{{ route('course-talks.enrollments.index', $edition) }}" class="btn btn-outline-secondary mb-3">Volver a la lista de participantes</a>

    {{-- A field-less InvalidCourseEditionData (duplicate enrollment, group
         rollback) is mapped to the generic `enrollment` error key, which no form
         field renders. Without this block the failure would be invisible. --}}
    @if ($errors->has('enrollment'))
        <x-alert type="error" data-testid="course-talks-enrollment-error">{{ $errors->first('enrollment') }}</x-alert>
    @endif

    @include('course-talks.enrollments._form')

    <form method="POST" action="{{ route('course-talks.enrollments.groups.store', $edition) }}" data-testid="course-talks-enrollment-group-form">
        @csrf

        <div class="card mt-3">
            <div class="card-header">
                <h3 class="card-title mb-0">Inscripción de grupo con pagador</h3>
            </div>
            <div class="card-body">
                <x-alert type="info">Cada participante conserva su propia ficha académica. El pago se registra una sola vez con los datos del pagador del grupo.</x-alert>

                <div class="row g-3">
                    <div class="col-md-4">
                        <x-select name="payer_customer_id" label="Cliente pagador" :options="$customerOptions" placeholder="Sin cliente registrado"/>
                    </div>
                    <div class="col-md-4">
                        <x-text-input name="payer_name" label="Nombre o razón social del pagador" maxlength="255" :required="true"/>
                    </div>
                    <div class="col-md-2">
                        <x-text-input name="payer_document_type" label="Tipo de documento" maxlength="50"/>
                    </div>
                    <div class="col-md-2">
                        <x-text-input name="payer_document_number" label="Número de documento" maxlength="50"/>
                    </div>
                    <div class="col-12">
                        <x-text-input name="notes" label="Notas" maxlength="1000" help="Opcional."/>
                    </div>
                </div>

                <hr>

                <h4 class="h6">Participantes del grupo</h4>
                <p class="form-text">
                    Una fila por participante. Las filas vacías se ignoran. Cada participante necesita nombres, apellidos,
                    tipo y número de documento, correo y celular con código de país.
                </p>

                <x-validation-error name="participants"/>

                @for ($index = 0; $index < $participantRows; $index++)
                    @php($row = $previousParticipants[$index] ?? [])
                    <div class="row g-2 align-items-end mb-2" data-testid="course-talks-group-participant-row">
                        <div class="col-md-1 form-text">{{ $index + 1 }}.</div>
                        <div class="col-md-2">
                            <label class="form-label mb-1" for="participant-{{ $index }}-first_name">Nombres</label>
                            <input type="text" class="form-control" id="participant-{{ $index }}-first_name"
                                   name="participants[{{ $index }}][first_name]" value="{{ $rowValue($row, 'first_name') }}" maxlength="255">
                            {{-- Component attributes need the expression form: an interpolated
                                 literal would be passed verbatim and never match the error bag. --}}
                            <x-validation-error :name="'participants.'.$index.'.first_name'"/>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label mb-1" for="participant-{{ $index }}-last_name">Apellidos</label>
                            <input type="text" class="form-control" id="participant-{{ $index }}-last_name"
                                   name="participants[{{ $index }}][last_name]" value="{{ $rowValue($row, 'last_name') }}" maxlength="255">
                            <x-validation-error :name="'participants.'.$index.'.last_name'"/>
                        </div>
                        <div class="col-md-1">
                            <label class="form-label mb-1" for="participant-{{ $index }}-document_type">Tipo doc.</label>
                            <input type="text" class="form-control" id="participant-{{ $index }}-document_type"
                                   name="participants[{{ $index }}][document_type]" value="{{ $rowValue($row, 'document_type') }}" maxlength="50">
                            <x-validation-error :name="'participants.'.$index.'.document_type'"/>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label mb-1" for="participant-{{ $index }}-document_number">Número doc.</label>
                            <input type="text" class="form-control" id="participant-{{ $index }}-document_number"
                                   name="participants[{{ $index }}][document_number]" value="{{ $rowValue($row, 'document_number') }}" maxlength="50">
                            <x-validation-error :name="'participants.'.$index.'.document_number'"/>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label mb-1" for="participant-{{ $index }}-email">Correo</label>
                            <input type="email" class="form-control" id="participant-{{ $index }}-email"
                                   name="participants[{{ $index }}][email]" value="{{ $rowValue($row, 'email') }}" maxlength="255">
                            <x-validation-error :name="'participants.'.$index.'.email'"/>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label mb-1" for="participant-{{ $index }}-mobile">Celular</label>
                            <input type="text" class="form-control" id="participant-{{ $index }}-mobile"
                                   name="participants[{{ $index }}][mobile]" value="{{ $rowValue($row, 'mobile') }}" maxlength="30">
                            <x-validation-error :name="'participants.'.$index.'.mobile'"/>
                        </div>
                    </div>
                @endfor
            </div>
            <div class="card-footer d-flex gap-2">
                <button type="submit" class="btn btn-primary" data-testid="btn-save-course-enrollment-group">Inscribir grupo</button>
                <a href="{{ route('course-talks.enrollments.index', $edition) }}" class="btn btn-outline-secondary">Cancelar</a>
            </div>
        </div>
    </form>
@endsection
