{{--
    Fields of the activity form, shared by create.blade.php and edit.blade.php.
    Receives $types, the $activity being edited (null on create) and $typeLocked
    (true when the activity already has dictados, so its type cannot change).
--}}
@php
    $activity = $activity ?? null;
    $typeLocked = $typeLocked ?? false;
    $typeOptions = collect($types)->mapWithKeys(fn ($type) => [$type->value => $type->label()])->all();
    $topics = old('base_syllabus_json', $activity?->base_syllabus_json ?? []);
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
    $talkCertificate = $activity !== null && $activity->talk_includes_certificate ? '1' : '0';
    $isActive = $activity === null || $activity->is_active ? '1' : '0';
@endphp

<div class="row g-3">
    <div class="col-md-4">
        @if ($typeLocked)
            {{-- A disabled select does not post, so the current type travels in a
                 hidden input; CourseActivityService::update() refuses a change anyway. --}}
            <input type="hidden" name="type" value="{{ $activity->type->value }}">
            <x-select name="type" label="Tipo" :options="$typeOptions" :value="$activity->type->value" :required="true" disabled
                      help="No se puede cambiar porque ya tiene dictados."/>
        @else
            <x-select name="type" label="Tipo" :options="$typeOptions" :value="old('type', $activity?->type->value)" placeholder="Seleccione" :required="true"/>
        @endif
    </div>
    <div class="col-md-4">
        <x-text-input name="code" label="Código" :value="$activity?->code" :required="true" maxlength="60" help="El código es único entre todos los cursos y charlas."/>
    </div>
    <div class="col-md-4">
        <x-text-input name="name" label="Nombre" :value="$activity?->name" :required="true" maxlength="255"/>
    </div>
    <div class="col-md-4">
        <x-text-input name="official_academic_hours" type="number" label="Horas académicas"
                      :value="$activity?->official_academic_hours" step="0.01" min="0" :required="true"/>
    </div>
    {{-- The talk-only control is rendered VISIBLE and ENABLED on the
         server, and the script at the bottom of this view is what hides
         it for a course. That order is deliberate: a user whose script
         does not run still gets a usable form in which a talk can carry
         its certificate, and the field only disappears when something is
         actually running to keep it truthful (cleared and disabled, so
         no stale value can post).

         The certificate PRICE used to sit next to it and was REMOVED:
         `course_activities.talk_certificate_price` was dropped when the
         charge moved to the delivery, so the input collected a number
         the server silently ignored. The delivery form
         (`course-talks.editions.create`) is now the only place a
         certificate charge can be entered. --}}
    <div class="col-md-4 d-flex flex-column justify-content-end" data-talk-only>
        <div class="form-check mb-2">
            <input type="hidden" name="talk_includes_certificate" value="0">
            <input type="checkbox" name="talk_includes_certificate" id="talk_includes_certificate" value="1"
                   class="form-check-input @error('talk_includes_certificate') is-invalid @enderror"
                   @checked((string) old('talk_includes_certificate', $talkCertificate) === '1')>
            <label class="form-check-label" for="talk_includes_certificate">Charla con certificado</label>
            <x-validation-error name="talk_includes_certificate"/>
        </div>
    </div>
    <div class="col-md-4 d-flex flex-column justify-content-end">
        <div class="form-check">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" id="is_active" value="1"
                   class="form-check-input @error('is_active') is-invalid @enderror"
                   @checked((string) old('is_active', $isActive) === '1')>
            <label class="form-check-label" for="is_active">Activa</label>
            <div class="form-text">Si la desmarca, el curso o charla deja de aparecer en el catálogo y no admite nuevos dictados. Los dictados existentes no cambian.</div>
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

@once
    @push('scripts')
        <script>
            (function () {
                'use strict';

                // Hiding the talk-only certificate checkbox while the type is a course.
                //
                // Progressive enhancement, in this order on purpose: the server renders
                // the control visible and enabled, and THIS script is what hides it.
                // A user whose script does not run therefore keeps a working form in
                // which a talk can carry its certificate, and only loses the tidiness
                // of not seeing an inapplicable control while creating a course.
                // Nothing is hidden from someone who cannot un-hide it.
                //
                // Hiding alone would also be a lie, because a hidden control still
                // posts: the value is cleared and the control DISABLED (a disabled
                // control is excluded from the submitted payload), which is what stops
                // a seller who ticked "Charla con certificado" and then switched to
                // Course from saving it on a course. Switching back to Talk un-hides
                // and re-enables the control but does NOT resurrect the cleared value
                // — the operator re-ticks it rather than inheriting a declaration they
                // discarded.
                //
                // The DOM contract the server owns: the create and edit forms carry
                // the data-course-activity-form attribute, the type <select> is named
                // "type" and uses the CourseActivityType values, and each wrapper the
                // script may hide is marked data-talk-only. The script reads those
                // hooks and never hard-codes the visibility of anything else. There is
                // one such wrapper today (the certificate checkbox); the loop stays
                // generic so a future talk-only field needs no script change.
                //
                // No test seam: this repository has no JavaScript test runner, so the
                // behaviour described above is NOT covered by an assertion anywhere.
                // What IS covered is the server half of the same rule —
                // CourseActivityService rejects talk certificate data on a course, and
                // tests/Feature/Courses/CourseActivityCreateHttpTest.php pins it.
                var form = document.querySelector('[data-course-activity-form]');
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
