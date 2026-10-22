{{--
    Phase create/edit form (rendered inside a modal, team only).

    Expected data: $project, $phase (null on create).
--}}
@php
    $isEdit = $phase !== null;
    $prefix = $isEdit ? 'phase-'.$phase->id.'-' : 'phase-new-';
@endphp
<form method="POST"
      action="{{ $isEdit ? route('internal-projects.phases.update', [$project, $phase]) : route('internal-projects.phases.store', $project) }}"
      data-testid="{{ $isEdit ? 'phase-edit-form' : 'phase-create-form' }}">
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif
    <div class="row g-3">
        <div class="col-md-8">
            <label for="{{ $prefix }}name" class="form-label">Nombre <span class="text-danger">*</span></label>
            <input type="text" name="name" id="{{ $prefix }}name" class="form-control" maxlength="120" required value="{{ $phase?->name }}">
        </div>
        <div class="col-md-4">
            <label for="{{ $prefix }}sort" class="form-label">Orden</label>
            <input type="number" name="sort" id="{{ $prefix }}sort" class="form-control" min="0" value="{{ $phase?->sort }}"
                   placeholder="{{ $isEdit ? '' : 'Al final' }}">
        </div>
        <div class="col-md-6">
            <label for="{{ $prefix }}starts_on" class="form-label">Inicio planificado</label>
            <input type="date" name="starts_on" id="{{ $prefix }}starts_on" class="form-control" value="{{ $phase?->starts_on?->format('Y-m-d') }}">
        </div>
        <div class="col-md-6">
            <label for="{{ $prefix }}ends_on" class="form-label">Fin planificado</label>
            <input type="date" name="ends_on" id="{{ $prefix }}ends_on" class="form-control" value="{{ $phase?->ends_on?->format('Y-m-d') }}">
        </div>
    </div>
    <div class="d-flex justify-content-end gap-2 mt-3">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary">{{ $isEdit ? 'Guardar fase' : 'Agregar fase' }}</button>
    </div>
</form>
