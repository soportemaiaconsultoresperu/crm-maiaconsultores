{{--
    Progress bar of a project or phase.

    Expected data: $percent (0-100), $color (hex), optional $height (px),
    optional $label (accessible name).
--}}
<div class="progress" role="progressbar" @style(['height: '.($height ?? 10).'px'])
     aria-label="{{ $label ?? 'Avance' }}" aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100">
    <div class="progress-bar" @style(['width: '.$percent.'%', 'background-color: '.$color])></div>
</div>
