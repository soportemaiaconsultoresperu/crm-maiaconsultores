@extends('layouts.app')

@section('title', 'Editar proyecto de contenido')
@section('page-title', 'Editar proyecto de contenido')

@section('content')
    @include('content.projects._form', ['project' => $project])
@endsection
