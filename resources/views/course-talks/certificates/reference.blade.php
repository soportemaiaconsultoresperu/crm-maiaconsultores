<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 32px 42px; }
        body { color: #1f2933; font-family: DejaVu Sans, sans-serif; font-size: 13px; }
        .page { min-height: 690px; position: relative; }
        .cover { text-align: center; }
        .brand { color: #0f766e; font-size: 18px; font-weight: bold; letter-spacing: 1px; }
        h1 { color: #0f172a; font-size: 30px; margin: 52px 0 28px; text-transform: uppercase; }
        .intro { font-size: 15px; margin-bottom: 12px; }
        .participant { border-bottom: 1px solid #0f172a; display: inline-block; font-size: 25px; font-weight: bold; padding: 8px 42px; }
        .activity { font-size: 18px; font-weight: bold; margin: 26px auto 20px; max-width: 620px; }
        .facts { margin: 0 auto 22px; width: 82%; }
        .facts td { padding: 6px 10px; }
        .facts .label { color: #475569; font-weight: bold; text-align: right; width: 42%; }
        .issue { margin: 24px 0; }
        .qr { bottom: 78px; position: absolute; right: 0; width: 105px; }
        .code { bottom: 44px; color: #475569; font-size: 11px; position: absolute; right: 0; }
        .signatures { bottom: 32px; left: 0; position: absolute; width: 78%; }
        .signature { display: inline-block; margin: 0 24px; text-align: center; width: 220px; }
        .line { border-top: 1px solid #111827; margin-bottom: 6px; }
        .page-break { page-break-after: always; }
        h2 { color: #0f766e; font-size: 22px; margin: 0 0 18px; }
        table.syllabus { border-collapse: collapse; width: 100%; }
        .syllabus th { background: #e0f2f1; color: #0f172a; }
        .syllabus th, .syllabus td { border: 1px solid #cbd5e1; padding: 8px; text-align: left; }
        .footer { bottom: 0; color: #64748b; font-size: 10px; position: absolute; text-align: center; width: 100%; }
    </style>
</head>
<body>
    <section class="page cover page-break">
        <div class="brand">{{ $certificate->company }}</div>
        <h1>{{ $certificate->title }}</h1>
        <div class="intro">{{ $certificate->introText }}</div>
        <div class="participant">{{ $certificate->participant }}</div>
        <div class="activity">{{ $certificate->activity }}</div>
        <table class="facts">
            <tr><td class="label">Modalidad</td><td>{{ $certificate->modality }}</td></tr>
            <tr><td class="label">Fechas</td><td>{{ $certificate->dateRange }}</td></tr>
            <tr><td class="label">Duración</td><td>{{ $certificate->academicHours }} horas académicas</td></tr>
        </table>
        <div class="issue">{{ $certificate->issueLocationDate }}</div>
        <div class="signatures">
            @foreach ($certificate->signatures as $signature)
                <div class="signature">
                    <div class="line"></div>
                    <strong>{{ $signature['name'] }}</strong><br>
                    <span>{{ $signature['role'] }}</span>
                </div>
            @endforeach
        </div>
        <div class="qr">{!! $certificate->qrSvg !!}</div>
        <div class="code">Código: {{ $certificate->certificateCode }}</div>
    </section>

    <section class="page">
        <h2>Temario</h2>
        <table class="syllabus">
            <thead>
                <tr><th>Clase</th><th>Tema</th><th>Expositor</th><th>Fecha</th></tr>
            </thead>
            <tbody>
                @foreach ($certificate->syllabus as $item)
                    <tr>
                        <td>{{ $item['class'] }}</td>
                        <td>{{ $item['topic'] }}</td>
                        <td>{{ $item['speaker'] }}</td>
                        <td>{{ $item['date'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="footer">{{ $certificate->company }} · Código {{ $certificate->certificateCode }}</div>
    </section>
</body>
</html>
