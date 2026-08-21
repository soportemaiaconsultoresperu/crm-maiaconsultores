@extends('layouts.app')

@section('title', 'Editar oportunidad '.$opportunity->code)
@section('page-title', 'Editar '.$opportunity->code)

@section('content')
    @include('opportunities._form', ['opportunity' => $opportunity])
@endsection
