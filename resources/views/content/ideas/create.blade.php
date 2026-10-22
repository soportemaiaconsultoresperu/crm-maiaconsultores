@extends('layouts.app')

@section('title', 'Nueva idea de '.$project->name)
@section('page-title', 'Nueva idea de '.$project->name)

@section('content')
    @includeIf('content.partials._project_band', ['project' => $project])

    <a href="{{ route('content.projects.ideas.index', $project) }}" class="btn btn-outline-secondary mb-3">Volver a las ideas</a>

    @if ($errors->any())
        <div class="alert alert-danger" role="alert">
            <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    @include('content.ideas._form', [
        'project' => $project,
        'idea' => null,
        'form' => $form,
        'catalogOptions' => $catalogOptions,
    ])
@endsection
