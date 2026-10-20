{{--
    Individual enrollment form, included by create.blade.php with $edition,
    $contactOptions and $participantOptions.

    The `participant_source` radio group is a UI-only selector: the matching
    StoreCourseEnrollmentRequest decides which fields the service receives, so
    exactly one of the three sources reaches CourseEnrollmentService::enroll().
--}}
<form method="POST" action="{{ route('course-talks.enrollments.store', $edition) }}" data-testid="course-talks-enrollment-create-form" data-course-enrollment-form>
    @csrf

    <div class="card">
        <div class="card-header">
            <h3 class="card-title mb-0">Inscribir un participante</h3>
        </div>
        <div class="card-body">
            @php
                $source = old('participant_source', 'contact');
                $sourceDescriptions = [
                    'contact' => 'Elegí esta opción si la persona ya existe como contacto del CRM. Solo tenés que seleccionar el contacto.',
                    'participant' => 'Elegí esta opción si la persona ya fue participante de otro curso. Solo tenés que seleccionar su ficha existente.',
                    'new' => 'Elegí esta opción si todavía no existe en el CRM ni en otros cursos. Cargá sus datos mínimos para crear la ficha.',
                ];
            @endphp

            <fieldset class="mb-3" aria-describedby="participant-source-help participant-source-selected">
                <legend class="form-label">Origen del participante</legend>
                <p class="form-text" id="participant-source-help">
                    Seleccioná de dónde sale la persona. Se mostrarán solo los campos que aplican a esa opción.
                </p>

                <div class="row g-2" role="radiogroup" aria-label="Origen del participante">
                    @foreach ([
                        'contact' => ['title' => 'Contacto del CRM', 'description' => 'La persona ya existe como contacto.'],
                        'participant' => ['title' => 'Participante existente', 'description' => 'La persona ya participó en otro curso.'],
                        'new' => ['title' => 'Nuevo participante', 'description' => 'La persona todavía no está registrada.'],
                    ] as $value => $option)
                        <div class="col-md-4">
                            <div class="form-check border rounded p-3 h-100">
                                <input class="form-check-input" type="radio" name="participant_source"
                                       id="participant-source-{{ $value }}" value="{{ $value }}"
                                       data-course-enrollment-source
                                       data-source-description="{{ $sourceDescriptions[$value] }}"
                                       @checked($source === $value)>
                                <label class="form-check-label fw-semibold" for="participant-source-{{ $value }}">{{ $option['title'] }}</label>
                                <div class="form-text">{{ $option['description'] }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <p class="alert alert-info mt-3 mb-0" id="participant-source-selected" role="status" aria-live="polite" data-course-enrollment-source-summary>
                    {{ $sourceDescriptions[$source] ?? $sourceDescriptions['contact'] }}
                </p>

                <x-validation-error name="participant_source"/>
            </fieldset>

            <div class="row g-3" data-course-enrollment-source-panel="contact">
                <div class="col-md-6">
                    <x-select name="contact_id" label="Contacto del CRM" :options="$contactOptions" placeholder="Seleccione un contacto"/>
                </div>
            </div>

            <div class="row g-3" data-course-enrollment-source-panel="participant">
                <div class="col-md-6">
                    <x-select name="course_participant_id" label="Participante ya registrado" :options="$participantOptions" placeholder="Seleccione un participante"/>
                </div>
            </div>

            <div data-course-enrollment-source-panel="new">
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
        </div>
        <div class="card-footer d-flex flex-wrap gap-2">
            <button type="submit" name="enrollment_action" value="enroll" class="btn btn-primary" data-testid="btn-save-course-enrollment">Inscribir participante</button>
            <button type="submit" name="enrollment_action" value="enroll_and_add_another" class="btn btn-outline-primary" data-testid="btn-save-and-add-course-enrollment">Inscribir y agregar otro</button>
            <a href="{{ route('course-talks.enrollments.index', $edition) }}" class="btn btn-outline-secondary">Cancelar</a>
        </div>
    </div>
</form>
