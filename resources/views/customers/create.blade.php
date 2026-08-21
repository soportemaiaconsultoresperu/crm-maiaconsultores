@extends('layouts.app')

@section('title', 'Nuevo cliente')
@section('page-title', 'Nuevo cliente')

@section('content')
    @include('customers._form', ['customer' => null])
@endsection
