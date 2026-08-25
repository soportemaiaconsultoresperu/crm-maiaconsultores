@extends('layouts.app')

@section('title', 'Editar regla ' . $rule->name)
@section('page-title', 'Editar regla')

@section('content')
    <div class="automation-page-intro mb-3">
        <p class="text-uppercase text-secondary small mb-1">Ajustar automatización</p>
        <h2 class="h5 mb-1">Estás editando “{{ $rule->name }}”</h2>
        <p class="text-muted small mb-0">
            Revisá la receta de negocio: <strong>cuándo</strong> empieza, <strong>si</strong> cumple las condiciones y <strong>entonces</strong> qué acciones ejecuta el CRM.
            Si vas a cambiar el comportamiento real, probala antes con <strong>Prueba segura</strong>.
        </p>
    </div>

    <x-validation-error name="general" />

    <livewire:admin.automations.rule-form :ruleId="$rule->id" :mode="'edit'" />
@endsection
