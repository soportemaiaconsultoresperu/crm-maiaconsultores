@extends('layouts.app')

@section('title', 'Editar contenido de '.$project->name)
@section('page-title', 'Editar contenido de '.$project->name)

@section('content')
    @includeIf('content.partials._project_band', ['project' => $project])

    <a href="{{ route('content.projects.contents.show', [$project, $item]) }}" class="btn btn-outline-secondary mb-3">Volver al detalle</a>

    @if ($errors->any())
        <div class="alert alert-danger" role="alert">
            <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    @include('content.items._form', [
        'project' => $project,
        'item' => $item,
        'form' => $form,
        'catalogOptions' => $catalogOptions,
    ])
@endsection
