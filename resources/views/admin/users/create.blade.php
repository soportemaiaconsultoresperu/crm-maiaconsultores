@extends('layouts.app')

@section('title', 'Nuevo usuario')
@section('page-title', 'Nuevo usuario')

@section('content')
    <form method="POST" action="{{ route('admin.users.store') }}" class="card">
        @csrf
        <div class="card-body">
            @include('admin.users._form', [
                'user' => $user,
                'roles' => $roles,
                'passwordRequired' => true,
            ])
        </div>
        <div class="card-footer d-flex justify-content-end gap-2">
            <a href="{{ route('admin.users.index') }}" class="btn btn-outline-secondary">Cancelar</a>
            <button type="submit" class="btn btn-primary">Crear usuario</button>
        </div>
    </form>
@endsection