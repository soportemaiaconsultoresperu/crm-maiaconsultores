{{--
    Project identity band (S1.2). Include in the header of every project page.
    Expected data: $project. A project without colour uses the neutral default.
--}}
@php
    $bandColor = \App\Support\Content\ProjectColor::resolve($project->color);
@endphp
<div class="content-project-band d-flex align-items-center gap-2 mb-3"
     @style([\App\Support\Content\ProjectColor::softStyle($bandColor)])
     data-testid="content-project-band"
     data-project-color="{{ $bandColor }}">
    <span class="content-project-swatch" @style(['background-color: '.$bandColor]) aria-hidden="true"></span>
    <span class="fw-semibold">{{ $project->name }}</span>
</div>
