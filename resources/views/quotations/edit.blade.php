@extends('layouts.app')

@section('title', 'Editar cotización '.$quotation->number)
@section('page-title', 'Editar '.$quotation->number)

@section('content')
    @include('quotations._form', [
        'quotation' => $quotation,
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