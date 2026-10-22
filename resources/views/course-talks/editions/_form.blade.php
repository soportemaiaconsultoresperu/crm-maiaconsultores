{{--
    Fields of the dictado form, shared by create.blade.php and edit.blade.php.
    Receives $activity, $modalities, $responsibleOptions and the $edition being
    edited (null on create).
--}}
@php
    $edition = $edition ?? null;
    $modalityOptions = collect($modalities)->mapWithKeys(fn ($modality) => [$modality->value => $modality->label()])->all();
    // A new delivery's syllabus starts as the activity's BASE syllabus: the course
    // feeds the delivery, and the operator then edits it for this delivery. An
    // edited delivery starts from its own syllabus. This is SERVER-SIDE on purpose
    // — the activity is already bound when the controller renders this view, so the
    // form arrives pre-filled with no JavaScript at all and a user without scripts
    // gets the exact same form. `old()` still wins when it is present, so a rejected
    // submission re-renders what the operator typed instead of the template.
    $syllabus = old('syllabus_override_json', $edition !== null ? ($edition->syllabus_override_json ?? []) : ($activity->base_syllabus_json ?? []));
    $syllabus = is_array($syllabus) && $syllabus !== [] ? array_values($syllabus) : [''];
    // A nested-array entry survives StoreCourseEditionRequest::prepareForValidation()
    // so the `string` rule can reject it; on the re-rendered form it must still
    // print as text instead of reaching htmlspecialchars() as an array (which
    // would return a 500 instead of the intended validation error).
    $syllabus = array_map(
        static fn ($topic): string => match (true) {
            $topic === null => '',
            is_scalar($topic) => (string) $topic,
            default => (json_encode($topic, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
        },
        $syllabus,
    );
@endphp

<div class="row g-3">
    <div class="col-md-4">
        <x-text-input name="code" label="Código" :value="$edition?->code" maxlength="60" help="Opcional. Debe ser único entre todos los dictados."/>
    </div>
    <div class="col-md-4">
        <x-select name="modality" label="Modalidad" :options="$modalityOptions" :value="old('modality', $edition?->modality->value)" placeholder="Seleccione" :required="true"/>
    </div>
    <div class="col-md-4">
        <x-text-input name="price_amount" type="number" label="Precio"
                      :value="$edition?->price_amount" step="0.01" min="0" :required="true"
                      :help="$edition !== null ? 'Cambiar el precio no modifica el importe de las inscripciones ya registradas.' : null"/>
    </div>
    {{-- The certificate charge is DELIVERY money, and it only applies to a
         talk that includes a certificate. That decision is made
         SERVER-SIDE: the activity is already bound when this view renders,
         so the field simply is not rendered when it cannot apply. Hiding it
         with script would be the weaker option — a script is not a rule, it
         can be skipped, and for a course the value would be zeroed by
         `CourseEdition`'s write guard anyway, so offering the control would
         promise a change the domain refuses to keep.
         `CourseActivity::issuesTalkCertificate()` owns the rule; this view
         only asks it. --}}
    @if ($activity->issuesTalkCertificate())
        <div class="col-md-4" data-testid="course-talks-edition-certificate-charge">
            <x-text-input name="certificate_charge_amount" type="number" label="Precio del certificado"
                          :value="$edition?->certificate_charge_amount" step="0.01" min="0"
                          help="Se suma al precio en cada matrícula de este dictado. Use 0 si el certificado no se cobra."/>
        </div>
    @endif
    <div class="col-md-4">
        {{-- The person who follows up this delivery; the delivery alerts show and
             filter by this name. --}}
        <x-select name="responsible_user_id" label="Responsable" :options="$responsibleOptions"
                  :value="old('responsible_user_id', $edition?->responsible_user_id)" placeholder="Sin responsable"
                  help="Opcional. Aparece en las alertas de entrega y permite filtrarlas."/>
    </div>
    <div class="col-md-3">
        <x-text-input name="starts_on" type="date" label="Fecha de inicio" :value="$edition?->starts_on?->format('Y-m-d')"/>
    </div>
    <div class="col-md-3">
        <x-text-input name="ends_on" type="date" label="Fecha de fin" :value="$edition?->ends_on?->format('Y-m-d')"/>
    </div>
    <div class="col-md-6">
        <x-text-input name="address" label="Dirección" :value="$edition?->address" maxlength="255"
                      help="Requerida para modalidad Presencial o Híbrida."/>
    </div>
    <div class="col-md-6">
        <x-text-input name="access_url" label="Enlace de acceso virtual" :value="$edition?->access_url" maxlength="255"
                      help="Requerido para modalidad Virtual o Híbrida."/>
    </div>
    <div class="col-12">
        <x-label for="syllabus_override_json" label="Temario del dictado"/>
        @foreach ($syllabus as $topic)
            <input type="text" name="syllabus_override_json[]" value="{{ $topic }}"
                   class="form-control mb-2 @error('syllabus_override_json.*') is-invalid @enderror"
                   maxlength="255" placeholder="Tema {{ $loop->iteration }}">
        @endforeach
        <x-validation-error name="syllabus_override_json.*"/>
        <div class="form-text">Opcional. Deje los temas vacíos que no utilice; no se guardan.</div>
    </div>
</div>
