{{--
    Content grid PDF export (T10). A4 landscape so a wide grid stays readable:
    the heading row is repeated by Dompdf on every page, each row keeps together,
    and the applied filters and the project name are stated in the document.
    Cells already arrive neutralised by ContentCellSanitizer, so a value a user
    typed can never render as executable content.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Cronograma de {{ $project->name }}</title>
    <style>
        @page { size: A4 landscape; margin: 18px 20px 34px 20px; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: DejaVu Sans, Helvetica, Arial, sans-serif;
            font-size: 8px;
            color: #172033;
            line-height: 1.35;
        }
        .header {
            border-bottom: 2px solid #2563eb;
            padding-bottom: 8px;
            margin-bottom: 10px;
        }
        .title { margin: 0; font-size: 15px; color: #0f172a; }
        .project { margin: 2px 0 0; font-size: 11px; font-weight: 700; color: #2563eb; }
        .meta { margin-top: 5px; font-size: 8px; color: #475569; }
        .filters { margin: 0; padding: 0; list-style: none; }
        .filters li { display: inline-block; margin-right: 12px; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        thead { display: table-header-group; }
        th {
            background: #2563eb;
            color: #ffffff;
            text-align: left;
            font-size: 7.5px;
            padding: 4px 4px;
            border: 1px solid #1d4ed8;
            word-wrap: break-word;
        }
        td {
            padding: 3px 4px;
            border: 1px solid #dbe5f1;
            vertical-align: top;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        tbody tr { page-break-inside: avoid; }
        tbody tr:nth-child(even) td { background: #f7faff; }
        .empty { padding: 14px; text-align: center; color: #64748b; font-style: italic; }
    </style>
</head>
<body>
    <div class="header">
        <h1 class="title">Cronograma de contenidos</h1>
        <p class="project">{{ $project->name }}</p>
        <div class="meta">
            @if (empty($filterSummary))
                <span>Sin filtros aplicados.</span>
            @else
                <span>Filtros aplicados:</span>
                <ul class="filters">
                    @foreach ($filterSummary as $statement)
                        <li>{{ $statement }}</li>
                    @endforeach
                </ul>
            @endif
            <div>Generado el {{ $generatedAt }} (hora de Perú).</div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                @foreach ($headings as $heading)
                    <th>{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($row as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td class="empty" colspan="{{ max(1, count($headings)) }}">
                        No hay contenidos que coincidan con los filtros aplicados.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
