@extends('layouts.app')

@section('title', 'Editar rol')
@section('page-title', 'Editar rol — '.$role->name)

@section('content')
    <form method="POST" action="{{ route('admin.roles.update', $role) }}" class="card">
        @csrf
        @method('PUT')
        <div class="card-body">
            <x-text-input name="name" label="Nombre del rol" :value="$role->name" required/>

            <h4 class="h6 mt-4">Permisos del rol</h4>
            <div class="row">
                @foreach ($permissions as $group)
                    <div class="col-md-6 mb-3">
                        <div class="border rounded p-2">
                            <div class="fw-medium mb-2">{{ ucfirst($group['module']) }}</div>
                            @foreach ($group['items'] as $perm)
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox"
                                           name="permissions[]" value="{{ $perm['name'] }}"
                                           id="perm-{{ md5($perm['name']) }}"
                                           @checked(in_array($perm['name'], old('permissions', $selectedPermissions), true))>
                                    <label class="form-check-label small" for="perm-{{ md5($perm['name']) }}">{{ $perm['label'] }}</label>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
        <div class="card-footer d-flex justify-content-end gap-2">
            <a href="{{ route('admin.roles.index') }}" class="btn btn-outline-secondary">Volver</a>
            <button type="submit" class="btn btn-primary">Guardar cambios</button>
        </div>
    </form>
@endsection