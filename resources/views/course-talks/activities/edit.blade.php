@extends('layouts.app')

@section('title', 'Editar '.$activity->name)
@section('page-title', 'Editar '.$activity->name)

@section('content')
    <a href="{{ route('course-talks.activities.show', $activity) }}" class="btn btn-outline-secondary mb-3">Volver a {{ $activity->name }}</a>

    <form method="POST" action="{{ route('course-talks.activities.update', $activity) }}" data-testid="course-talks-activity-edit-form" data-course-activity-form>
        @csrf
        @method('PUT')

        <div class="card">
            <div class="card-header">
                <h3 class="card-title mb-0">Editar curso o charla</h3>
            </div>
            <div class="card-body">
                @include('course-talks.activities._form')
            </div>
            <div class="card-footer d-flex gap-2">
                <button type="submit" class="btn btn-primary" data-testid="btn-update-course-activity">Guardar cambios</button>
                <a href="{{ route('course-talks.activities.show', $activity) }}" class="btn btn-outline-secondary">Cancelar</a>
            </div>
        </div>
    </form>
@endsection
