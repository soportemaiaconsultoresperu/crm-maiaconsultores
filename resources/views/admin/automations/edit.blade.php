@extends('layouts.app')

@section('title', 'Editar regla ' . $rule->name)
@section('page-title', 'Editar regla')

@section('content')
    <p class="text-muted small">
        Editando la regla <strong>{{ $rule->name }}</strong>. Modifica sólo los
        campos que desees; los grupos, condiciones y acciones se reemplazan en
        una transacción al guardar (CRUD-03).
    </p>

    <x-validation-error name="general" />

    <livewire:admin.automations.rule-form :ruleId="$rule->id" :mode="'edit'" />
@endsection
