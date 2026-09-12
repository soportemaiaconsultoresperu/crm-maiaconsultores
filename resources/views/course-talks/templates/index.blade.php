@extends('layouts.app')

@section('title', 'Plantillas de certificados')
@section('page-title', 'Plantillas de certificados')

@section('content')
    @php
        // Presentation-only: the scope, the version and the active state are the
        // domain's (CourseCertificateTemplateService derives the version and
        // decides which revision is in force), and the settings are rendered
        // exactly as they were stored, escaped. `blade_view` is deliberately not
        // shown — which view renders the PDF is not an administrator-facing
        // concept — and `html_template` is never read here.
        $canManage = Gate::allows('manage', App\Models\Courses\CourseCertificateTemplate::class);

        $settingLabels = [
            'title' => 'Título',
            'intro_text' => 'Texto de introducción',
            'company' => 'Empresa',
        ];

        $text = static fn (mixed $value): string => is_scalar($value) ? (string) $value : '';
    @endphp

    <a href="{{ route('course-talks.activities.index') }}" class="btn btn-outline-secondary mb-3">Volver a actividades</a>

    <p class="text-secondary">
        Una plantilla define lo que un certificado generado muestra. Sólo puede haber una plantilla activa por alcance:
        al activar una, las demás del mismo alcance quedan inactivas. Sin plantilla activa, el sistema usa su diseño por defecto.
    </p>

    <x-table title="Plantillas de certificados" data-testid="course-talks-templates-table">
        @slot('filters')
            @if ($canManage)
                <a class="btn btn-sm btn-primary" data-testid="course-talks-template-create-link" href="{{ route('course-talks.templates.create') }}">Nueva plantilla</a>
            @endif
        @endslot
        @slot('headers')
            <tr>
                <th scope="col">Plantilla</th>
                <th scope="col">Alcance</th>
                <th scope="col">Versión</th>
                <th scope="col">Estado</th>
                <th scope="col">Configuración aplicada</th>
                <th scope="col"></th>
            </tr>
        @endslot
        @slot('rows')
            @forelse ($templates as $template)
                @php
                    $settings = is_array($template->settings_json) ? $template->settings_json : [];
                    $signatures = is_array($settings['signatures'] ?? null) ? array_values($settings['signatures']) : [];

                    // Only what can be rendered as text is rendered: a row written
                    // outside the domain can hold anything, and a value that is not
                    // a non-empty string configures nothing anyway.
                    $defined = [];
                    foreach ($settings as $key => $value) {
                        if ($key === 'signatures' || ! is_string($value) || trim($value) === '') {
                            continue;
                        }

                        $defined[$key] = $value;
                    }
                @endphp
                <tr data-testid="course-talks-template-row-{{ $template->id }}">
                    <td><strong>{{ $template->name }}</strong></td>
                    <td>{{ $typeScopes[$template->type_scope ?? ''] ?? ($template->type_scope ?? '—') }}</td>
                    <td>v{{ $template->version }}</td>
                    <td>
                        <span class="badge {{ $template->is_active ? 'text-bg-success' : 'text-bg-secondary' }}"
                              data-testid="course-talks-template-state-{{ $template->id }}">
                            {{ $template->is_active ? 'Activa' : 'Inactiva' }}
                        </span>
                    </td>
                    <td data-testid="course-talks-template-settings-{{ $template->id }}">
                        @if ($defined === [] && $signatures === [])
                            <span class="text-secondary">Sin configuración; usa los valores del sistema.</span>
                        @else
                            <ul class="list-unstyled mb-0 small">
                                @foreach ($defined as $key => $value)
                                    <li><span class="text-secondary">{{ $settingLabels[$key] ?? $key }}:</span> {{ $value }}</li>
                                @endforeach
                                @foreach ($signatures as $signature)
                                    @if (is_array($signature))
                                        <li>
                                            <span class="text-secondary">Firma:</span>
                                            {{ $text($signature['name'] ?? '') }} — {{ $text($signature['role'] ?? '') }}
                                        </li>
                                    @endif
                                @endforeach
                            </ul>
                        @endif
                    </td>
                    <td class="text-end">
                        @if ($canManage)
                            <a class="btn btn-sm btn-outline-primary" href="{{ route('course-talks.templates.edit', $template) }}"
                               data-testid="course-talks-template-edit-{{ $template->id }}">Editar</a>
                            @if ($template->is_active)
                                <form method="POST" action="{{ route('course-talks.templates.deactivate', $template) }}" class="d-inline"
                                      data-testid="course-talks-template-deactivate-form-{{ $template->id }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-secondary">Desactivar</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('course-talks.templates.activate', $template) }}" class="d-inline"
                                      data-testid="course-talks-template-activate-form-{{ $template->id }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-success">Activar</button>
                                </form>
                            @endif
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center text-secondary py-4" data-testid="course-talks-templates-empty">
                        Todavía no hay plantillas configuradas. Sin plantilla activa, los certificados usan el diseño por defecto del sistema.
                    </td>
                </tr>
            @endforelse
        @endslot
    </x-table>
@endsection
