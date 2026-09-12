@extends('layouts.app')

@section('title', 'Nueva plantilla de certificado')
@section('page-title', 'Nueva plantilla de certificado')

@section('content')
    <a href="{{ route('course-talks.templates.index') }}" class="btn btn-outline-secondary mb-3">Volver a plantillas</a>

    @include('course-talks.templates._form', [
        'action' => route('course-talks.templates.store'),
        'verb' => 'POST',
        'heading' => 'Nueva plantilla de certificado',
        'submitLabel' => 'Crear plantilla',
        'template' => null,
        'typeScopes' => $typeScopes,
        'backUrl' => route('course-talks.templates.index'),
    ])
@endsection
