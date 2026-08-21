<div class="row g-2">
    <div class="col-md-4">
        <label class="form-label small mb-1 d-block">Recipient strategy</label>
        <div class="btn-group btn-group-sm" role="group" aria-label="Recipient strategy">
            <input type="radio" id="assign-owner-{{ $actionIndex }}-current"
                class="btn-check" value="current" wire:model="recipient_strategy">
            <label for="assign-owner-{{ $actionIndex }}-current" class="btn btn-outline-primary">Actual</label>
            <input type="radio" id="assign-owner-{{ $actionIndex }}-user"
                class="btn-check" value="user" wire:model="recipient_strategy">
            <label for="assign-owner-{{ $actionIndex }}-user" class="btn btn-outline-primary">Usuario</label>
            <input type="radio" id="assign-owner-{{ $actionIndex }}-team"
                class="btn-check" value="team" wire:model="recipient_strategy">
            <label for="assign-owner-{{ $actionIndex }}-team" class="btn btn-outline-primary">Equipo</label>
            <input type="radio" id="assign-owner-{{ $actionIndex }}-round-robin"
                class="btn-check" value="round_robin" wire:model="recipient_strategy">
            <label for="assign-owner-{{ $actionIndex }}-round-robin" class="btn btn-outline-primary">Round robin</label>
        </div>
    </div>
    @if ($recipient_strategy === 'user')
        <div class="col-md-4">
            <label for="assign-owner-{{ $actionIndex }}-user-id" class="form-label small mb-1">Usuario</label>
            <select id="assign-owner-{{ $actionIndex }}-user-id"
                class="form-select form-select-sm"
                wire:model="user_id">
                <option value="">— Seleccionar —</option>
                @foreach ($this->visibleUsers as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>
    @endif
    @if (in_array($recipient_strategy, ['team', 'round_robin'], true))
        <div class="col-md-4">
            <label for="assign-owner-{{ $actionIndex }}-team-id" class="form-label small mb-1">Equipo</label>
            <select id="assign-owner-{{ $actionIndex }}-team-id"
                class="form-select form-select-sm"
                wire:model="team_id">
                <option value="">— Seleccionar —</option>
                @foreach ($this->teams as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>
    @endif
    <div class="col-md-1 ms-auto d-flex align-items-end">
        <button type="button" class="btn btn-sm btn-outline-primary w-100"
            wire:click="emit" aria-label="Aplicar cambios">
            <i class="bi bi-check2" aria-hidden="true"></i>
        </button>
    </div>
</div>
