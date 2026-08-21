@extends('layouts.app')

@section('title', 'Editar plantilla ' . $template->name)
@section('page-title', 'Editar plantilla de correo')

@section('content')
    <p class="text-muted small">
        Editando la plantilla <strong>{{ $template->name }}</strong>. Cada vez
        que el cuerpo cambia, se persiste una nueva fila en
        <code>email_template_versions</code> con el snapshot completo.
    </p>

    <x-validation-error name="general" />

    <livewire:admin.email.template-form :templateId="$template->id" :mode="'edit'" />
@endsection
