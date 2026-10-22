@extends('layouts.app')

@section('title', 'Editar dictado '.$edition->code)
@section('page-title', 'Editar dictado '.$edition->code)

@section('content')
    <a href="{{ route('course-talks.editions.show', $edition) }}" class="btn btn-outline-secondary mb-3">Volver al dictado</a>

    {{-- Field-less InvalidCourseEditionData failures are reported on the generic
         `edition` error key, which no form field renders. --}}
    @if ($errors->has('edition'))
        <x-alert type="error" data-testid="course-talks-edition-error">{{ $errors->first('edition') }}</x-alert>
    @endif

    <form method="POST" action="{{ route('course-talks.editions.update', $edition) }}" data-testid="course-talks-edition-edit-form">
        @csrf
        @method('PUT')

        <div class="card">
            <div class="card-header">
                <h3 class="card-title mb-0">Editar dictado de {{ $activity->name }}</h3>
            </div>
            <div class="card-body">
                <x-alert type="info">El estado del dictado se cambia con los botones de la página del dictado, no desde este formulario.</x-alert>
                @include('course-talks.editions._form')
            </div>
            <div class="card-footer d-flex gap-2">
                <button type="submit" class="btn btn-primary" data-testid="btn-update-course-edition">Guardar cambios</button>
                <a href="{{ route('course-talks.editions.show', $edition) }}" class="btn btn-outline-secondary">Cancelar</a>
            </div>
        </div>
    </form>
@endsection
