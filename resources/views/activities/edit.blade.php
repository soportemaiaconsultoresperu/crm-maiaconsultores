@extends('layouts.app')

@section('title', 'Editar actividad')
@section('page-title', 'Editar actividad')

@section('content')
    @include('activities._form', ['activity' => $activity])
@endsection
