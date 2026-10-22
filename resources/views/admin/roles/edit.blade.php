@extends('layouts.app')

@section('title', 'Editar rol')
@section('page-title', 'Editar rol — '.ucfirst($role->name))

@section('content')
    @include('admin.roles._form', [
        'action' => route('admin.roles.update', $role),
        'method' => 'PUT',
        'submitLabel' => 'Guardar cambios',
        'cancelLabel' => 'Volver',
    ])
@endsection
