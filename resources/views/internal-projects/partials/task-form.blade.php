{{--
    Task create/edit form (rendered inside a modal, team only).

    Expected data: $project (phases loaded), $task (null on create),
    $phaseId (preselected phase on create), $users.
--}}
@php
    $isEdit = $task !== null;
    $prefix = $isEdit ? 'task-'.$task->id.'-' : 'task-new-'.$phaseId.'-';
    $selectedPhase = $task?->internal_project_phase_id ?? $phaseId;
@endphp
<form method="POST"
      action="{{ $isEdit ? route('internal-projects.tasks.update', [$project, $task]) : route('internal-projects.tasks.store', $project) }}"
      data-testid="{{ $isEdit ? 'task-edit-form' : 'task-create-form' }}">
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif
    <div class="row g-3">
        <div class="col-md-8">
            <label for="{{ $prefix }}title" class="form-label">Título <span class="text-danger">*</span></label>
            <input type="text" name="title" id="{{ $prefix }}title" class="form-control" maxlength="200" required value="{{ $task?->title }}">
        </div>
        <div class="col-md-4">
            <label for="{{ $prefix }}phase" class="form-label">Fase <span class="text-danger">*</span></label>
            <select name="internal_project_phase_id" id="{{ $prefix }}phase" class="form-select" required>
                @foreach ($project->phases as $option)
                    <option value="{{ $option->id }}" @selected($option->id === $selectedPhase)>{{ $option->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-12">
            <label for="{{ $prefix }}description" class="form-label">Descripción</label>
            <textarea name="description" id="{{ $prefix }}description" rows="2" class="form-control">{{ $task?->description }}</textarea>
        </div>
        <div class="col-md-6">
            <label for="{{ $prefix }}responsible" class="form-label">Responsable</label>
            <select name="responsible_user_id" id="{{ $prefix }}responsible" class="form-select">
                <option value="">Sin responsable</option>
                @foreach ($users as $user)
                    <option value="{{ $user->id }}" @selected($user->id === $task?->responsible_user_id)>{{ $user->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-6">
            <label for="{{ $prefix }}status" class="form-label">Estado</label>
            <select name="status" id="{{ $prefix }}status" class="form-select">
                @foreach (\App\Enums\InternalProjects\TaskStatus::labels() as $value => $label)
                    <option value="{{ $value }}" @selected($value === ($task?->status?->value ?? 'pendiente'))>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-4">
            <label for="{{ $prefix }}starts_on" class="form-label">Inicio <span class="text-danger">*</span></label>
            <input type="date" name="starts_on" id="{{ $prefix }}starts_on" class="form-control" required value="{{ $task?->starts_on?->format('Y-m-d') }}">
        </div>
        <div class="col-md-4">
            <label for="{{ $prefix }}due_on" class="form-label">Vencimiento <span class="text-danger">*</span></label>
            <input type="date" name="due_on" id="{{ $prefix }}due_on" class="form-control" required value="{{ $task?->due_on?->format('Y-m-d') }}">
        </div>
        <div class="col-md-4">
            <label for="{{ $prefix }}weight" class="form-label">Peso <span class="text-danger">*</span></label>
            <input type="number" name="weight" id="{{ $prefix }}weight" class="form-control" min="1" max="65535" required value="{{ $task?->weight ?? 1 }}">
            <div class="form-text">Cuánto cuenta en el avance.</div>
        </div>
        <div class="col-md-12">
            <input type="hidden" name="is_milestone" value="0">
            <div class="form-check">
                <input type="checkbox" class="form-check-input" name="is_milestone" id="{{ $prefix }}milestone" value="1" @checked($task?->is_milestone)>
                <label class="form-check-label" for="{{ $prefix }}milestone">Es un hito</label>
            </div>
        </div>
    </div>
    <div class="d-flex justify-content-end gap-2 mt-3">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary">{{ $isEdit ? 'Guardar tarea' : 'Agregar tarea' }}</button>
    </div>
</form>
