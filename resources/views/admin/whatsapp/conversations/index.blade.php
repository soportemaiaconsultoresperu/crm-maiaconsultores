@extends('layouts.app')

@section('title', 'Bandeja de WhatsApp')
@section('page-title', 'Bandeja de WhatsApp')

@section('content')
    <p class="text-muted">
        Bandeja B14 — conversaciones sincronizadas con Meta WhatsApp Cloud API.
        El listado respeta <code>DataScopeService</code>: cada vendedor
        autenticado solo ve las conversaciones asignadas a sí mismo (o al
        equipo del supervisor) más las conversaciones sin asignar (decisión
        14b). La asignación, el cierre y el opt-out se controlan desde la
        ficha de cada conversación.
    </p>

    @if (session('success'))
        <x-alert type="success" :message="session('success')" />
    @endif
    @if (session('error'))
        <x-alert type="danger" :message="session('error')" />
    @endif

    <livewire:admin.whatsapp.conversation-list />
@endsection