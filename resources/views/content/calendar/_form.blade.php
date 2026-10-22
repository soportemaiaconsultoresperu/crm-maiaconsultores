@php
    $monthKey = $month->format('Y-m');
    $isEditing = $editing !== null;
    $action = $isEditing
        ? route('content.projects.calendar.annotations.update', [$project, $editing])
        : route('content.projects.calendar.annotations.store', $project);
    $cancelUrl = route('content.projects.calendar', ['contentProject' => $project, 'month' => $monthKey]);
@endphp

<div class="card" id="calendario-formulario" data-testid="content-calendar-form-card">
    <div class="card-header">
        <h3 class="card-title mb-0">{{ $isEditing ? 'Editar fecha importante' : 'Agregar fecha importante' }}</h3>
    </div>
    <div class="card-body">
        @if ($canManage)
            <form method="POST" action="{{ $action }}" data-testid="content-calendar-form">
                @csrf
                @if ($isEditing)
                    @method('PUT')
                @endif

                <div class="row g-3">
                    <div class="col-sm-4">
                        <label for="date" class="form-label">Fecha</label>
                        <input type="date" id="date" name="date" value="{{ old('date', $formDate) }}"
                               class="form-control @error('date') is-invalid @enderror" required data-testid="content-calendar-field-date">
                        @error('date')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-sm-8">
                        <label for="name" class="form-label">Nombre</label>
                        <input type="text" id="name" name="name" value="{{ old('name', $editing?->name) }}"
                               maxlength="150" class="form-control @error('name') is-invalid @enderror"
                               placeholder="Festividad, aniversario o actividad" required data-testid="content-calendar-field-name">
                        @error('name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-12">
                        <label for="notes" class="form-label">Notas (opcional)</label>
                        <textarea id="notes" name="notes" rows="3" maxlength="2000"
                                  class="form-control @error('notes') is-invalid @enderror"
                                  data-testid="content-calendar-field-notes">{{ old('notes', $editing?->notes) }}</textarea>
                        @error('notes')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <div class="mt-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary" data-testid="content-calendar-submit">
                        {{ $isEditing ? 'Guardar cambios' : 'Agregar' }}
                    </button>
                    @if ($isEditing)
                        <a href="{{ $cancelUrl }}" class="btn btn-outline-secondary">Cancelar</a>
                    @endif
                </div>
            </form>
        @else
            <p class="text-muted mb-0" data-testid="content-calendar-form-readonly">
                Sólo un miembro con permiso de edición puede agregar o modificar fechas importantes.
            </p>
        @endif
    </div>
</div>
