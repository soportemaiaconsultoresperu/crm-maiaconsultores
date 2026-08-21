{{-- B12-UI — PR 3 / Stage 3B-2 — RuleForm Blade view.
     Hybrid Livewire + standard form: POSTs / PUTs to the server-side routes
     `admin.automations.store` / `admin.automations.update`; wire:model keeps
     the Livewire state in sync for inline UI (add/remove groups, etc.).
     B12.5-POL-01: wire:sort containers on the groups + actions loops. --}}
<div class="card"><div class="card-body">
    <form method="POST" action="{{ $mode === 'edit' ? route('admin.automations.update', $ruleId) : route('admin.automations.store') }}">
        @csrf
        @if ($mode === 'edit') @method('PUT') @endif

        <div class="row g-3 mb-3">
            <div class="col-md-8">
                <label for="rule-name" class="form-label">Nombre</label>
                <input type="text" id="rule-name" name="name" class="form-control @error('name') is-invalid @enderror" wire:model="name" required>
                <x-validation-error name="name" />
            </div>
            <div class="col-md-4">
                <label for="rule-order" class="form-label">Orden</label>
                <input type="number" id="rule-order" name="order" min="1" class="form-control @error('order') is-invalid @enderror" wire:model="order">
                <x-validation-error name="order" />
            </div>
        </div>

        <div class="mb-3">
            <label for="rule-description" class="form-label">Descripción</label>
            <textarea id="rule-description" name="description" rows="2" class="form-control @error('description') is-invalid @enderror" wire:model="description"></textarea>
            <x-validation-error name="description" />
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <label for="rule-trigger" class="form-label">Trigger</label>
                <select id="rule-trigger" name="trigger_event" class="form-select @error('trigger_event') is-invalid @enderror" wire:model="trigger_event" required>
                    <option value="">— Seleccionar —</option>
                    @foreach ($this->triggers as $fqcn)
                        <option value="{{ $fqcn }}">{{ class_basename($fqcn) }}</option>
                    @endforeach
                </select>
                <x-validation-error name="trigger_event" />
            </div>
            <div class="col-md-3">
                <label class="form-label d-block">Modo</label>
                <div class="btn-group btn-group-sm" role="group" aria-label="Modo de la regla">
                    <input type="radio" id="mode-live" name="mode" value="live" class="btn-check" wire:model="ruleMode">
                    <label for="mode-live" class="btn btn-outline-primary">Live</label>
                    <input type="radio" id="mode-test" name="mode" value="test" class="btn-check" wire:model="ruleMode">
                    <label for="mode-test" class="btn btn-outline-primary">Test</label>
                </div>
                <x-validation-error name="mode" />
            </div>
            <div class="col-md-3">
                <div class="form-check mt-4">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" id="rule-is-active" name="is_active" value="1" class="form-check-input" wire:model.boolean="is_active">
                    <label for="rule-is-active" class="form-check-label">Activa</label>
                </div>
                <x-validation-error name="is_active" />
            </div>
        </div>

        <h5 class="mt-4">Condiciones</h5>
        <div wire:sort="reorderGroups" data-testid="rule-form-groups" class="d-flex flex-column gap-2 mb-2">
            @foreach ($groups as $index => $group)
                <div data-testid="rule-form-group-row" wire:key="group-{{ $index }}">
                    <livewire:admin.automations.condition-group-editor
                        :group="$group['conditions'] ?? []"
                        :groupIndex="$index"
                        :logicalOperator="$group['logical_operator'] ?? 'AND'"
                        :wire:key="'group-'.$index" />
                </div>
            @endforeach
        </div>
        <button type="button" class="btn btn-sm btn-outline-primary" wire:click="addGroup">
            <i class="bi bi-plus-circle me-1" aria-hidden="true"></i> Añadir grupo
        </button>

        <h5 class="mt-4">Acciones</h5>
        <div wire:sort="reorderActions" data-testid="rule-form-actions" class="d-flex flex-column gap-2 mb-2">
            @foreach ($actions as $index => $action)
                <div data-testid="rule-form-action-row" wire:key="action-{{ $index }}"><div class="card mb-2"><div class="card-body">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label small mb-1" for="action-type-{{ $index }}">Tipo</label>
                            <select id="action-type-{{ $index }}" class="form-select form-select-sm" name="actions[{{ $index }}][type]" wire:model="actions.{{ $index }}.type">
                                @foreach ($this->actionTypes as $type)
                                    <option value="{{ $type }}">{{ $type }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1" for="action-channel-{{ $index }}">Canal</label>
                            <input type="text" id="action-channel-{{ $index }}" class="form-control form-control-sm" name="actions[{{ $index }}][channel]" wire:model="actions.{{ $index }}.channel">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1" for="action-recipient-{{ $index }}">Recipient strategy</label>
                            <input type="text" id="action-recipient-{{ $index }}" class="form-control form-control-sm" name="actions[{{ $index }}][recipient_strategy]" wire:model="actions.{{ $index }}.recipient_strategy">
                        </div>
                        <div class="col-md-2">
                            <div class="form-check mt-3">
                                <input type="hidden" name="actions[{{ $index }}][is_active]" value="0">
                                <input type="checkbox" id="action-active-{{ $index }}" class="form-check-input" name="actions[{{ $index }}][is_active]" value="1" wire:model.boolean="actions.{{ $index }}.is_active">
                                <label class="form-check-label small" for="action-active-{{ $index }}">Activa</label>
                            </div>
                        </div>
                        <div class="col-md-2 text-end">
                            <button type="button" class="btn btn-sm btn-outline-danger w-100" wire:click="removeAction({{ $index }})" aria-label="Eliminar acción">
                                <i class="bi bi-trash" aria-hidden="true"></i> Quitar
                            </button>
                        </div>
                        <div class="col-12">
                            <label class="form-label small mb-1">Widget por tipo</label>
                            <livewire:admin.automations.action-editor
                                :actionIndex="$index"
                                :action="$action"
                                :editorUserId="auth()->id() ?? 0"
                                :wire:key="'action-editor-' . $index" />
                        </div>
                        <input type="hidden" name="actions[{{ $index }}][payload_json]" value="{{ is_array($action['payload_json'] ?? null) ? json_encode($action['payload_json']) : ($action['payload_json'] ?? '{}') }}">
                        <input type="hidden" name="actions[{{ $index }}][position]" value="{{ $action['position'] ?? ($index + 1) }}">
                    </div>
                </div></div></div>
            @endforeach
        </div>
        <button type="button" class="btn btn-sm btn-outline-primary" wire:click="addAction">
            <i class="bi bi-plus-circle me-1" aria-hidden="true"></i> Añadir acción
        </button>

        <input type="hidden" name="owner_id" value="{{ $owner_id ?? '' }}">

        <hr class="my-4">

        <div class="d-flex justify-content-end gap-2">
            <a href="{{ route('admin.automations.index') }}" class="btn btn-secondary">Cancelar</a>
            <button type="submit" class="btn btn-primary">Guardar regla</button>
        </div>
    </form>
</div></div>
