@extends('layouts.app')

@section('title', 'Nuevo contenido de '.$project->name)
@section('page-title', 'Nuevo contenido de '.$project->name)

@section('content')
    <a href="{{ route('content.projects.contents.index', $project) }}" class="btn btn-outline-secondary mb-3">Volver al cronograma</a>

    @if ($errors->any())
        <div class="alert alert-danger" role="alert">
            <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    @include('content.items._form', [
        'project' => $project,
        'item' => null,
        'form' => $form,
        'catalogOptions' => $catalogOptions,
    ])
@endsection
