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
                    {{-- The two talk-only controls are rendered VISIBLE and ENABLED on the
                         server, and the script at the bottom of this view is what hides
                         them for a course. That order is deliberate: a user whose script
                         does not run still gets a usable form in which a talk can carry
                         its certificate, and the fields only disappear when something is
                         actually running to keep them truthful (cleared and disabled, so
                         no stale value can post). --}}
                    <div class="col-md-4" data-talk-only>
                        <x-text-input name="talk_certificate_price" type="number" label="Precio del certificado de charla"
                                      :value="old('talk_certificate_price')" step="0.01" min="0"/>
                    </div>
                    <div class="col-md-4 d-flex flex-column justify-content-end" data-talk-only>
                        <div class="form-check mb-2">
                            <input type="hidden" name="talk_includes_certificate" value="0">
                            <input type="checkbox" name="talk_includes_certificate" id="talk_includes_certificate" value="1"
                                   class="form-check-input @error('talk_includes_certificate') is-invalid @enderror"
                                   @checked((string) old('talk_includes_certificate', '0') === '1')>
                            <label class="form-check-label" for="talk_includes_certificate">Charla con certificado</label>
                            <x-validation-error name="talk_includes_certificate"/>
                        </div>
                    </div>
                    <div class="col-md-4 d-flex flex-column justify-content-end">
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

@once
    @push('scripts')
        <script>
            (function () {
                'use strict';

                // Hiding the talk-only certificate fields while the type is a course.
                //
                // Progressive enhancement, in this order on purpose: the server renders
                // the fields visible and enabled, and THIS script is what hides them.
                // A user whose script does not run therefore keeps a working form in
                // which a talk can carry its certificate price, and only loses the
                // tidiness of not seeing two inapplicable controls while creating a
                // course. Nothing is hidden from someone who cannot un-hide it.
                //
                // Hiding alone would also be a lie, because a hidden control still
                // posts: the value is cleared and the control DISABLED (a disabled
                // control is excluded from the submitted payload), which is what stops
                // a seller who typed a certificate price and then switched to Course
                // from saving it on a course. Switching back to Talk un-hides and
                // re-enables the fields but does NOT resurrect the cleared value — the
                // operator retypes the price rather than inheriting one they discarded.
                //
                // The DOM contract the server owns: the form carries
                // data-testid="course-talks-activity-create-form", the type <select> is
                // named "type" and uses the CourseActivityType values, and each wrapper
                // the script may hide is marked data-talk-only. The script reads those
                // hooks and never hard-codes the visibility of anything else.
                //
                // No test seam: this repository has no JavaScript test runner, so the
                // behaviour described above is NOT covered by an assertion anywhere.
                // What IS covered is the server half of the same rule —
                // CourseActivityService rejects talk certificate data on a course, and
                // tests/Feature/Courses/CourseActivityCreateHttpTest.php pins it.
                var form = document.querySelector('[data-testid="course-talks-activity-create-form"]');
                if (form === null) {
                    return;
                }

                var typeSelect = form.querySelector('select[name="type"]');
                var groups = form.querySelectorAll('[data-talk-only]');
                if (typeSelect === null || groups.length === 0) {
                    return;
                }

                // A control that carries a server-side validation error must stay
                // visible and editable. Hiding it would hide the operator's only clue
                // about why the form came back, and a disabled field they cannot fix is
                // worse than a field that does not apply: an invisible failure, which is
                // the exact class of defect this rule exists to prevent.
                function carriesError(group) {
                    return group.querySelector('.is-invalid, .invalid-feedback, [role="alert"]') !== null;
                }

                function sync() {
                    var course = typeSelect.value === 'course';

                    Array.prototype.forEach.call(groups, function (group) {
                        var hide = course && !carriesError(group);
                        group.hidden = hide;

                        Array.prototype.forEach.call(group.querySelectorAll('input, select, textarea'), function (control) {
                            // The paired hidden input is the fallback that makes an
                            // unchecked checkbox submit an explicit "0" instead of
                            // nothing, so it is never disabled or cleared.
                            if (control.type === 'hidden') {
                                return;
                            }

                            control.disabled = hide;

                            if (!hide) {
                                return;
                            }

                            if (control.type === 'checkbox' || control.type === 'radio') {
                                control.checked = false;
                            } else {
                                control.value = '';
                            }
                        });
                    });
                }

                typeSelect.addEventListener('change', sync);

                // Also on load: the form is re-rendered with old() input after a failed
                // submission, and a course selected there must not keep the certificate
                // data the operator had typed before switching the type.
                sync();
            })();
        </script>
    @endpush
@endonce
