@extends('layouts.app')

@section('title', 'Nuevo dictado')
@section('page-title', 'Nuevo dictado')

@section('content')
    <a href="{{ route('course-talks.activities.show', $activity) }}" class="btn btn-outline-secondary mb-3">Volver a {{ $activity->name }}</a>

    {{-- Field-less InvalidCourseEditionData failures are reported on the generic
         `edition` error key, which no form field renders. Without this block
         they would be invisible to the user. --}}
    @if ($errors->has('edition'))
        <x-alert type="error" data-testid="course-talks-edition-error">{{ $errors->first('edition') }}</x-alert>
    @endif

    <form method="POST" action="{{ route('course-talks.editions.store', $activity) }}" data-testid="course-talks-edition-create-form">
        @csrf

        <div class="card">
            <div class="card-header">
                <h3 class="card-title mb-0">Nuevo dictado de {{ $activity->name }}</h3>
            </div>
            <div class="card-body">
                @include('course-talks.editions._form', ['edition' => null])
            </div>
            <div class="card-footer d-flex gap-2">
                <button type="submit" class="btn btn-primary" data-testid="btn-save-course-edition">Crear dictado</button>
                <a href="{{ route('course-talks.activities.show', $activity) }}" class="btn btn-outline-secondary">Cancelar</a>
            </div>
        </div>
    </form>
@endsection
