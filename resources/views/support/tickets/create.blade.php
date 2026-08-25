@extends('layouts.app')

@section('title', 'Nuevo ticket de soporte')
@section('page-title', 'Nuevo ticket de soporte')

@section('content')
    <form method="POST" action="{{ route('support.tickets.store') }}" data-swal-loading="Creando ticket...">
        @include('support.tickets._form')
    </form>
@endsection
