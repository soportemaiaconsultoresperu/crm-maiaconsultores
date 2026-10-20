<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    @php
        $poppinsRegular = 'file:///'.str_replace('\\', '/', storage_path('fonts/Poppins-Regular.ttf'));
        $poppinsSemiBold = 'file:///'.str_replace('\\', '/', storage_path('fonts/Poppins-SemiBold.ttf'));
        $poppinsBold = 'file:///'.str_replace('\\', '/', storage_path('fonts/Poppins-Bold.ttf'));
    @endphp
    <style>
        /* Poppins travels with the certificate: dompdf does not read system fonts, it needs the
           files. They live in storage/fonts and are registered here so the PDF can embed them. */
        @font-face { font-family: 'Poppins'; font-style: normal; font-weight: normal; src: url('{{ $poppinsRegular }}'); }
        @font-face { font-family: 'Poppins'; font-style: normal; font-weight: bold; src: url('{{ $poppinsSemiBold }}'); }
        @font-face { font-family: 'Poppins Black'; font-style: normal; font-weight: normal; src: url('{{ $poppinsBold }}'); }

        @page { margin: 0; size: A4 landscape; }
        * { box-sizing: border-box; }
        body { background: #ffffff; color: #102647; font-family: 'Poppins', 'DejaVu Sans', sans-serif; font-size: 10pt; margin: 0; }
        .page { height: 595.28pt; overflow: hidden; position: relative; width: 841.89pt; }
        .certificate-page { background: #d7d9ff; page-break-after: always; }
        .syllabus-page { background: #dcefff; }
        .inner-card { background: #fbfdff; border-radius: 26pt; height: 531.28pt; left: 39pt; position: absolute; top: 32pt; width: 763.89pt; }
        .logo { display: block; position: absolute; right: 42pt; top: 31pt; width: 92pt; }

        .certificate-content { left: 72pt; position: absolute; right: 72pt; text-align: center; top: 76pt; }
        .doctype { color: #137fc3; font-family: 'Poppins Black', 'DejaVu Sans', sans-serif; font-size: 31pt; letter-spacing: 1.6pt; line-height: 1; margin: 0 0 19pt; text-transform: uppercase; }
        .lead { color: #1a2e4f; font-size: 10.5pt; letter-spacing: .15pt; margin: 0 0 17pt; }
        .participant { color: #1a2e4f; display: inline-block; font-family: 'Poppins Black', 'DejaVu Sans', sans-serif; font-size: 20pt; letter-spacing: .6pt; line-height: 1.14; margin: 0 auto 14pt; min-width: 420pt; text-transform: uppercase; }
        .participant .name { display: block; padding: 0 18pt 8pt; }
        .participant .rule { border-top: 1.1pt solid #65758e; display: block; height: 1pt; width: 100%; }
        .participation-label { color: #1a2e4f; font-size: 10.5pt; margin: 0 0 8pt; }
        .activity { color: #137fc3; font-family: 'Poppins Black', 'DejaVu Sans', sans-serif; font-size: 18pt; letter-spacing: .45pt; line-height: 1.2; margin: 0 auto 19pt; max-width: 590pt; text-transform: uppercase; }
        .description { color: #1a2e4f; font-size: 10.2pt; line-height: 1.65; margin: 0 auto; max-width: 605pt; text-align: center; }
        .issue { color: #1a2e4f; font-size: 9.7pt; margin: 24pt 0 0; text-align: right; }
        .page-one-qr { bottom: 37pt; left: 51pt; position: absolute; width: 72pt; }
        .qr-frame { background: #ffffff; border: 1.6pt solid #102647; border-radius: 4pt; height: 72pt; padding: 5pt; width: 72pt; }
        .qr-image { display: block; height: 60pt; width: 60pt; }
        .certificate-footer { bottom: 38pt; left: 132pt; position: absolute; right: 132pt; }
        .signatures { text-align: center; width: 100%; }
        .signature { display: inline-block; vertical-align: bottom; width: 195pt; }
        .signature:first-child { margin-right: 96pt; }
        .signature .rule { border-top: .85pt solid #102647; margin: 0 0 8pt; }
        .signature .name { color: #102647; font-size: 9pt; font-weight: bold; letter-spacing: .15pt; text-transform: uppercase; }
        .signature .role { color: #102647; font-size: 8.2pt; text-transform: uppercase; }
        .verification { bottom: 18pt; color: #55708f; font-size: 7.4pt; position: absolute; right: 50pt; text-align: right; }

        .syllabus-content { left: 60pt; position: absolute; right: 60pt; top: 58pt; }
        .syllabus-title { color: #137fc3; font-family: 'Poppins Black', 'DejaVu Sans', sans-serif; font-size: 24pt; letter-spacing: 1.1pt; line-height: 1; margin: 0 0 20pt; text-align: left; text-transform: uppercase; }
        .syllabus-statement { color: #1a2e4f; font-size: 10.2pt; line-height: 1.55; margin: 0 110pt 18pt 0; text-align: left; }
        .topic-heading { color: #137fc3; font-family: 'Poppins Black', 'DejaVu Sans', sans-serif; font-size: 13pt; letter-spacing: .55pt; line-height: 1; margin: 0 0 10pt; text-align: left; text-transform: uppercase; }
        .table-wrap { border: 1pt solid #8ab8d8; border-radius: 13pt; overflow: hidden; }
        table.syllabus { border-collapse: collapse; table-layout: fixed; width: 100%; }
        .syllabus th { background: #d9efff; border-bottom: .85pt solid #8ab8d8; border-right: .85pt solid #8ab8d8; color: #102647; font-size: 9.2pt; font-weight: bold; padding: 8pt 10pt; text-align: left; }
        .syllabus th:last-child { border-right: 0; }
        .syllabus td { background: #ffffff; border-bottom: .75pt solid #b3cee2; border-right: .75pt solid #b3cee2; color: #102647; font-size: 8.5pt; line-height: 1.35; padding: 8pt 10pt; text-align: left; vertical-align: top; }
        .syllabus td:last-child { border-right: 0; }
        .syllabus tbody tr:last-child td { border-bottom: 0; }
        .syllabus .clase { width: 16%; }
        .syllabus .speaker { width: 27%; }
        .syllabus .date { width: 16%; }
        .syllabus-code { bottom: 31pt; color: #55708f; font-size: 7.6pt; letter-spacing: .25pt; position: absolute; right: 50pt; text-align: right; }
    </style>
</head>
<body>
    @php
        $logoPath = public_path('images/logo-maia.png');
        $logoPayload = is_file($logoPath) ? 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath)) : '';
        $qrPayload = $certificate->qrSvg !== '' ? 'data:image/svg+xml;base64,'.base64_encode($certificate->qrSvg) : '';
        $modality = ucfirst($certificate->modality);
        $certificateTitle = trim((string) $certificate->title);
        $visibleCertificateTitle = mb_strtolower($certificateTitle, 'UTF-8') === mb_strtolower('Certificado de aprobación', 'UTF-8')
            ? 'CERTIFICADO'
            : $certificateTitle;
    @endphp

    <section class="page certificate-page">
        <div class="inner-card">
            @if ($logoPayload !== '')
                <img class="logo" src="{{ $logoPayload }}" alt="{{ $certificate->company }}">
            @endif

            <div class="certificate-content">
                <h1 class="doctype" data-template-title="{{ $certificate->title }}">{{ $visibleCertificateTitle }}</h1>
                {{-- The intro is DATABASE data, not design copy: a template can replace it, so it is
                     rendered from the certificate instead of being hardcoded into this view. --}}
                <p class="lead">{{ $certificate->introText }}</p>
                <div class="participant"><span class="name">{{ $certificate->participant }}</span><span class="rule"></span></div>
                <p class="participation-label">Por haber participado en el:</p>
                <div class="activity">{{ $certificate->activity }}</div>
                <p class="description">
                    Organizado por {{ $certificate->company }}, desarrollado en modalidad {{ $modality }},
                    realizado del {{ $certificate->dateRange }}, con una duración de {{ $certificate->academicHours }} horas académicas.
                </p>
                <p class="issue">{{ $certificate->issueLocationDate }}</p>
            </div>

            @if ($qrPayload !== '')
                <div class="page-one-qr" aria-label="Código QR de verificación">
                    <div class="qr-frame"><img class="qr-image" src="{{ $qrPayload }}" alt="Código QR de verificación"></div>
                </div>
            @endif

            <div class="certificate-footer">
                <div class="signatures">
                    @foreach ($certificate->signatures as $signature)
                        <div class="signature">
                            <div class="rule"></div>
                            <div class="name">{{ $signature['name'] }}</div>
                            <div class="role">{{ $signature['role'] }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="verification">Código: {{ $certificate->certificateCode }}</div>
        </div>
    </section>

    <section class="page syllabus-page">
        <div class="inner-card">
            @if ($logoPayload !== '')
                <img class="logo" src="{{ $logoPayload }}" alt="{{ $certificate->company }}">
            @endif

            <div class="syllabus-content">
                <h2 class="syllabus-title">CERTIFICADO</h2>
                <p class="syllabus-statement">
                    Se deja constancia de que <strong>{{ $certificate->participant }}</strong> participó en
                    <strong>{{ $certificate->activity }}</strong>, realizado del {{ $certificate->dateRange }}
                    en modalidad {{ $modality }}, con una duración de {{ $certificate->academicHours }} horas académicas.
                </p>
                <h3 class="topic-heading">Temario</h3>
                <div class="table-wrap">
                    <table class="syllabus">
                        <thead>
                            <tr><th class="clase">Clase</th><th>Tema</th><th class="speaker">Ponente</th><th class="date">Fecha</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($certificate->syllabus as $item)
                                <tr>
                                    <td class="clase">{{ $item['class'] }}</td>
                                    <td>{{ $item['topic'] }}</td>
                                    <td>{{ $item['speaker'] }}</td>
                                    <td>{{ $item['date'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="syllabus-code">Código: {{ $certificate->certificateCode }}</div>
        </div>
    </section>
</body>
</html>
