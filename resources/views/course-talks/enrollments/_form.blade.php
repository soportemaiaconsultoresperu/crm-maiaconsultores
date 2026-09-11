{{--
    Individual enrollment form, included by create.blade.php with $edition,
    $contactOptions and $participantOptions.

    The `participant_source` radio group is a UI-only selector: the matching
    StoreCourseEnrollmentRequest decides which fields the service receives, so
    exactly one of the three sources reaches CourseEnrollmentService::enroll().
--}}
<form method="POST" action="{{ route('course-talks.enrollments.store', $edition) }}" data-testid="course-talks-enrollment-create-form">
    @csrf

    <div class="card">
        <div class="card-header">
            <h3 class="card-title mb-0">Inscribir un participante</h3>
        </div>
        <div class="card-body">
            <fieldset class="mb-3">
                <legend class="form-label">Origen del participante</legend>

                @php($source = old('participant_source', 'contact'))

                @foreach ([
                    'contact' => 'Contacto existente del CRM',
                    'participant' => 'Participante ya registrado en un curso',
                    'new' => 'Datos nuevos del participante',
                ] as $value => $label)
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="participant_source"
                               id="participant-source-{{ $value }}" value="{{ $value }}"
                               @checked($source === $value)>
                        <label class="form-check-label" for="participant-source-{{ $value }}">{{ $label }}</label>
                    </div>
                @endforeach

                <x-validation-error name="participant_source"/>
            </fieldset>

            <div class="row g-3">
                <div class="col-md-6">
                    <x-select name="contact_id" label="Contacto del CRM" :options="$contactOptions" placeholder="Seleccione un contacto"/>
                </div>
                <div class="col-md-6">
                    <x-select name="course_participant_id" label="Participante ya registrado" :options="$participantOptions" placeholder="Seleccione un participante"/>
                </div>
            </div>

            <hr>

            <p class="text-secondary mb-2">Complete estos datos solo cuando el participante todavía no exista en el CRM ni en otro curso.</p>

            <div class="row g-3">
                <div class="col-md-3">
                    <x-text-input name="first_name" label="Nombres" maxlength="255"/>
                </div>
                <div class="col-md-3">
                    <x-text-input name="last_name" label="Apellidos" maxlength="255"/>
                </div>
                <div class="col-md-3">
                    <x-text-input name="document_type" label="Tipo de documento" maxlength="50" help="Por ejemplo DNI, CE o Pasaporte."/>
                </div>
                <div class="col-md-3">
                    <x-text-input name="document_number" label="Número de documento" maxlength="50"/>
                </div>
                <div class="col-md-6">
                    <x-text-input name="email" type="email" label="Correo electrónico" maxlength="255"/>
                </div>
                <div class="col-md-6">
                    <x-text-input name="mobile" label="Celular" maxlength="30" help="Incluya el código de país, por ejemplo +51 999 111 222."/>
                </div>
            </div>
        </div>
        <div class="card-footer d-flex gap-2">
            <button type="submit" class="btn btn-primary" data-testid="btn-save-course-enrollment">Inscribir participante</button>
            <a href="{{ route('course-talks.enrollments.index', $edition) }}" class="btn btn-outline-secondary">Cancelar</a>
        </div>
    </div>
</form>
