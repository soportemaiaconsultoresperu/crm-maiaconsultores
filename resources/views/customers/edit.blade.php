@extends('layouts.app')

@section('title', 'Editar cliente '.$customer->code)
@section('page-title', 'Editar '.$customer->code)

@section('content')
    @include('customers._form', ['customer' => $customer])
@endsection
