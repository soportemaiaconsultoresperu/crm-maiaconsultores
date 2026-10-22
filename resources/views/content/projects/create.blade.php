@extends('layouts.app')

@section('title', 'Nuevo proyecto de contenido')
@section('page-title', 'Nuevo proyecto de contenido')

@section('content')
    @include('content.projects._form', ['project' => null])
@endsection
