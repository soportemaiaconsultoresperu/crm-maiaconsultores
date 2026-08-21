@extends('layouts.app')

@section('title', 'Nueva actividad')
@section('page-title', 'Nueva actividad')

@section('content')
    @include('activities._form', ['activity' => null])
@endsection
