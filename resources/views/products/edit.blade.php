@extends('layouts.app')

@section('title', 'Editar producto '.$product->code)
@section('page-title', 'Editar '.$product->code)

@section('content')
    @include('products._form', ['product' => $product])
@endsection