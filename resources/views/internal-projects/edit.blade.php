@extends('layouts.app')

@section('title', 'Editar '.$project->name)
@section('page-title', 'Editar proyecto interno')

@section('content')
    @include('internal-projects._form')
@endsection
