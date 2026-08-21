{{--
    Breadcrumb items. Pages pass `$breadcrumbs` as
    [['label' => 'Prospectos', 'route' => 'leads.index'], ...].
    The last item is rendered as the active crumb.
--}}
<li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Inicio</a></li>
@forelse ($breadcrumbs ?? [] as $crumb)
    @if ($loop->last && empty($crumb['route']))
        <li class="breadcrumb-item active">{{ $crumb['label'] }}</li>
    @else
        <li class="breadcrumb-item">
            <a href="{{ $crumb['route'] ?? '#' }}">{{ $crumb['label'] }}</a>
        </li>
    @endif
@empty
    <li class="breadcrumb-item active">Dashboard</li>
@endforelse
