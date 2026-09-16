<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        {{-- Poppins travels with the certificate: dompdf does not read system fonts, it needs the
             files. They live in storage/fonts and are registered here so the PDF can embed them. --}}
        @font-face { font-family: 'Poppins'; font-style: normal; font-weight: normal; src: url('file:///{{ str_replace('\\', '/', storage_path('fonts/Poppins-Regular.ttf')) }}'); }
        @font-face { font-family: 'Poppins'; font-style: normal; font-weight: bold; src: url('file:///{{ str_replace('\\', '/', storage_path('fonts/Poppins-SemiBold.ttf')) }}'); }
        @font-face { font-family: 'Poppins Black'; font-style: normal; font-weight: normal; src: url('file:///{{ str_replace('\\', '/', storage_path('fonts/Poppins-Bold.ttf')) }}'); }

        {{-- The reference frame is a gradient. dompdf has no reliable gradient support and this
             project uses none anywhere, so the frame is the flat brand blue the reference fades
             to. Swapping in a generated gradient PNG later is a one-line change here. --}}
        @page { margin: 0; }
        body { background: #cfe4f7; color: #22262b; font-family: 'Poppins', 'DejaVu Sans', sans-serif; font-size: 12px; margin: 0; }
        .sheet { padding: 22px 22px 0; }
        .page { background: #f4f8fd; min-height: 748px; padding: 30px 40px 26px; position: relative; }
        .page-break { page-break-after: always; }

        .logo { position: absolute; right: 40px; top: 26px; width: 132px; }

        .cover { text-align: center; }
        .doctype { color: #1b5fa8; font-family: 'Poppins Black', 'DejaVu Sans', sans-serif; font-size: 33px; margin: 74px 0 26px; }
        .lead { font-size: 13px; margin-bottom: 10px; }
        .participant { color: #1c2024; font-family: 'Poppins Black', 'DejaVu Sans', sans-serif; font-size: 31px; line-height: 1.2; margin: 0 auto 16px; max-width: 760px; text-transform: uppercase; }
        .activity { color: #1b5fa8; font-size: 19px; font-weight: bold; margin: 0 auto 18px; max-width: 700px; }
        .about { font-size: 12px; line-height: 1.7; margin: 0 auto; max-width: 780px; }
        .issue { font-size: 12px; margin: 26px 0 0; text-align: right; }

        .signatures { bottom: 34px; left: 40px; position: absolute; text-align: center; width: 700px; }
        .signature { display: inline-block; margin: 0 18px; vertical-align: bottom; width: 250px; }
        .signature .rule { border-top: 1px solid #22262b; margin: 0 0 6px; }
        .signature .name { font-size: 11px; font-weight: bold; }
        .signature .role { color: #4a5259; font-size: 10px; }

        {{-- Discreet, per the agreed placement: it verifies the document without competing with it. --}}
        .qr { bottom: 34px; left: 40px; position: absolute; text-align: center; }
        .qr svg { height: 74px; width: 74px; }
        .code { bottom: 12px; color: #4a5259; font-size: 9px; position: absolute; right: 40px; }

        .heading { color: #22262b; font-family: 'Poppins Black', 'DejaVu Sans', sans-serif; font-size: 25px; margin: 78px 0 12px; }
        .statement { font-size: 12px; line-height: 1.75; margin: 0 0 26px; max-width: 1000px; }
        .statement strong { font-weight: bold; }
        .topic-heading { font-family: 'Poppins Black', 'DejaVu Sans', sans-serif; font-size: 22px; margin: 0 0 14px; }
        table.syllabus { border-collapse: collapse; margin: 0 auto; width: 94%; }
        .syllabus th { background: #bfdcf0; color: #22262b; font-size: 11px; font-weight: bold; padding: 9px 10px; text-align: left; }
        .syllabus td { border-bottom: 1px solid #b9c6d2; font-size: 11px; padding: 9px 10px; text-align: left; }
        .syllabus .clase { text-align: center; width: 12%; }
    </style>
</head>
<body>
    <div class="sheet">
        <section class="page cover page-break">
            <img class="logo" src="file:///{{ str_replace('\\', '/', public_path('images/logo-maia.png')) }}" alt="Maia Consultores">

            <div class="doctype">{{ $certificate->title }}</div>
            {{-- The intro is DATABASE data, not design copy: a template can replace it, so it is
                 rendered from the certificate instead of being hardcoded into this view. --}}
            <div class="lead">{{ $certificate->introText }}</div>
            <div class="participant">{{ $certificate->participant }}</div>
            <div class="lead">Por haber participado en el:</div>
            <div class="activity">{{ $certificate->activity }}</div>

            <div class="about">
                Organizado por {{ $certificate->company }}, desarrollado en modalidad
                {{ $certificate->modality }} {{ $certificate->dateRange }}, con una duración total
                de {{ $certificate->academicHours }} horas académicas.
            </div>

            <div class="issue">{{ $certificate->issueLocationDate }}</div>

            <div class="signatures">
                @foreach ($certificate->signatures as $signature)
                    <div class="signature">
                        <div class="rule"></div>
                        <div class="name">{{ $signature['name'] }}</div>
                        <div class="role">{{ $signature['role'] }}</div>
                    </div>
                @endforeach
            </div>

            <div class="qr">{!! $certificate->qrSvg !!}</div>
            <div class="code">Código: {{ $certificate->certificateCode }}</div>
        </section>

        <section class="page">
            <img class="logo" src="file:///{{ str_replace('\\', '/', public_path('images/logo-maia.png')) }}" alt="Maia Consultores">

            <div class="heading">{{ $certificate->title }}</div>

            <div class="statement">
                {{ $certificate->company }} deja constancia de que el documento expedido a
                <strong>{{ $certificate->participant }}</strong>, en razón de haber participado en el
                <strong>«{{ $certificate->activity }}»</strong>, desarrollado
                {{ $certificate->dateRange }} en modalidad {{ $certificate->modality }}, conteniendo
                los siguientes temas organizados en clases:
            </div>

            <div class="topic-heading">Temario</div>

            <table class="syllabus">
                <thead>
                    <tr><th class="clase">Clase</th><th>Tema</th><th>Expositor</th><th>Fecha</th></tr>
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

            <div class="code">Código: {{ $certificate->certificateCode }}</div>
        </section>
    </div>
</body>
</html>
