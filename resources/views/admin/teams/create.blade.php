@extends('layouts.app')

@section('title', 'Nuevo equipo')
@section('page-title', 'Nuevo equipo')

@section('content')
    <form method="POST" action="{{ route('admin.teams.store') }}" class="card">
        @csrf
        <div class="card-body">
            @include('admin.teams._form', [
                'team' => $team,
                'supervisors' => $supervisors,
            ])

            <hr>

            <h4 class="h6">Miembros iniciales</h4>
            <p class="text-secondary small">Marca a los vendedores que pertenecerán al equipo desde el inicio.</p>
            <select name="members[]" multiple class="form-select" size="6" aria-label="Miembros del equipo">
                @foreach ($memberCandidates as $candidate)
                    <option value="{{ $candidate->id }}">{{ $candidate->name }} — {{ $candidate->email }}</option>
                @endforeach
            </select>
        </div>
        <div class="card-footer d-flex justify-content-end gap-2">
            <a href="{{ route('admin.teams.index') }}" class="btn btn-outline-secondary">Cancelar</a>
            <button type="submit" class="btn btn-primary">Crear equipo</button>
        </div>
    </form>
@endsection