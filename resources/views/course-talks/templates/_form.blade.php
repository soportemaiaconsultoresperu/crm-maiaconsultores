{{--
    Shared create/edit form for a certificate template, included by
    create.blade.php and edit.blade.php with $action, $verb, $heading,
    $submitLabel, $template (null when creating), $typeScopes and $backUrl.

    Only what an administrator may safely configure is rendered: the name, the
    type scope, and the four `settings_json` keys the certificate view consumes.
    There is deliberately NO `blade_view` selector — the render allowlist has
    exactly one member, so choosing the view that renders a legal document is not
    an administrator's decision — and no `html_template` field at all: raw HTML
    that would end up inside a generated PDF is an injection path the domain
    refuses to own. Neither attribute has a validation rule either, so a tampered
    payload cannot smuggle either one past this form.

    The settings inputs are plain HTML on purpose. A Blade component's attribute
    is not an interpolated expression: `name="settings_json[title]"` keeps the
    HTML name the browser posts, while the error bag is keyed in dot notation and
    must be asked for with `:name="'settings_json.title'"`. The same trap lost a
    validation message in unit 6.b, so the two forms are kept apart explicitly.
--}}
@php
    $stored = $template?->settings_json;
    $submitted = old('settings_json', is_array($stored) ? $stored : []);
    $settings = is_array($submitted) ? $submitted : [];

    // Presentation-only guards: a stored or re-submitted value must never reach
    // htmlspecialchars() as an array, which would turn an intended validation
    // error into a 500.
    $text = static fn (mixed $value): string => is_scalar($value) ? (string) $value : '';
    $setting = static fn (string $key): string => $text($settings[$key] ?? '');

    // The certificate view renders exactly two signatures
    // (array_slice(..., 0, 2)); the form offers those two rows and the domain
    // refuses a third one posted by hand.
    $signatureSlots = 2;
    $signature = static function (int $slot, string $key) use ($settings, $text): string {
        $row = $settings['signatures'][$slot] ?? null;

        return is_array($row) ? $text($row[$key] ?? '') : '';
    };
@endphp

@if ($errors->any())
    <x-alert type="error" data-testid="course-talks-template-errors">
        <ul class="mb-0">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </x-alert>
@endif

<form method="POST" action="{{ $action }}" data-testid="course-talks-template-form">
    @csrf
    @if ($verb !== 'POST')
        @method($verb)
    @endif

    <div class="card">
        <div class="card-header">
            <h3 class="card-title mb-0">{{ $heading }}</h3>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <x-text-input name="name" label="Nombre de la plantilla" :required="true" maxlength="255"
                                  :value="$template?->name"
                                  help="Nombre con el que identificará esta configuración."/>
                </div>
                <div class="col-md-6">
                    <x-select name="type_scope" label="Alcance por tipo de documento" :options="$typeScopes"
                              :value="$template?->type_scope" placeholder="Seleccione el alcance" :required="true"
                              help="La plantilla se usará para los certificados de este tipo. «Todos los tipos» se usa en los tipos que no tengan plantilla propia."/>
                </div>
            </div>

            <hr>

            <h4 class="h6">Textos del certificado</h4>
            <p class="text-secondary small">
                Deje un campo vacío para no cambiar nada: el sistema usa su texto por defecto.
            </p>

            <div class="row g-3">
                <div class="col-md-6">
                    <x-label for="settings-title" label="Título"/>
                    <input type="text" id="settings-title" name="settings_json[title]" maxlength="255"
                           class="form-control @error('settings_json.title') is-invalid @enderror"
                           value="{{ $setting('title') }}" placeholder="Certificado">
                    <x-validation-error :name="'settings_json.title'"/>
                </div>
                <div class="col-md-6">
                    <x-label for="settings-company" label="Empresa"/>
                    <input type="text" id="settings-company" name="settings_json[company]" maxlength="255"
                           class="form-control @error('settings_json.company') is-invalid @enderror"
                           value="{{ $setting('company') }}" placeholder="Maia Consultores">
                    <x-validation-error :name="'settings_json.company'"/>
                </div>
                <div class="col-12">
                    <x-label for="settings-intro-text" label="Texto de introducción"/>
                    <input type="text" id="settings-intro-text" name="settings_json[intro_text]" maxlength="255"
                           class="form-control @error('settings_json.intro_text') is-invalid @enderror"
                           value="{{ $setting('intro_text') }}" placeholder="Otorga el presente certificado a">
                    <x-validation-error :name="'settings_json.intro_text'"/>
                </div>
            </div>

            <h4 class="h6 mt-4">Firmas</h4>
            <p class="text-secondary small">
                El certificado lleva como máximo dos firmas. Deje las dos filas vacías para usar las firmas por defecto del sistema.
            </p>

            <x-validation-error :name="'settings_json.signatures'"/>

            @for ($slot = 0; $slot < $signatureSlots; $slot++)
                <div class="row g-2 align-items-end mb-2">
                    <div class="col-md-1 form-text">{{ $slot + 1 }}.</div>
                    <div class="col-md-5">
                        <x-label :for="'settings-signature-'.$slot.'-name'" label="Nombre"/>
                        <input type="text" id="settings-signature-{{ $slot }}-name"
                               name="settings_json[signatures][{{ $slot }}][name]" maxlength="255"
                               class="form-control @error('settings_json.signatures.'.$slot.'.name') is-invalid @enderror"
                               value="{{ $signature($slot, 'name') }}">
                        <x-validation-error :name="'settings_json.signatures.'.$slot.'.name'"/>
                    </div>
                    <div class="col-md-5">
                        <x-label :for="'settings-signature-'.$slot.'-role'" label="Cargo"/>
                        <input type="text" id="settings-signature-{{ $slot }}-role"
                               name="settings_json[signatures][{{ $slot }}][role]" maxlength="255"
                               class="form-control @error('settings_json.signatures.'.$slot.'.role') is-invalid @enderror"
                               value="{{ $signature($slot, 'role') }}">
                        <x-validation-error :name="'settings_json.signatures.'.$slot.'.role'"/>
                    </div>
                </div>
            @endfor
        </div>
        <div class="card-footer d-flex gap-2">
            <button type="submit" class="btn btn-primary" data-testid="btn-save-course-certificate-template">{{ $submitLabel }}</button>
            <a href="{{ $backUrl }}" class="btn btn-outline-secondary">Cancelar</a>
        </div>
    </div>
</form>
