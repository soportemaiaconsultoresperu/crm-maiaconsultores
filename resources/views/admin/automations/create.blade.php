@extends('layouts.app')

@section('title', 'Nueva regla')
@section('page-title', 'Nueva regla de automatización')

@section('content')
    <p class="text-muted small">
        Define el nombre, el trigger y las condiciones + acciones de la regla.
        La validación se ejecuta en servidor; los campos marcados se verifican
        contra el catálogo canónico de <code>AutomationServiceProvider</code>.
    </p>

    <x-validation-error name="general" />

    <livewire:admin.automations.rule-form :ruleId="null" :mode="'create'" />
@endsection
