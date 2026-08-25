{{--
    Quotation PDF (RF-COT-005). Formal modern business layout, A4 portrait,
    Spanish d/m/Y dates. Items display the historical tax snapshot
    (tax_name + tax_rate) — never the current catalog value (ADR-005).
--}}
@php
    $q = $quotation;
    $statusLabel = match ($q->status) {
        'draft' => 'Borrador',
        'sent' => 'Enviada',
        'accepted' => 'Aceptada',
        'rejected' => 'Rechazada',
        'expired' => 'Vencida',
        'voided' => 'Anulada',
        default => ucfirst($q->status),
    };
    $statusClass = match ($q->status) {
        'accepted' => 'status-accepted',
        'rejected', 'voided' => 'status-danger',
        'expired' => 'status-warning',
        'sent' => 'status-sent',
        default => 'status-draft',
    };
    $subject = $q->customer
        ? $q->customer->legal_name
        : trim(($q->lead?->first_name.' '.($q->lead?->last_name ?? '')).($q->lead?->company_name ? ' — '.$q->lead->company_name : ''));
    $docLine = $q->customer
        ? trim(($q->customer?->doc_type ? strtoupper($q->customer->doc_type).' ' : '').($q->customer?->doc_number ?? ''))
        : trim(($q->lead?->doc_type ? strtoupper($q->lead->doc_type).' ' : '').($q->lead?->doc_number ?? ''));
    $subjectAddress = $q->customer?->fiscal_address ?: $q->lead?->address;
    $appName = config('app.name', 'CRM Maia Consultores');
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $q->number }}</title>
    <style>
        @page { margin: 26px 28px 64px 28px; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: DejaVu Sans, Helvetica, Arial, sans-serif;
            font-size: 10.5px;
            color: #172033;
            line-height: 1.42;
            background: #ffffff;
        }
        .top-band {
            height: 10px;
            background: #2563eb;
            border-radius: 8px 8px 0 0;
        }
        .document {
            border: 1px solid #dbe5f1;
            border-radius: 8px;
            overflow: hidden;
        }
        .hero {
            width: 100%;
            border-collapse: collapse;
            background: #f7faff;
            border-bottom: 1px solid #dbe5f1;
        }
        .hero td { padding: 18px 20px; vertical-align: top; }
        .brand-mark {
            display: inline-block;
            width: 30px;
            height: 30px;
            border-radius: 9px;
            background: #2563eb;
            color: #ffffff;
            text-align: center;
            line-height: 30px;
            font-weight: 700;
            margin-right: 8px;
        }
        .brand-name {
            font-size: 13px;
            font-weight: 700;
            color: #111827;
        }
        .brand-subtitle { color: #64748b; font-size: 9.5px; margin-top: 3px; }
        .quote-title {
            margin: 0;
            font-size: 24px;
            letter-spacing: 1px;
            color: #0f172a;
            text-align: right;
        }
        .quote-number {
            margin-top: 4px;
            font-size: 12px;
            font-weight: 700;
            color: #2563eb;
            text-align: right;
        }
        .status-pill {
            display: inline-block;
            margin-top: 8px;
            padding: 4px 10px;
            border-radius: 999px;
            color: #ffffff;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
        }
        .status-draft { background: #64748b; }
        .status-sent { background: #0ea5e9; }
        .status-accepted { background: #10b981; }
        .status-danger { background: #ef4444; }
        .status-warning { background: #f59e0b; color: #111827; }

        .section { padding: 16px 20px 0; }
        .meta-grid { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        .meta-grid td { width: 50%; vertical-align: top; }
        .meta-grid td + td { padding-left: 14px; }
        .panel {
            min-height: 112px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px;
            background: #ffffff;
        }
        .panel-title {
            margin: 0 0 8px;
            color: #64748b;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .7px;
        }
        .panel-main { margin: 0 0 5px; font-size: 13px; font-weight: 700; color: #111827; }
        .muted { color: #64748b; }
        .small { font-size: 9.5px; }
        .kv { width: 100%; border-collapse: collapse; margin-top: 2px; }
        .kv td { padding: 2px 0; vertical-align: top; }
        .kv .label { width: 38%; color: #64748b; }
        .kv .value { text-align: right; font-weight: 700; color: #172033; }

        table.items {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
        }
        table.items th,
        table.items td {
            padding: 8px 7px;
            border-bottom: 1px solid #e9eef6;
            vertical-align: top;
        }
        table.items thead th {
            background: #0f172a;
            color: #ffffff;
            font-size: 8.5px;
            text-transform: uppercase;
            letter-spacing: .55px;
            text-align: left;
        }
        table.items tbody tr:nth-child(even) td { background: #f8fafc; }
        table.items .num { text-align: right; white-space: nowrap; }
        table.items .center { text-align: center; }
        table.items .desc { font-weight: 700; color: #111827; }
        table.items .product { margin-top: 2px; color: #64748b; font-size: 9px; }

        .summary-wrap { width: 100%; margin-top: 14px; border-collapse: collapse; }
        .summary-wrap td { vertical-align: top; }
        .terms-box {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px;
            min-height: 116px;
            background: #ffffff;
        }
        .totals {
            width: 270px;
            margin-left: auto;
            border: 1px solid #dbe5f1;
            border-radius: 8px;
            overflow: hidden;
        }
        .totals table { width: 100%; border-collapse: collapse; }
        .totals td { padding: 7px 10px; border-bottom: 1px solid #edf2f7; }
        .totals .label { color: #64748b; }
        .totals .value { text-align: right; font-weight: 700; }
        .totals .grand td {
            background: #2563eb;
            color: #ffffff;
            border-bottom: 0;
            font-size: 13px;
            font-weight: 800;
            padding-top: 10px;
            padding-bottom: 10px;
        }
        .signature-grid { width: 100%; border-collapse: collapse; margin-top: 22px; }
        .signature-grid td { width: 50%; padding-top: 18px; vertical-align: top; }
        .signature-line { border-top: 1px solid #cbd5e1; width: 82%; padding-top: 6px; color: #64748b; }
        .footer {
            position: fixed;
            left: 28px;
            right: 28px;
            bottom: 24px;
            padding-top: 7px;
            border-top: 1px solid #dbe5f1;
            color: #64748b;
            font-size: 8.7px;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="document">
        <div class="top-band"></div>
        <table class="hero">
            <tr>
                <td style="width: 54%;">
                    <div>
                        <span class="brand-mark">M</span>
                        <span class="brand-name">{{ $appName }}</span>
                    </div>
                    <div class="brand-subtitle">Propuesta comercial formal · CRM Maia Consultores</div>
                </td>
                <td style="width: 46%; text-align: right;">
                    <h1 class="quote-title">COTIZACIÓN</h1>
                    <div class="quote-number">{{ $q->number }}</div>
                    <span class="status-pill {{ $statusClass }}">{{ $statusLabel }}</span>
                </td>
            </tr>
        </table>

        <div class="section">
            <table class="meta-grid">
                <tr>
                    <td>
                        <div class="panel">
                            <p class="panel-title">{{ $q->customer ? 'Cliente' : 'Prospecto' }}</p>
                            <p class="panel-main">{{ $subject ?: '—' }}</p>
                            @if ($docLine !== '')
                                <p class="small muted">{{ $docLine }}</p>
                            @endif
                            @if ($subjectAddress)
                                <p class="small muted">{{ $subjectAddress }}</p>
                            @endif
                            @if ($q->contact)
                                <p class="small muted">Contacto: {{ trim($q->contact->first_name.' '.$q->contact->last_name) }}</p>
                            @endif
                        </div>
                    </td>
                    <td>
                        <div class="panel">
                            <p class="panel-title">Datos de la propuesta</p>
                            <table class="kv">
                                <tr><td class="label">Emisión</td><td class="value">{{ $q->issued_at?->format('d/m/Y') ?? '—' }}</td></tr>
                                <tr><td class="label">Validez</td><td class="value">{{ $q->expires_at?->format('d/m/Y') ?? '—' }}</td></tr>
                                <tr><td class="label">Moneda</td><td class="value">{{ $q->currency_code }}</td></tr>
                                <tr><td class="label">Responsable</td><td class="value">{{ $q->owner?->name ?? '—' }}</td></tr>
                            </table>
                            @if ($q->opportunity)
                                <p class="small muted">Oportunidad: {{ $q->opportunity->code }} — {{ $q->opportunity->title }}</p>
                            @endif
                        </div>
                    </td>
                </tr>
            </table>

            <table class="items">
                <thead>
                    <tr>
                        <th class="center" style="width: 28px;">#</th>
                        <th>Descripción</th>
                        <th style="width: 52px;">Unidad</th>
                        <th class="num" style="width: 58px;">Cant.</th>
                        <th class="num" style="width: 76px;">P. unitario</th>
                        <th class="num" style="width: 62px;">Desc.</th>
                        <th class="num" style="width: 78px;">Impuesto</th>
                        <th class="num" style="width: 86px;">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($q->items as $item)
                        <tr>
                            <td class="center muted">{{ $loop->iteration }}</td>
                            <td>
                                <div class="desc">{{ $item->description }}</div>
                                @if ($item->product)
                                    <div class="product">{{ $item->product->code }} — {{ $item->product->name }}</div>
                                @endif
                            </td>
                            <td>{{ $item->unit }}</td>
                            <td class="num">{{ number_format((float) $item->quantity, 2) }}</td>
                            <td class="num">{{ number_format((float) $item->unit_price, 2) }}</td>
                            <td class="num">{{ number_format((float) $item->discount_amount, 2) }}</td>
                            <td class="num">
                                {{ $item->tax_name ?: '—' }}
                                @if ((float) $item->tax_rate > 0)
                                    <div class="small muted">{{ number_format((float) $item->tax_rate, 2) }}%</div>
                                @endif
                            </td>
                            <td class="num"><strong>{{ number_format((float) $item->line_total, 2) }}</strong></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <table class="summary-wrap">
                <tr>
                    <td style="width: 56%; padding-right: 16px;">
                        <div class="terms-box">
                            <p class="panel-title">Condiciones comerciales</p>
                            @if ($q->terms)
                                <p>{{ $q->terms }}</p>
                            @elseif ($q->expires_at)
                                <p>Oferta válida hasta el {{ $q->expires_at->format('d/m/Y') }}.</p>
                            @else
                                <p>Oferta sujeta a disponibilidad y aprobación comercial.</p>
                            @endif
                            @if ($q->observations)
                                <p style="margin-top: 8px;"><strong>Observaciones:</strong> {{ $q->observations }}</p>
                            @endif
                        </div>
                    </td>
                    <td style="width: 44%;">
                        <div class="totals">
                            <table>
                                <tr><td class="label">Subtotal</td><td class="value">{{ $q->currency_code }} {{ number_format((float) $q->subtotal, 2) }}</td></tr>
                                <tr><td class="label">Descuento</td><td class="value">{{ $q->currency_code }} {{ number_format((float) $q->discount_total, 2) }}</td></tr>
                                <tr><td class="label">Impuesto</td><td class="value">{{ $q->currency_code }} {{ number_format((float) $q->tax_total, 2) }}</td></tr>
                                <tr class="grand"><td>Total</td><td style="text-align: right;">{{ $q->currency_code }} {{ number_format((float) $q->total, 2) }}</td></tr>
                            </table>
                        </div>
                    </td>
                </tr>
            </table>

            <table class="signature-grid">
                <tr>
                    <td><div class="signature-line">Preparado por {{ $q->owner?->name ?? $appName }}</div></td>
                    <td><div class="signature-line" style="margin-left: auto; text-align: center;">Aceptación del cliente</div></td>
                </tr>
            </table>
        </div>
    </div>

    <div class="footer">
        {{ $appName }} · Cotización {{ $q->number }} · Generada el {{ now()->format('d/m/Y H:i') }} · Documento emitido desde CRM Maia
    </div>
</body>
</html>
