@extends('layouts.app')

@section('title', 'Nuevo curso o charla')
@section('page-title', 'Nuevo curso o charla')

@section('content')
    <a href="{{ route('course-talks.activities.index') }}" class="btn btn-outline-secondary mb-3">Volver al catálogo</a>

    <form method="POST" action="{{ route('course-talks.activities.store') }}" data-testid="course-talks-activity-create-form" data-course-activity-form>
        @csrf

        <div class="card">
            <div class="card-header">
                <h3 class="card-title mb-0">Nuevo curso o charla</h3>
            </div>
            <div class="card-body">
                @include('course-talks.activities._form', ['activity' => null, 'typeLocked' => false])
            </div>
            <div class="card-footer d-flex gap-2">
                <button type="submit" class="btn btn-primary" data-testid="btn-save-course-activity">Crear curso o charla</button>
                <a href="{{ route('course-talks.activities.index') }}" class="btn btn-outline-secondary">Cancelar</a>
            </div>
        </div>
    </form>
@endsection
