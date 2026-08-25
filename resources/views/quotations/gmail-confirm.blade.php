@extends('layouts.app')

@section('title', 'Enviar cotización por Gmail')
@section('page-title', 'Enviar '.$quotation->number.' por Gmail')

@section('content')
    <div class="card" data-testid="gmail-confirm-card">
        <div class="card-header">
            <h3 class="card-title mb-0">Confirmar envío por Gmail</h3>
        </div>
        <form method="POST" action="{{ route('quotations.gmail-send', $quotation) }}" data-swal-loading>
            @csrf
            <div class="card-body">
                <div class="alert alert-info">
                    Revisá los datos antes de confirmar. Gmail confirmará aceptación para envío, no entrega ni lectura.
                </div>

                @if($recipient['ambiguous'])
                    <div class="alert alert-warning" data-testid="recipient-ambiguous">
                        Hay más de un correo posible. Indicá el destinatario manualmente.
                    </div>
                @endif

                <div class="mb-3">
                    <label class="form-label">Desde</label>
                    <input class="form-control" value="{{ $from }}" disabled data-testid="gmail-from">
                </div>
                <div class="mb-3">
                    <label for="to" class="form-label">Para <span class="text-danger">*</span></label>
                    <input id="to" name="to" type="email" required class="form-control @error('to') is-invalid @enderror" value="{{ old('to', $recipient['email']) }}" data-testid="gmail-to">
                    @error('to')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="cc" class="form-label">CC</label>
                        <input id="cc" name="cc" class="form-control @error('cc') is-invalid @enderror" value="{{ old('cc') }}" placeholder="correo1@dominio.com, correo2@dominio.com" data-testid="gmail-cc">
                        @error('cc')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label for="bcc" class="form-label">CCO</label>
                        <input id="bcc" name="bcc" class="form-control @error('bcc') is-invalid @enderror" value="{{ old('bcc') }}" placeholder="correo@dominio.com" data-testid="gmail-bcc">
                        @error('bcc')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="mb-3 mt-3">
                    <label for="subject" class="form-label">Asunto <span class="text-danger">*</span></label>
                    <input id="subject" name="subject" required class="form-control @error('subject') is-invalid @enderror" value="{{ old('subject', $subject) }}" data-testid="gmail-subject">
                    @error('subject')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label for="body" class="form-label">Mensaje <span class="text-danger">*</span></label>
                    <textarea id="body" name="body" required rows="8" class="form-control @error('body') is-invalid @enderror" data-testid="gmail-body">{{ old('body', $body) }}</textarea>
                    @error('body')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-0">
                    <label class="form-label">Adjunto</label>
                    <input class="form-control" value="{{ $filename }}" disabled data-testid="gmail-attachment">
                </div>
            </div>
            <div class="card-footer d-flex gap-2">
                <button class="btn btn-primary" data-swal-confirm data-swal-title="Enviar cotización por Gmail" data-swal-text="Se encolará el envío con el PDF adjunto." data-swal-type="question" data-testid="btn-confirm-gmail-send">
                    Confirmar envío
                </button>
                <a href="{{ route('quotations.show', $quotation) }}" class="btn btn-outline-secondary">Cancelar</a>
            </div>
        </form>
    </div>
@endsection
