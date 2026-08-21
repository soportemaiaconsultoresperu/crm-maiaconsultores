@extends('layouts.app')

@section('title', 'Nuevo prospecto')
@section('page-title', 'Nuevo prospecto')

@section('content')
    @include('leads._form', ['lead' => null])
@endsection
