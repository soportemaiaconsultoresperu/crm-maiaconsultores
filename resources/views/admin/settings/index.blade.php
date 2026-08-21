@extends('layouts.app')

@use(Illuminate\Support\Arr)

@section('title', 'Configuración')
@section('page-title', 'Configuración del sistema')

@section('content')
    @if (session('status'))
        <x-alert type="success" :dismissible="true">{{ session('status') }}</x-alert>
    @endif

    @php
        // Load the whole settings translation array once; the translator treats
        // dots in keys as array-path separators, so we use Arr::get() to walk
        // nested keys like `settings.keys.company.name.label` safely.
        $settingsTranslations = trans('settings');
        $groupLabelFor = fn (string $name): string => Arr::get($settingsTranslations, 'groups.' . $name) ?: ucfirst($name);
        $keyLabelFor = fn (string $key, string $fallback): string => Arr::get($settingsTranslations, 'keys.' . $key . '.label') ?: $fallback;
        $keyHelpFor = fn (string $key): string => Arr::get($settingsTranslations, 'keys.' . $key . '.help') ?: '';
        $keyTypeFor = fn (string $key): string => Arr::get($settingsTranslations, 'keys.' . $key . '.type') ?: '';
    @endphp

    <form method="POST" action="{{ route('admin.settings.update') }}">
        @csrf
        @method('PUT')

        @foreach ($groups as $groupName => $entries)
            <div class="card mb-3">
                <div class="card-header">
                    <h3 class="card-title mb-0">{{ $groupLabelFor($groupName) }}</h3>
                </div>
                <div class="card-body">
                    @foreach ($entries as $entry)
                        @php
                            $value = old("settings.{$loop->index}.value", $entry['casted']);
                            $key = $entry['key'];
                            $label = $keyLabelFor($key, $key);
                            $help = $keyHelpFor($key);
                            $fieldType = $keyTypeFor($key);
                        @endphp
                        <div class="mb-3">
                            <input type="hidden" name="settings[{{ $loop->index }}][key]" value="{{ $key }}">
                            <input type="hidden" name="settings[{{ $loop->index }}][type]" value="{{ $entry['type'] }}">
                            <input type="hidden" name="settings[{{ $loop->index }}][group]" value="{{ $groupName }}">

                            @if ($fieldType === 'image')
                                {{-- Image field: rendered OUTSIDE the main form (it posts to its own route). --}}
                                @include('admin.settings.partials.logo-upload', [
                                    'value' => $value,
                                    'label' => $label,
                                    'help' => $help,
                                    'previewUrl' => !empty($value) ? route('admin.settings.logo.preview') : null,
                                ])
                            @else
                                @switch($entry['type'])
                                    @case('boolean')
                                        <div class="form-check">
                                            <input type="hidden" name="settings[{{ $loop->index }}][value]" value="0">
                                            <input class="form-check-input" type="checkbox"
                                                   name="settings[{{ $loop->index }}][value]"
                                                   id="setting-{{ \Illuminate\Support\Str::slug($key, '_') }}"
                                                   value="1"
                                                   @checked((bool) $value)>
                                            <label class="form-check-label"
                                                   for="setting-{{ \Illuminate\Support\Str::slug($key, '_') }}">
                                                {{ $label }}
                                            </label>
                                            @if ($help)
                                                <div class="form-text">{{ $help }}</div>
                                            @endif
                                        </div>
                                        @break
                                    @case('integer')
                                        <label class="form-label">{{ $label }}</label>
                                        <input type="number" step="1"
                                               name="settings[{{ $loop->index }}][value]"
                                               value="{{ $value }}" class="form-control">
                                        @break
                                    @case('decimal')
                                        <label class="form-label">{{ $label }}</label>
                                        <input type="number" step="0.01"
                                               name="settings[{{ $loop->index }}][value]"
                                               value="{{ $value }}" class="form-control">
                                        @break
                                    @case('json')
                                        <label class="form-label">{{ $label }} <small class="text-secondary">(JSON)</small></label>
                                        <textarea name="settings[{{ $loop->index }}][value]" rows="3"
                                                  class="form-control font-monospace">{{ is_array($value) ? json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $value }}</textarea>
                                        @break
                                    @default
                                        <label class="form-label">{{ $label }}</label>
                                        <input type="text"
                                               name="settings[{{ $loop->index }}][value]"
                                               value="{{ $value }}" class="form-control">
                                @endswitch
                                @if ($help && $entry['type'] !== 'boolean')
                                    <div class="form-text">{{ $help }}</div>
                                @endif
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach

        @can('settings.manage')
            <div class="d-flex justify-content-end">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-1" aria-hidden="true"></i> Guardar parámetros
                </button>
            </div>
        @endcan
    </form>
@endsection
