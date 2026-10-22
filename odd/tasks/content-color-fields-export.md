# Content module: project color, hideable standard fields, styled exports with date range

Three improvements to "Cronograma de contenidos" requested by the user.
Branch: `fix/content-module-review`. Never commit `storage/fonts/*.json`.

## Specs

- S1 Project color gets real use: "Identidad visual del proyecto (Recomendado)", "Calendarios (Recomendado)", "Exportaciones (Recomendado)", "Selector de color".
  - S1.1 Color picker with a preset palette replaces the free-text field on project create/edit; stored value stays a hex `#rrggbb`; validation rejects anything else; projects without color fall back to a neutral default.
  - S1.2 Visual identity: colored marker on each project in the projects list, a colored band/marker in the header of every project page (grid, ideas, calendar, trash, fields, catalogs, show), readable text contrast.
  - S1.3 Calendars: planned contents in the project calendar and the consolidated calendar are painted with their project's color, with a legend in the consolidated view.
  - S1.4 Exports: Excel and PDF headers use the project color (U3).
- S2 Standard fields can be hidden per project: "Ocultar por proyecto (Recomendado)". An admin (configure ability) chooses which optional standard fields the project uses: pilar, tema, objetivos, formato, canal, hashtags, notas. Hidden fields disappear from the content and idea forms, the grid columns, the grid filters and the exports; data already stored is kept (not deleted) and reappears if the field is shown again; server validation ignores hidden fields (submitted values for hidden fields are not changed). Fecha, hora, estado, título y copy stay mandatory and cannot be hidden. Archived projects read-only as today.
- S3 Excel export gets styling: bold header row with the project color background and readable text, frozen header, auto filter, sensible column widths, wrapped long text, date/time columns formatted, a title block (project, filters/range, generated at) above the table, and the "Atrasado" marker highlighted.
- S4 Date range: "Desde/Hasta en la grilla (Recomendado)". The grid gets "Desde" and "Hasta" date filters (Lima dates, inclusive) in addition to the month; the Excel and PDF exports include exactly that range with the other filters; invalid ranges (Hasta < Desde, bad dates) never 500 and show a clear message; the PDF/Excel summary states the range.

## Tasks

- [x] T1 S1.1-S1.3 — color picker, visual identity, calendars. Route: worker U1. Commit: b200370
- [x] T2 S2 — hideable standard fields (migration, settings UI on the fields page, forms, grid, filters, validation). Route: worker U2. Commit: dec79af
- [x] T3 S1.4, S3, S4 + hidden fields in exports — after T2. Route: worker U3. Commit: a7128b7
- [x] T4 Integration: suites, browser smoke, user guide update, commits. Route: inline. Commit: —

## Log

- L1 User: "goal1 : el color que se identifica el proyecto en el modulo de cronograma de contenido no se usa, encuentrale una utilidad, goal2:  cuando uno en el cronograma de contenido, añade un nuevo contenido viene campos por default pero cuando uno quiere eliminar ciertos campos no esta permitido eliminar, gioa3: en el cronograma de contenidos cuando descargas el excel el excel no tiene ningun estilo arreglalo, ademas que cuando uno descarga el pdf o el excel no se puede seleccionar las fechas mostrar solo en el excel y pdf solo lo que pertenece a ese rango de fechas"
- L2 Decisions: color = identidad visual + calendarios + exportaciones + selector de color; campos = "Ocultar por proyecto (Recomendado)"; rango = "Desde/Hasta en la grilla (Recomendado)".
- L3 Discovery: color is only used for consolidated-calendar annotations (consolidated.blade.php:92) and shown as `<code>` on project show; the form field is free text (projects/_form.blade.php:29). Standard optional fields: pillar, topic, objectives, formats, channels, hashtags, notes; required: planned_date, planned_time, status, title, body (StoreContentItemRequest). Exports filter only by month; Excel has no styles (app/Exports/ArrayExport.php).
- L4 Integration: tests/Feature/Content 318/318 after U3. Browser (admin): palette with 10 swatches + custom picker, colored band (#2563eb) on grid, Desde/Hasta inputs keep values and export links carry them; real downloaded xlsx inspected: title block A1-A3 with range and Peru time, bold header at row 5 filled 2563EB, freeze A6, autofilter A5:N5. Consolidated calendar shows annotations only (unchanged); contents not added there. Guide updated (6.0 campos estándar, color, Desde/Hasta, styled Excel, new messages).
