# Content module review fixes

Fix every defect found by the read-only review of the "Cronograma de contenidos" module (security, spec-coherence and reliability lenses), applying the product decisions below.
Branch: `fix/content-module-review`. Unrelated uncommitted user changes (CourseTalks, UserController, user requests, SendEmailMessage, CourseDocumentGenerationService, `resources/js/app.js` course-enrollment import, course-talks views, AdminHttpTest, Courses tests, untracked fonts/docs) must never be staged or committed.

## Specs
Product decisions (L2):
- S1 Member scope "Todo en su proyecto": a user assigned to a project (no admin) sees the menu and the project, creates/edits contents and ideas, attaches files, writes calendar annotations, trashes and restores. Catalogs, custom fields/sections, members and project archive/settings remain admin (existing `content_projects.*` permissions). Membership alone must not grant anything on projects the user is not assigned to.
- S2 Archived project = read-only: viewing and exporting allowed; every write (contents, ideas, attachments, annotations, trash/restore/purge, catalogs, fields) is refused until reactivated. Consolidated calendar keeps excluding archived projects.
- S3 Duplicate keeps " (copia)" suffix; spec/guide are adjusted to say so.
- S4 Attachments stay on the detail page after saving; the guide is adjusted, and after creating a content the user lands on its detail with the attachments section visible.

Defects to fix (review findings):
- S5 Editing a content/idea must not drop retired pillar/topic/objective/format/channel selections: the form shows the item's stored retired options marked "(Retirada)" and keeps them selected; new items still cannot choose retired options.
- S6 After a failed save (validation or stale revision) multi-selects (objectives, formats, channels, multi-select custom fields) re-render with the submitted values (old() strings vs int ids).
- S7 A multi-select custom field can be cleared from the form.
- S8 Invalid `month` filter (e.g. `2026-13`, `2026-00`) never causes 500 in grid or exports: it is rejected with a validation error or ignored.
- S9 Content routes reject idea ids and idea routes reject content ids (404), including attachment routes.
- S10 Ideas get the same concurrent-edit protection as contents (revision check + warning).
- S11 Excel export headings (custom field labels) are formula-neutralized like data cells.
- S12 Topic select is filtered client-side by the chosen pillar (server validation unchanged).
- S13 Export matches the screen: same date format (d/m/Y H:i) in grid, trash, export and PDF; overdue marker present in export; docs reworded where columns legitimately differ (custom fields).
- S14 Duplicate succeeds even if a required custom field was added after the original was created (copy existing values, skip the required check), and any duplication error is shown to the user.
- S15 PDF export has a row cap (and Excel streams/chunks) so a full history cannot exhaust memory; the user gets a clear message when the cap applies.
- S16 Attachment upload: count limit (10) and sort are race-safe (transaction + lock on the content row); a stored file is removed if the row insert fails.
- S17 Purge deletes files only after the DB commit; add a bulk "Vaciar papelera" action (per project, admin-or-member per S1, refused on archived per S2).
- S18 Ideas can be trashed from the idea detail; restore redirects to the right detail (idea or content).
- S19 Wording/format consistency: overdue label unified with the docs ("Atrasado"); dates d/m/Y everywhere.
- S20 00:00 rule: drop the unreachable date-only overdue grace; an explicit 00:00 content is overdue after that instant. Docs updated.
- S21 MySQL robustness: planned date year range validated (2000-2099), numeric custom fields validated within decimal(14,2), MySQL connection timezone pinned to +00:00.
- S22 Field-schema service answers 404-equivalent for foreign and unknown section/option ids alike (no existence oracle).
- S23 Attachment downloads send `X-Content-Type-Options: nosniff`.
- S24 Ideas list order matches its docblock (newest first); search escapes `%` and `_`; create/duplicate buttons are protected against double submit.
- S25 Consolidated calendar is reachable from the projects index for users allowed to see it.
- S26 Exports include values of inactive custom fields that still hold data, or docs state they are excluded — choose include.

## Tasks
- [ ] T1 S1,S2,S17,S18(restore),S22,S25 — access & trash: policy abilities, seeder, sidebar, archived read-only, trash service/controller/view + empty-trash route, field-schema 404, projects index link. Route: worker W1. Commit: —
- [ ] T2 S5-S10,S12,S14,S16,S18(trash button),S19(grid),S20,S21(validation),S23,S24 — forms, items, ideas, attachments. Route: worker W2. Commit: —
- [ ] T3 S3,S4,S11,S13,S15,S19(export),S21(db timezone),S26 + docs for every S — exports, config, docs. Route: worker W3. Commit: —
- [ ] T4 Integration: full Content suite + full suite diff vs base, browser smoke as member/admin, commits. Route: inline. Commit: —

## Log
- L1 User: "revisa que el modulo Cronograma de contenidos este todo ok o si hay alguna ambiguedad o algun problema que se pueda presentar, revisa que todo este con coherencia" → review produced the findings above. Then: "si corrige todo lo que has encontrado".
- L2 Decisions: miembros = "Todo en su proyecto (Recomendado)"; archivados = "Sí, solo lectura (Recomendado)"; duplicar = "Con “ (copia)” (Recomendado)"; adjuntos = "Ajustar la guía (Recomendado)".
- L3 Baseline: tests/Feature/Content 229/229 green on feat/internal-activities a2693f7. Full suite has 13 pre-existing failures unrelated to this module.
- L4 W3 done (exports/docs). S21 timezone part REVERTED by parent: local check showed @@global.time_zone=SYSTEM (Peru) and users.updated_at reads 17:46 under SYSTEM vs 22:46 under +00:00, so pinning would shift every existing TIMESTAMP by 5h across the CRM. config/database.php restored; test and docs adjusted. Needs a separate data-migration decision.
- L5 W1 done. Parent fixed SeedersTest regression caused by blanking ADMIN_* in local .env: phpunit.xml now provides test ADMIN_EMAIL/ADMIN_PASSWORD (commit 96df4fe).
