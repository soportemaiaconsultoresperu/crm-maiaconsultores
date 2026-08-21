{{--
    Quotation PDF (RF-COT-005). Clean professional layout, mono-column,
    Spanish d/m/Y dates. Items display the historical tax snapshot
    (tax_name + tax_rate) — never the current catalog value (ADR-005).
--}}
@php
    $q = $quotation;
    $subject = $q->customer
        ? $q->customer->legal_name
        : trim(($q->lead?->first_name.' '.($q->lead?->last_name ?? '')).($q->lead?->company_name ? ' — '.$q->lead->company_name : ''));
    $docLine = trim(($q->customer?->doc_type ? strtoupper($q->customer->doc_type).' ' : '').($q->customer?->doc_number ?? ''));
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $q->number }}</title>
    <style>
        @page { margin: 28px 28px 60px 28px; }
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, Helvetica, Arial, sans-serif;
            font-size: 11px;
            color: #1f2d3d;
            line-height: 1.4;
        }
        .header {
            text-align: center;
            margin-bottom: 18px;
            padding-bottom: 8px;
            border-bottom: 2px solid #1f2d3d;
        }
        .header h1 {
            font-size: 18px;
            margin: 0 0 4px 0;
            letter-spacing: 1px;
        }
        .header .meta {
            font-size: 10px;
            color: #555;
        }
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 10px;
            color: #fff;
            background: #0d6efd;
            vertical-align: middle;
        }
        .badge.borrador { background: #6c757d; }
        .badge.enviada { background: #0dcaf0; color: #000; }
        .badge.aceptada { background: #198754; }
        .badge.rechazada { background: #dc3545; }
        .badge.anulada { background: #6c757d; }
        .badge.vencida { background: #ffc107; color: #000; }

        .row { width: 100%; }
        .row::after { content: ''; display: block; clear: both; }
        .col-half { width: 49%; float: left; }
        .col-half + .col-half { margin-left: 2%; }

        .block {
            border: 1px solid #d6dde6;
            border-radius: 4px;
            padding: 8px 10px;
            margin-bottom: 12px;
        }
        .block h3 {
            font-size: 11px;
            margin: 0 0 4px 0;
            text-transform: uppercase;
            color: #555;
            letter-spacing: 0.6px;
        }
        .block p { margin: 0; }
        .block .small { font-size: 10px; color: #555; }

        table.items {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
        }
        table.items th, table.items td {
            border-bottom: 1px solid #e2e6ee;
            padding: 6px 4px;
            font-size: 10px;
            vertical-align: top;
        }
        table.items thead th {
            background: #f1f3f7;
            text-align: left;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        table.items td.num, table.items th.num { text-align: right; }
        table.items td.center, table.items th.center { text-align: center; }
        table.items .desc {
            font-weight: 600;
        }

        .totals {
            width: 320px;
            margin-left: auto;
            border: 1px solid #d6dde6;
            border-radius: 4px;
            padding: 8px 10px;
        }
        .totals .line {
            display: flex;
            justify-content: space-between;
            padding: 2px 0;
            font-size: 11px;
        }
        .totals .line.grand {
            border-top: 1px solid #1f2d3d;
            margin-top: 4px;
            padding-top: 6px;
            font-size: 13px;
            font-weight: 700;
        }

        .footer {
            position: fixed;
            left: 28px;
            right: 28px;
            bottom: 24px;
            font-size: 9px;
            color: #777;
            text-align: center;
            border-top: 1px solid #d6dde6;
            padding-top: 6px;
        }

        .notes {
            margin-top: 18px;
            font-size: 10px;
            color: #555;
        }
        .notes p { margin: 0 0 4px 0; }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $q->number }}</h1>
        <div class="meta">
            <span class="badge {{ $q->status }}">
                {{ match ($q->status) {
                    'draft' => 'Borrador',
                    'sent' => 'Enviada',
                    'accepted' => 'Aceptada',
                    'rejected' => 'Rechazada',
                    'expired' => 'Vencida',
                    'voided' => 'Anulada',
                    default => ucfirst($q->status),
                } }}
            </span>
            &nbsp;&nbsp;Emisión: {{ $q->issued_at?->format('d/m/Y') }}
            @if ($q->expires_at)
                &nbsp;·&nbsp;Válida hasta: {{ $q->expires_at->format('d/m/Y') }}
            @endif
        </div>
    </div>

    <div class="row">
        <div class="col-half">
            <div class="block">
                <h3>{{ $q->customer ? 'Cliente' : 'Prospecto' }}</h3>
                <p>{{ $subject }}</p>
                @if ($docLine !== '')
                    <p class="small">{{ $docLine }}</p>
                @endif
                @if ($q->customer?->fiscal_address)
                    <p class="small">{{ $q->customer->fiscal_address }}</p>
                @endif
            </div>
        </div>
        <div class="col-half">
            <div class="block">
                <h3>Responsable</h3>
                <p>{{ $q->owner?->name ?? '—' }}</p>
                @if ($q->opportunity)
                    <p class="small">Oportunidad: {{ $q->opportunity->code }} — {{ $q->opportunity->title }}</p>
                @endif
                @if ($q->contact)
                    <p class="small">Contacto: {{ trim($q->contact->first_name.' '.$q->contact->last_name) }}</p>
                @endif
            </div>
        </div>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th class="center" style="width: 24px;">#</th>
                <th>Descripción</th>
                <th style="width: 50px;">Unidad</th>
                <th class="num" style="width: 60px;">Cant.</th>
                <th class="num" style="width: 80px;">P. unitario</th>
                <th class="num" style="width: 60px;">Desc.</th>
                <th class="num" style="width: 80px;">Impuesto</th>
                <th class="num" style="width: 90px;">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($q->items as $item)
                <tr>
                    <td class="center">{{ $loop->iteration }}</td>
                    <td>
                        <span class="desc">{{ $item->description }}</span>
                        @if ($item->product)
                            <div class="small" style="color: #777;">{{ $item->product->code }} — {{ $item->product->name }}</div>
                        @endif
                    </td>
                    <td>{{ $item->unit }}</td>
                    <td class="num">{{ number_format((float) $item->quantity, 2) }}</td>
                    <td class="num">{{ number_format((float) $item->unit_price, 2) }}</td>
                    <td class="num">{{ number_format((float) $item->discount_amount, 2) }}</td>
                    <td class="num">
                        {{ $item->tax_name ?: '—' }}
                        @if ((float) $item->tax_rate > 0)
                            <div class="small" style="color: #777;">{{ number_format((float) $item->tax_rate, 2) }}%</div>
                        @endif
                    </td>
                    <td class="num">{{ number_format((float) $item->line_total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals">
        <div class="line"><span>Subtotal</span><span>{{ $q->currency_code }} {{ number_format((float) $q->subtotal, 2) }}</span></div>
        <div class="line"><span>Descuento</span><span>{{ $q->currency_code }} {{ number_format((float) $q->discount_total, 2) }}</span></div>
        <div class="line"><span>Impuesto</span><span>{{ $q->currency_code }} {{ number_format((float) $q->tax_total, 2) }}</span></div>
        <div class="line grand"><span>Total</span><span>{{ $q->currency_code }} {{ number_format((float) $q->total, 2) }}</span></div>
    </div>

    <div class="notes">
        @if ($q->expires_at)
            <p><strong>Validez:</strong> {{ $q->expires_at->format('d/m/Y') }}.</p>
        @endif
        @if ($q->terms)
            <p><strong>Términos:</strong> {{ $q->terms }}</p>
        @endif
        @if ($q->observations)
            <p><strong>Observaciones:</strong> {{ $q->observations }}</p>
        @endif
    </div>

    <div class="footer">
        {{ config('app.name', 'CRM Maia Consultores') }} — Cotización {{ $q->number }} — Generada el {{ now()->format('d/m/Y H:i') }}
    </div>
</body>
</html>