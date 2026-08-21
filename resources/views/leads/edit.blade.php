@extends('layouts.app')

@section('title', 'Editar prospecto')
@section('page-title', 'Editar '.$lead->code)

@section('content')
    @include('leads._form', ['lead' => $lead])
@endsection
