@extends('layouts.app')

@section('title', 'Nueva plantilla de correo')
@section('page-title', 'Nueva plantilla de correo')

@section('content')
    <p class="text-muted small">
        Define el nombre, el slug, el subject y los cuerpos HTML / text de la
        plantilla. La lista de variables debe estar en <code>snake_case</code>
        y se evalúa contra una lista permitida — no se permite Blade, PHP ni
        expresiones embebidas (decisión 11c).
    </p>

    <x-validation-error name="general" />

    <livewire:admin.email.template-form :templateId="null" :mode="'create'" />
@endsection
