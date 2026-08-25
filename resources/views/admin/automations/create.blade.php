@extends('layouts.app')

@section('title', 'Nueva regla')
@section('page-title', 'Nueva regla de automatización')

@section('content')
    <div class="automation-page-intro mb-3">
        <p class="text-uppercase text-secondary small mb-1">Nueva automatización</p>
        <h2 class="h5 mb-1">Creá una regla con la receta CUANDO / SI / ENTONCES</h2>
        <p class="text-muted small mb-0">
            Primero elegí qué evento la inicia, después cuándo debe aplicar y por último qué hará el CRM.
            Recomendación: guardala en modo <strong>Prueba segura</strong> antes de activarla para el equipo.
        </p>
    </div>

    <x-validation-error name="general" />

    <livewire:admin.automations.rule-form :ruleId="null" :mode="'create'" />
@endsection
