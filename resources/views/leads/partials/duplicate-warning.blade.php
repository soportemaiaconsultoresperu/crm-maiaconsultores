{{--
    Duplicate warning block (RF-LEAD-006, ADR-003). Rendered from the
    flashed 'duplicates' payload: ['critical' => [...], 'warning' => [...]]
    with code / full_name / field per match. The confirmation button lives
    in the form footer (_form.blade.php).
--}}
@php($duplicates = session('duplicates'))

@if (! empty($duplicates['critical']))
    <x-alert type="danger" data-testid="duplicate-warning">
        <strong>Posible duplicado crítico.</strong>
        Se encontraron prospectos con el mismo documento:
        <ul class="mb-0">
            @foreach ($duplicates['critical'] as $match)
                <li>
                    <strong>{{ $match['code'] }}</strong> — {{ $match['full_name'] }}
                    (coincide por {{ $match['field'] }})
                </li>
            @endforeach
        </ul>
    </x-alert>
@endif

@if (! empty($duplicates['warning']))
    <x-alert type="warning" class="mt-2" data-testid="duplicate-warning-soft">
        <strong>Posibles duplicados.</strong>
        Los siguientes prospectos coinciden por correo, teléfono o WhatsApp:
        <ul class="mb-0">
            @foreach ($duplicates['warning'] as $match)
                <li>
                    <strong>{{ $match['code'] }}</strong> — {{ $match['full_name'] }}
                    (coincide por {{ $match['field'] }})
                </li>
            @endforeach
        </ul>
    </x-alert>
@endif
