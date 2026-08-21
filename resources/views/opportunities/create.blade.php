@extends('layouts.app')

@section('title', 'Nueva oportunidad')
@section('page-title', 'Nueva oportunidad')

@section('content')
    @include('opportunities._form', ['opportunity' => null])
@endsection
