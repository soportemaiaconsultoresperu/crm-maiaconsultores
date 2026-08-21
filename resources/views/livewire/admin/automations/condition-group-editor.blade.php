{{-- B12-UI — PR 3 / Stage 3B-1 — ConditionGroupEditor Blade view. --}}
<div class="card mb-3" wire:key="group-{{ $groupIndex }}">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <strong>Grupo {{ $groupIndex + 1 }} ({{ strtoupper($logicalOperator) }})</strong>
        <div class="btn-group btn-group-sm" role="group" aria-label="Operador lógico del grupo">
            <button type="button"
                    class="btn {{ $logicalOperator === 'AND' ? 'btn-primary' : 'btn-outline-secondary' }}"
                    wire:click="updateLogicalOperator('AND')"
                    aria-pressed="{{ $logicalOperator === 'AND' ? 'true' : 'false' }}">AND</button>
            <button type="button"
                    class="btn {{ $logicalOperator === 'OR' ? 'btn-primary' : 'btn-outline-secondary' }}"
                    wire:click="updateLogicalOperator('OR')"
                    aria-pressed="{{ $logicalOperator === 'OR' ? 'true' : 'false' }}">OR</button>
        </div>
    </div>
    <div class="card-body">
        @forelse ($conditions as $index => $condition)
            <div class="row g-2 align-items-end mb-2" wire:key="cond-{{ $groupIndex }}-{{ $index }}">
                <div class="col-md-3">
                    <label class="form-label small mb-1">Campo</label>
                    <input type="text" class="form-control form-control-sm"
                           wire:model="conditions.{{ $index }}.field" autocomplete="off">
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1">Operador</label>
                    <select class="form-select form-select-sm" wire:model="conditions.{{ $index }}.operator">
                        @foreach (\App\Enums\ConditionOperator::values() as $op)
                            <option value="{{ $op }}">{{ $op }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Tipo</label>
                    <select class="form-select form-select-sm" wire:model="conditions.{{ $index }}.value_type">
                        @foreach (['string','int','bool','date','datetime','enum','array'] as $vt)
                            <option value="{{ $vt }}">{{ $vt }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1">Valor</label>
                    <input type="text" class="form-control form-control-sm"
                           wire:model="conditions.{{ $index }}.value" autocomplete="off">
                </div>
                <div class="col-md-1">
                    <button type="button" class="btn btn-sm btn-outline-danger w-100"
                            wire:click="removeCondition({{ $index }})"
                            aria-label="Eliminar condición" title="Eliminar condición">
                        <i class="bi bi-trash" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
        @empty
            <p class="text-muted small mb-2">Sin condiciones. Agregue al menos una.</p>
        @endforelse
        <button type="button" class="btn btn-sm btn-outline-primary" wire:click="addCondition">
            <i class="bi bi-plus-circle me-1" aria-hidden="true"></i> Agregar condición
        </button>
    </div>
</div>