@extends('layouts.app')

@section('title', 'Nuevo producto')
@section('page-title', 'Nuevo producto')

@section('content')
    @include('products._form', ['product' => null])
@endsection