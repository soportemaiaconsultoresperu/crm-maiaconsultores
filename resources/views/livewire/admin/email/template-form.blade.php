<div>
    <form method="POST"
          action="{{ $mode === 'edit' ? route('admin.email.templates.update', $templateId) : route('admin.email.templates.store') }}"
          class="vstack gap-3">

        @csrf
        @if ($mode === 'edit')
            @method('PUT')
        @endif

        <div class="row g-3">
            <div class="col-md-6">
                <label for="email-template-name" class="form-label">Nombre</label>
                <input type="text"
                       id="email-template-name"
                       name="name"
                       wire:model="name"
                       class="form-control @error('name') is-invalid @enderror"
                       required>
                @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <div class="col-md-6">
                <label for="email-template-slug" class="form-label">Slug</label>
                <input type="text"
                       id="email-template-slug"
                       name="slug"
                       wire:model="slug"
                       class="form-control @error('slug') is-invalid @enderror"
                       pattern="[a-z0-9_-]+"
                       required>
                @error('slug') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
        </div>

        <div>
            <label for="email-template-subject" class="form-label">Asunto</label>
            <input type="text"
                   id="email-template-subject"
                   name="subject"
                   wire:model="subject"
                   wire:change="updatedSubject"
                   class="form-control @error('subject') is-invalid @enderror"
                   maxlength="191"
                   required>
            @error('subject') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <label for="email-template-body-html" class="form-label">Cuerpo HTML</label>
                <textarea id="email-template-body-html"
                          name="body_html"
                          wire:model="bodyHtml"
                          wire:change="updatedBodyHtml"
                          class="form-control font-monospace @error('body_html') is-invalid @enderror"
                          rows="10"
                          required></textarea>
                @error('body_html') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="col-md-6">
                <label for="email-template-body-text" class="form-label">Cuerpo texto plano</label>
                <textarea id="email-template-body-text"
                          name="body_text"
                          wire:model="bodyText"
                          class="form-control font-monospace @error('body_text') is-invalid @enderror"
                          rows="10"
                          required></textarea>
                @error('body_text') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
        </div>

        <div>
            <label class="form-label d-block">Variables permitidas</label>
            <p class="text-muted small">
                Lista snake_case. Sólo se sustituyen las variables declaradas
                aquí — ninguna otra es interpolada (decisión 11c).
            </p>
            @forelse ($variablesArray as $index => $variable)
                <div class="input-group mb-2" wire:key="variable-{{ $index }}">
                    <input type="text"
                           name="variables_json[]"
                           value="{{ $variable }}"
                           wire:model="variablesArray.{{ $index }}"
                           class="form-control"
                           pattern="[a-z][a-z0-9_]*"
                           placeholder="nombre_variable">
                    <button type="button" wire:click="removeVariable({{ $index }})"
                            class="btn btn-outline-danger">
                        Quitar
                    </button>
                </div>
            @empty
                <p class="text-muted">Aún no se declararon variables permitidas.</p>
            @endforelse
            <button type="button" wire:click="addVariable" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-plus-circle me-1"></i> Añadir variable
            </button>
        </div>

        <div class="form-check">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox"
                   id="email-template-active"
                   name="is_active"
                   value="1"
                   wire:model="isActive"
                   class="form-check-input">
            <label for="email-template-active" class="form-check-label">Plantilla activa</label>
        </div>

        <div>
            <h3 class="h6 mt-3">Vista previa</h3>
            <p class="text-muted small">
                La sustitución se hace contra valores de prueba
                (<code>«nombre_variable»</code>) sólo para validar la
                sintaxis — no se envía nada aquí.
            </p>
            <div class="card border-secondary mb-3">
                <div class="card-header">
                    Asunto: <strong>{{ $previewSubject ?: '(vacío)' }}</strong>
                </div>
                <div class="card-body">
                    <pre class="mb-0 small">{{ $previewText ?: '(vacío)' }}</pre>
                </div>
            </div>
            <div class="card border-secondary">
                <div class="card-header">HTML</div>
                <div class="card-body">
                    <div class="small">{!! $previewHtml ?: '<span class="text-muted">(vacío)</span>' !!}</div>
                </div>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2">
            <button type="submit" class="btn btn-primary">Guardar plantilla</button>
            @if ($mode === 'edit')
                @can('email.send')
                    <button type="submit"
                            formaction="{{ route('admin.email.templates.send', $templateId) }}"
                            class="btn btn-outline-warning"
                            onclick="event.preventDefault();
                                const to = prompt('¿A qué correo quieres enviar la prueba?');
                                if (to) {
                                    const f = this.closest('form');
                                    const input = document.createElement('input');
                                    input.type = 'hidden';
                                    input.name = 'to';
                                    input.value = to;
                                    f.appendChild(input);
                                    f.action = this.getAttribute('formaction');
                                    f.submit();
                                }">
                        Envío de prueba
                    </button>
                @endcan
            @endif
        </div>
    </form>
</div>
