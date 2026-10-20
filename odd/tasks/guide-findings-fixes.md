# Guide findings fixes

Fix every product inconsistency found while writing the user guides for "Cronograma de contenidos" and "Cursos y charlas", plus unify the CRM tone to "usted".
Branch: `fix/content-module-review` (user WIP was committed first as 9b79efc / 2db12b4). `storage/fonts/*.json` are dompdf cache files: never commit them.

## Specs

Product decisions (L2):

- S1 User WIP committed first as-is ("Commitearlos primero tal cual (Recomendado)").
- S2 Courses: edit course/talk activities and dictados (editions); delete an activity only if it has no dictados, delete a dictado only if it has no enrollments ("Editar + eliminar con resguardo (Recomendado)").
- S3 WhatsApp and email document links both last 7 days ("Ambos 7 días (Recomendado)").
- S4 Whole CRM UI and user-facing messages use "usted" ("Usted (Recomendado)"): no voseo (Elegí, Marcá, tenés, podés…) and no tuteo in views, flash messages, validation/domain messages, JS-rendered text.

Content module (findings from the guide):

- S5 Trash "Fecha planificada" shows d/m/Y H:i like the rest (was 2026-03-08).
- S6 "Archivado — solo lectura" notice also on the content grid and content/idea detail pages.
- S7 "Mover a la papelera" confirmation text must not say only an administrator empties the trash (members can).
- S8 Retired attachment message must not claim restoring the content brings it back; add a "Restaurar adjunto" action (trash ability, refused on archived) OR reword truthfully — choose: add the action.
- S9 Retired options of single/multi selection custom fields are not offered on new contents/ideas (only kept, marked "(Retirada)", on items that already store them), like catalog options.
- S10 Project list: "Incluir archivados" shows only archived; inactive projects get their own filter "Incluir inactivos"; docs explain inactive vs archived.
- S11 Consistent wording for deactivation across catalogs, sections, fields and field options: buttons "Desactivar"/"Reactivar", badge "Inactiva/Inactivo" (option badge currently "Retirada" for custom field options; catalog retired options shown on items stay "(Retirada)" as defined earlier — keep that marker only for selections on existing items).

Courses module (findings from the guide):

- S12 Certificate/constancia prints modality in Spanish (Presencial, Virtual, Híbrida/Semipresencial per enum labels), never the enum's internal English name; tests assert every modality label.
- S13 Second PDF page title matches the document type ("CONSTANCIA DE PARTICIPACIÓN" for constancias, "CERTIFICADO" for certificates).
- S14 Comprobante (commercial document) emails no longer always fail: they use the same system SMTP path as academic documents when no account is bound (mirror the academic single-document rule in SendEmailMessage); fix the two pre-existing SendEmailMessageCorrelationTest failures if they are caused by this.
- S15 Finishing a talk's dictado with "Charla con certificado" triggers automatic constancia/certificate generation like courses; a talk without certificate never shows "Participación de la charla sin confirmar" when attendance is confirmed (status reflects attendance correctly).
- S16 User-facing domain and validation messages in Spanish with Spanish field names (duplicate code, address/URL by modality, duplicate enrollment, payment transitions, invalid grade, "official academic hours", "price amount", "ends on", etc.).
- S17 Invalid WhatsApp phone shows a specific phone message, not "document not deliverable".
- S18 Dictado has a "Responsable" field (nullable user, additive migration), shown and filterable in alerts; alerts show the responsible's name.
- S19 "Activa" checkbox on activities has an effect: inactive activities are hidden from the default catalog list (filter to show them) and cannot get new dictados; existing dictados unaffected.
- S20 Fields/states with no way to set them are removed from the UI (not from the DB): discount (always 0) not shown; enrollment states other than "Inscrito" not offered in filters/badges unless reachable; comprobante states "Enviado"/"Descartado" shown only when reachable (WhatsApp/email flows) — verify reachability before hiding; `course-talks.audit.view` permission documented as reserved (no removal).
- S21 WhatsApp-pending notice on comprobantes shows the human type label ("Factura", "Boleta"…), not the lowercase internal key.
- S22 A "Ver PDF" button to open/download the generated PDF (academic and commercial documents) inside the module, authorized by the same rules as the existing secure link.
- S23 User guides (docs/guia-usuario/modulo-contenidos.md, cursos-y-charlas.md) and module docs updated to the new behavior; guide sections that said "todavía no hace" updated.

## Tasks

- [x] T1 S5-S11, S4 (content views), S23 (content guide) — Route: worker W-A. Commit: 85f3d3c
- [x] T2 S3, S12-S17, S20 (comprobante states), S21, S22, S4 (documents/templates/certificate views) — Route: worker W-B. Commit: 56153c0
- [x] T3 S2, S16 (catalog/edition/enrollment/grade/attendance messages), S18, S19, S20 (discount, enrollment states, audit permission doc), S4 (activities/editions/enrollments/alerts views + course-enrollment-form.js) — Route: worker W-C. Commit: da4fef6
- [x] T4 S4 rest of the CRM (all other views, flash messages, JS, lang) — Route: worker W-D. Commit: 544cd92
- [x] T5 Integration: suites, browser smoke, courses guide S23, commits. Route: inline. Commit: see L5

## Log

- L1 User: "tambien hazla para el modulo de Cronograma de contenidos y otro para cursos y charlas" → guides written; findings listed. Then: "arregla todas las observaciones que has encontrado".
- L2 Decisions: tus cambios = "Commitearlos primero tal cual (Recomendado)"; editar cursos = "Editar + eliminar con resguardo (Recomendado)"; link WhatsApp = "Ambos 7 días (Recomendado)"; tono = "Usted (Recomendado)".
- L3 Baseline: full suite 1768/1783 with 13 pre-existing failures (AdminHttp settings, automations banners x6, SettingsService, CourseAcademicDocumentGeneration, CustomerInvoiceCrud, GmailProvider, GoogleCalendarWebhook remote edit, SendEmailMessageCorrelation x2). User WIP committed: 9b79efc (users), 2db12b4 (courses), 990e43a (docs).
- L4 Integration: parent wired routes/course-documents.php and routes/course-catalog.php into routes/web.php and the CourseEditionService finish hook (editionCompletionCanIssueDocuments). Full suite 1820/1833: the same 13 baseline failures minus the 2 SendEmailMessageCorrelation ones, which are now fixed. Browser smoke (admin): Mostrar inactivas filter, activity with dictados offers Editar but not Eliminar, dictado Responsable and edit form, Ver PDF returns application/pdf via signed link and 302 to login when anonymous. Already-generated PDFs keep the old modality/title until regenerated. S18 needed no migration (responsible_user_id already existed).
- L5 Commits: user WIP 9b79efc/2db12b4/990e43a; W-D 544cd92; W-A 85f3d3c; W-B 56153c0; W-C+wiring da4fef6; courses guide in the closing docs commit.
