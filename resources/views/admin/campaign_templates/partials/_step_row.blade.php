@php
    $actionTypes = \App\Models\ActivityType::query()->where('is_active', true)->orderBy('sort')->get(['id', 'name'])->mapWithKeys(fn ($t) => [$t->id => $t->name])->all();
@endphp

<div class="card mb-2 p-3 step-row">
    <div class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small">Tipo</label>
            <select name="steps[{{ $i }}][action_type_id]" class="form-select form-select-sm @error('steps.' . $i . '.action_type_id') is-invalid @enderror" required>
                @foreach ($actionTypes as $id => $name)
                    <option value="{{ $id }}" @selected(old('steps.' . $i . '.action_type_id', $step['action_type_id'] ?? '') == $id)>{{ $name }}</option>
                @endforeach
            </select>
            <x-validation-error name="steps.{{ $i }}.action_type_id"/>
        </div>
        <div class="col-md-3">
            <label class="form-label small">Título</label>
            <input type="text" name="steps[{{ $i }}][title]" value="{{ $step['title'] ?? '' }}" class="form-control form-control-sm @error('steps.' . $i . '.title') is-invalid @enderror" required>
            <x-validation-error name="steps.{{ $i }}.title"/>
        </div>
        <div class="col-md-2">
            <label class="form-label small">Día</label>
            <input type="number" name="steps[{{ $i }}][day_offset]" value="{{ $step['day_offset'] ?? 0 }}" min="0" class="form-control form-control-sm @error('steps.' . $i . '.day_offset') is-invalid @enderror">
            <x-validation-error name="steps.{{ $i }}.day_offset"/>
        </div>
        <div class="col-md-2">
            <label class="form-label small">Hora</label>
            <input type="time" name="steps[{{ $i }}][scheduled_time]" value="{{ $step['scheduled_time'] ?? '09:00' }}" class="form-control form-control-sm @error('steps.' . $i . '.scheduled_time') is-invalid @enderror">
            <x-validation-error name="steps.{{ $i }}.scheduled_time"/>
        </div>
        <div class="col-md-2 text-end">
            <button type="button" class="btn btn-sm btn-outline-danger remove-step-btn">
                <i class="bi bi-trash"></i>
            </button>
        </div>
    </div>
</div>
