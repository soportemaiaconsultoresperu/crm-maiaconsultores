@extends('layouts.app')

@section('title', 'Nueva cotización')
@section('page-title', 'Nueva cotización')

@section('content')
    @include('quotations._form', [
        'quotation' => null,
        'prefill' => $prefill ?? [],
        'items' => $items ?? [],
        'leads' => $leads,
        'customers' => $customers,
        'contacts' => $contacts,
        'opportunities' => $opportunities,
        'currencies' => $currencies,
        'products' => $products,
        'taxes' => $taxes,
        'owners' => $owners,
    ])
@endsection