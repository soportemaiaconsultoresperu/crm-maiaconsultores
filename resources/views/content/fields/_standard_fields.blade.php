{{--
    Optional standard fields the project uses (S2). Expected data: $project.

    Each optional field posts `standard[<key>]` as 0 (hidden input) or 1 (the
    checked box). The mandatory fields are listed disabled and are never posted,
    so they cannot be hidden. Hiding a field keeps the stored data.
--}}
<section class="card mb-3" aria-labelledby="heading-campos-estandar">
    <div class="card-header"><h2 class="card-title mb-0" id="heading-campos-estandar">Campos estándar</h2></div>
    <div class="card-body">
        <p class="text-secondary small">
            Marque los campos que usa este proyecto. Un campo desmarcado deja de aparecer en los formularios de contenidos e ideas,
            en la grilla, en sus filtros y en las exportaciones; los datos ya registrados se conservan y vuelven a mostrarse si lo marca de nuevo.
            Al ocultar el pilar también se oculta el tema.
        </p>
        <form method="POST" action="{{ route('content.fields.standard.update', $project) }}"
              data-testid="content-standard-fields-form" data-swal-loading>
            @csrf
            @method('PUT')
            <div class="row g-2">
                @foreach (\App\Models\Content\ContentProject::MANDATORY_STANDARD_FIELDS as $key => $label)
                    <div class="col-md-4">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="standard-{{ $key }}" checked disabled>
                            <label class="form-check-label" for="standard-{{ $key }}">
                                {{ $label }} <span class="badge text-bg-secondary">Obligatorio</span>
                            </label>
                        </div>
                    </div>
                @endforeach
                @foreach (\App\Models\Content\ContentProject::HIDEABLE_STANDARD_FIELDS as $key => $label)
                    <div class="col-md-4">
                        <div class="form-check">
                            <input type="hidden" name="standard[{{ $key }}]" value="0">
                            <input class="form-check-input" type="checkbox" name="standard[{{ $key }}]" value="1"
                                   id="standard-{{ $key }}" data-testid="content-standard-field-{{ $key }}"
                                   @checked(! $project->isStandardFieldHidden($key))>
                            <label class="form-check-label" for="standard-{{ $key }}">{{ $label }}</label>
                        </div>
                    </div>
                @endforeach
            </div>
            <button type="submit" class="btn btn-primary mt-3" data-testid="btn-save-standard-fields">Guardar campos estándar</button>
        </form>
    </div>
</section>
