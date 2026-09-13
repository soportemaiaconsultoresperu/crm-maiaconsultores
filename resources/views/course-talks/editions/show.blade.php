@extends('layouts.app')

@section('title', 'Dictado '.$edition->code)
@section('page-title', 'Dictado '.$edition->code)

@section('content')
    <a href="{{ route('course-talks.activities.show', $edition->activity) }}" class="btn btn-outline-secondary mb-3">Volver a {{ $edition->activity->name }}</a>

    {{-- A field-less InvalidCourseEditionData is mapped to the generic `edition`
         error key. No form field carries that name, so without this block the
         failure would never be rendered. --}}
    @if ($errors->has('edition'))
        <x-alert type="error" data-testid="course-talks-edition-error">{{ $errors->first('edition') }}</x-alert>
    @endif

    {{-- Slice 6.g — contextual navigation: every screen this edition owns is one
         click away. Management surfaces are advertised only to holders of the
         ability their own route already requires, so no link can 403. --}}
    <nav class="d-flex flex-wrap gap-2 mb-3" aria-label="Secciones del dictado" data-testid="course-talks-edition-navigation">
        @can('update', \App\Models\Courses\CourseEdition::class)
            <a href="{{ route('course-talks.editions.teachers', $edition) }}" class="btn btn-outline-primary">Docentes</a>
            <a href="{{ route('course-talks.editions.sessions', $edition) }}" class="btn btn-outline-primary">Sesiones</a>
        @endcan
        <a href="{{ route('course-talks.enrollments.index', $edition) }}" class="btn btn-outline-primary">Participantes</a>
        @can('create', \App\Models\Courses\CourseEnrollment::class)
            <a href="{{ route('course-talks.enrollments.create', $edition) }}" class="btn btn-outline-primary">Inscribir participante</a>
        @endcan
        <a href="{{ route('course-talks.attendance.index', $edition) }}" class="btn btn-outline-primary">Asistencia</a>
        {{-- The grade matrix requires the same ability the route itself
             requires, so a rendered link can never answer 403. --}}
        @can('manageGrades', $edition)
            <a href="{{ route('course-talks.grades.index', $edition) }}" class="btn btn-outline-primary">Notas</a>
        @endcan
        @can('view', $edition)
            <a href="{{ route('course-talks.documents.index', $edition) }}" class="btn btn-outline-primary">Documentos</a>
            {{-- The commercial documents list requires the same ability its own
                 route requires, so the link can never answer 403. --}}
            <a href="{{ route('course-talks.commercial-documents.index', $edition) }}" class="btn btn-outline-primary">Comprobantes</a>
        @endcan
    </nav>

    <div class="card" data-testid="course-talks-edition-detail">
        <div class="card-header"><h3 class="card-title mb-0">{{ $edition->activity->name }}</h3></div>
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3">Código</dt><dd class="col-sm-9"><code>{{ $edition->code ?: '—' }}</code></dd>
                <dt class="col-sm-3">Estado</dt><dd class="col-sm-9"><span class="badge text-bg-secondary">{{ $edition->state->label() }}</span></dd>
                <dt class="col-sm-3">Modalidad</dt><dd class="col-sm-9">{{ $edition->modality->label() }}</dd>
                <dt class="col-sm-3">Fechas</dt><dd class="col-sm-9">{{ $edition->starts_on?->format('d/m/Y') }} — {{ $edition->ends_on?->format('d/m/Y') }}</dd>
                @if ($edition->address)<dt class="col-sm-3">Dirección</dt><dd class="col-sm-9">{{ $edition->address }}</dd>@endif
                @if ($edition->access_url)
                    <dt class="col-sm-3">Acceso virtual</dt>
                    <dd class="col-sm-9">
                        @if (preg_match('#^https?://#i', $edition->access_url))
                            <a href="{{ $edition->access_url }}" rel="noopener noreferrer" target="_blank">Abrir enlace</a>
                        @else
                            {{ $edition->access_url }}
                        @endif
                    </dd>
                @endif
            </dl>
        </div>
    </div>

    {{-- Rendered only by CourseEditionController::teachers(). The read-only
         detail route renders this same view without passing `$teachers`, so it
         keeps working (and querying nothing extra) as before. --}}
    @isset($teachers)
        <div class="card mt-3" data-testid="course-talks-edition-teachers">
            <div class="card-header"><h3 class="card-title mb-0">Docentes del dictado</h3></div>
            <div class="card-body">
                @forelse ($teachers as $teacher)
                    <div data-testid="course-talks-edition-teacher">
                        {{ $teacher->display_name }}
                        @if ($teacher->email)<span class="text-secondary ms-2">{{ $teacher->email }}</span>@endif
                        @if ($teacher->user_id)<span class="badge text-bg-light ms-2">Usuario interno</span>@endif
                    </div>
                @empty
                    <p class="text-secondary mb-0" data-testid="course-talks-edition-teachers-empty">Este dictado todavía no tiene docentes registrados.</p>
                @endforelse
            </div>
        </div>

        @php
            $rows = old('teachers', $teachers->map(fn ($teacher) => [
                'display_name' => $teacher->display_name,
                'email' => $teacher->email,
                'user_id' => $teacher->user_id,
            ])->all());
            $rows = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
            $rowValue = fn ($row, string $key): string => is_scalar($row[$key] ?? null) ? (string) $row[$key] : '';
        @endphp

        <form method="POST" action="{{ route('course-talks.editions.teachers.sync', $edition) }}" data-testid="course-talks-edition-teachers-form">
            @csrf

            <div class="card mt-3">
                <div class="card-header"><h3 class="card-title mb-0">Actualizar docentes</h3></div>
                <div class="card-body">
                    <x-alert type="warning">Guardar reemplaza toda la lista de docentes de este dictado: los docentes que no aparezcan en el formulario quedarán sin asignar.</x-alert>

                    @if ($errors->any())
                        <x-alert type="error" data-testid="course-talks-teachers-errors">
                            <ul class="mb-0">
                                @foreach ($errors->all() as $message)
                                    <li>{{ $message }}</li>
                                @endforeach
                            </ul>
                        </x-alert>
                    @endif

                    @forelse ($rows as $index => $row)
                        <div class="row g-2 align-items-center mb-2" data-testid="course-talks-edition-teacher-row">
                            <div class="col-md-1 form-check ms-3">
                                <input type="checkbox" class="form-check-input" id="teacher-remove-{{ $index }}" name="teachers[{{ $index }}][remove]" value="1">
                                <label class="form-check-label" for="teacher-remove-{{ $index }}">Quitar</label>
                            </div>
                            <div class="col-md-5"><input type="text" class="form-control" name="teachers[{{ $index }}][display_name]" value="{{ $rowValue($row, 'display_name') }}" maxlength="255" placeholder="Nombre del docente"></div>
                            <div class="col-md-3"><input type="text" class="form-control" name="teachers[{{ $index }}][email]" value="{{ $rowValue($row, 'email') }}" maxlength="255" placeholder="Correo (opcional)"></div>
                            <div class="col-md-3"><input type="number" class="form-control" name="teachers[{{ $index }}][user_id]" value="{{ $rowValue($row, 'user_id') }}" placeholder="Usuario interno (opcional)"></div>
                        </div>
                    @empty
                        <p class="text-secondary" data-testid="course-talks-edition-teachers-none">La lista está vacía. Agregue el primer docente abajo.</p>
                    @endforelse

                    <hr>

                    <div class="row g-2 align-items-center">
                        <div class="col-md-1 form-text ms-3">Nuevo</div>
                        <div class="col-md-5"><input type="text" class="form-control" name="new_teacher[display_name]" value="{{ old('new_teacher.display_name') }}" maxlength="255" placeholder="Nombre del docente"></div>
                        <div class="col-md-3"><input type="text" class="form-control" name="new_teacher[email]" value="{{ old('new_teacher.email') }}" maxlength="255" placeholder="Correo (opcional)"></div>
                        <div class="col-md-3"><input type="number" class="form-control" name="new_teacher[user_id]" value="{{ old('new_teacher.user_id') }}" placeholder="Usuario interno (opcional)"></div>
                    </div>
                    <div class="form-text">Deje la fila «Nuevo» vacía para no agregar ningún docente.</div>
                </div>
                <div class="card-footer d-flex gap-2">
                    <button type="submit" class="btn btn-primary" data-testid="btn-sync-course-edition-teachers">Guardar docentes</button>
                    <a href="{{ route('course-talks.editions.show', $edition) }}" class="btn btn-outline-secondary">Cancelar</a>
                </div>
            </div>
        </form>
    @endisset

    {{-- Rendered only by CourseEditionController::sessions(). The read-only
         detail route and the teachers route render this view without passing
         `$sessions`, so they keep working (and query nothing extra). --}}
    @isset($sessions)
        <div class="card mt-3" data-testid="course-talks-edition-sessions">
            <div class="card-header"><h3 class="card-title mb-0">Sesiones del dictado</h3></div>
            <div class="card-body">
                @forelse ($sessions as $session)
                    <div class="mb-1" data-testid="course-talks-edition-session">
                        <span class="badge text-bg-light me-1">{{ $session->sort_order }}</span>
                        <strong>{{ $session->topic }}</strong>
                        @if ($session->session_date)<span class="text-secondary ms-2">{{ $session->session_date->format('d/m/Y') }}</span>@endif
                        @if ($session->starts_at || $session->ends_at)
                            <span class="text-secondary ms-2">{{ $session->starts_at ? substr($session->starts_at, 0, 5) : '—' }} — {{ $session->ends_at ? substr($session->ends_at, 0, 5) : '—' }}</span>
                        @endif
                        @if ($session->teacher_name)<span class="ms-2">{{ $session->teacher_name }}</span>@endif
                    </div>
                @empty
                    <p class="text-secondary mb-0" data-testid="course-talks-edition-sessions-empty">Este dictado todavía no tiene sesiones registradas.</p>
                @endforelse
            </div>
        </div>

        @php
            $sessionRows = old('sessions', $sessions->map(fn ($session) => [
                'topic' => $session->topic,
                'session_date' => $session->session_date?->format('Y-m-d'),
                'starts_at' => $session->starts_at ? substr($session->starts_at, 0, 5) : null,
                'ends_at' => $session->ends_at ? substr($session->ends_at, 0, 5) : null,
                'teacher_name' => $session->teacher_name,
            ])->all());
            $sessionRows = is_array($sessionRows) ? array_values(array_filter($sessionRows, 'is_array')) : [];
            $sessionValue = fn ($row, string $key): string => is_scalar($row[$key] ?? null) ? (string) $row[$key] : '';
        @endphp

        <form method="POST" action="{{ route('course-talks.editions.sessions.sync', $edition) }}" data-testid="course-talks-edition-sessions-form">
            @csrf

            <div class="card mt-3">
                <div class="card-header"><h3 class="card-title mb-0">Actualizar sesiones</h3></div>
                <div class="card-body">
                    {{-- syncSessions() upserts by array position instead of wiping the
                         list, so the copy must not claim the teachers surface's full
                         replacement. --}}
                    <x-alert type="info">Guardar actualiza las sesiones por posición: la fila 1 actualiza la sesión 1, la fila 2 la sesión 2, y así sucesivamente. Use la fila «Nueva» para agregar una sesión. Este formulario no elimina sesiones existentes.</x-alert>

                    @if ($errors->any())
                        <x-alert type="error" data-testid="course-talks-sessions-errors">
                            <ul class="mb-0">
                                @foreach ($errors->all() as $message)
                                    <li>{{ $message }}</li>
                                @endforeach
                            </ul>
                        </x-alert>
                    @endif

                    @forelse ($sessionRows as $index => $row)
                        <div class="row g-2 align-items-end mb-2" data-testid="course-talks-edition-session-row">
                            <div class="col-md-1 form-text">{{ $index + 1 }}.</div>
                            <div class="col-md-2">
                                <label class="form-label mb-1" for="session-date-{{ $index }}">Fecha</label>
                                <input type="date" class="form-control" id="session-date-{{ $index }}" name="sessions[{{ $index }}][session_date]" value="{{ $sessionValue($row, 'session_date') }}">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label mb-1" for="session-start-{{ $index }}">Inicio</label>
                                <input type="time" class="form-control" id="session-start-{{ $index }}" name="sessions[{{ $index }}][starts_at]" value="{{ $sessionValue($row, 'starts_at') }}">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label mb-1" for="session-end-{{ $index }}">Fin</label>
                                <input type="time" class="form-control" id="session-end-{{ $index }}" name="sessions[{{ $index }}][ends_at]" value="{{ $sessionValue($row, 'ends_at') }}">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label mb-1" for="session-teacher-{{ $index }}">Docente</label>
                                <input type="text" class="form-control" id="session-teacher-{{ $index }}" name="sessions[{{ $index }}][teacher_name]" value="{{ $sessionValue($row, 'teacher_name') }}" maxlength="255">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label mb-1" for="session-topic-{{ $index }}">Tema</label>
                                <input type="text" class="form-control" id="session-topic-{{ $index }}" name="sessions[{{ $index }}][topic]" value="{{ $sessionValue($row, 'topic') }}" maxlength="255">
                            </div>
                        </div>
                    @empty
                        <p class="text-secondary" data-testid="course-talks-edition-sessions-none">La lista está vacía. Agregue la primera sesión abajo.</p>
                    @endforelse

                    <hr>

                    <div class="row g-2 align-items-end">
                        <div class="col-md-1 form-text">Nueva</div>
                        <div class="col-md-2">
                            <label class="form-label mb-1" for="new-session-date">Fecha</label>
                            <input type="date" class="form-control" id="new-session-date" name="new_session[session_date]" value="{{ old('new_session.session_date') }}">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label mb-1" for="new-session-start">Inicio</label>
                            <input type="time" class="form-control" id="new-session-start" name="new_session[starts_at]" value="{{ old('new_session.starts_at') }}">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label mb-1" for="new-session-end">Fin</label>
                            <input type="time" class="form-control" id="new-session-end" name="new_session[ends_at]" value="{{ old('new_session.ends_at') }}">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label mb-1" for="new-session-teacher">Docente</label>
                            <input type="text" class="form-control" id="new-session-teacher" name="new_session[teacher_name]" value="{{ old('new_session.teacher_name') }}" maxlength="255">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label mb-1" for="new-session-topic">Tema</label>
                            <input type="text" class="form-control" id="new-session-topic" name="new_session[topic]" value="{{ old('new_session.topic') }}" maxlength="255">
                        </div>
                    </div>
                    <div class="form-text">Deje el tema de la fila «Nueva» vacío para no agregar ninguna sesión.</div>
                </div>
                <div class="card-footer d-flex gap-2">
                    <button type="submit" class="btn btn-primary" data-testid="btn-sync-course-edition-sessions">Guardar sesiones</button>
                    <a href="{{ route('course-talks.editions.show', $edition) }}" class="btn btn-outline-secondary">Cancelar</a>
                </div>
            </div>
        </form>
    @endisset
@endsection
