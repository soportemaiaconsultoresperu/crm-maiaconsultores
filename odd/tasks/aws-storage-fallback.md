# AWS storage with local fallback

## Objective and problem
Today every file in the CRM is written to local disk. The goal is that all file handling uses AWS S3 when AWS is configured, and falls back to the project's own local storage when it is not, with no other change in behaviour.

## Confirmed decisions (operator)
- The S3 adapter is installed now (`league/flysystem-aws-s3-v3`), rather than deferring it to deployment.
- Private files are delivered by a **temporary signed URL**: the application still decides authorization first, then hands out a short-lived URL so the file does not pass through the application server.
- Files that are genuinely public are stored in a public bucket and served by direct URL.
- Activation is **automatic by credentials**: when `AWS_BUCKET`, `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` are all present, AWS is used; if any is missing, local storage is used. No manual switch is required.

## Verified facts this feature builds on
- The adapter was missing and is now installed: `league/flysystem-aws-s3-v3` 3.35.3, `aws/aws-sdk-php` 3.399.1, plus `aws/aws-crt-php`, `mtdowling/jmespath.php` and `symfony/filesystem`. The lock shows 5 installs, 0 updates and 0 removals, so no unrelated package moved.
- The `s3` disk entry already existed in `config/filesystems.php` but could not work without the adapter, and no code ever used it.
- `docs/DECISIONES.md` (ADR-011) already states the intended target: local in development, S3 in production, only signed URLs, and no public symlink for private files. This feature implements that decision rather than inventing a new policy.
- Application code touches storage through 29 explicit disk call sites and never relies on a default disk. There is no `Storage::url`, `temporaryUrl` or `Storage::disk('public')` usage anywhere, so the `public` disk is currently dormant.
- `config('filesystems.docs_disk')` is read in five places but is never defined, so it always falls back to the literal `docs`.
- Only three product call sites assume a local path and therefore break on S3: the quotation email attachment in `app/Mail/GenericEmail.php:81`, the company logo preview in `app/Http/Controllers/SettingController.php:213,224`, and the lead template download in `app/Http/Controllers/LeadController.php:253-263`.
- `app/Services/Courses/DomPdfRenderer.php` and the certificate template depend on local font and image files. Those are deployment assets baked into the image, not user files, and S3 does not address them.
- Roughly 130 `Storage::fake` call sites exist across 29 test files. `tests/Feature/Content/ContentAttachmentHttpTest.php` asserts the local layout directly (root under `app/private`, private visibility, no `url`, no `serve`) and must become mode-aware.
- Deployment: `docker-compose.yml` mounts a `storage-data` volume into `init`, `app`, `queue`, `scheduler` and read-only into the web server, `docker/nginx/default.conf:19` aliases `/storage/` to `storage/app/public`, and the production backup guide tars Docker volumes. With S3 the user files leave that volume and the bucket needs its own versioning and backup policy.
- `config/excel.php` points its temporary files at a local path with no remote disk, which matters only if imports or exports run on a queue worker outside the web container.

## Scope and constraints
- Additive and behaviour-preserving: with AWS absent, every existing test and every existing flow must behave exactly as it does today.
- The switch happens in configuration, not at 29 call sites. Keep the semantic disk names `docs`, `local` and `public` so call sites do not change, and give the cloud-backed disks a root prefix that mirrors the local tree, so a stored path means the same thing in both modes.
- Never introduce `->path()` for a stored user file. Anywhere a real filesystem path is genuinely required, generate into a temporary location that works in both modes.
- Authorization stays in the application and is decided before any URL is issued. A signed URL must never be produced for a file the current user may not read.
- Do not change the global timezone, and do not touch the content module's business rules.
- Do not commit the unrelated in-flight working tree changes.
- No production or deployment action, and no infrastructure change without separate explicit authorization.

## Execution settings
- Route: delegated writer per work unit, independent verification where the change is security-relevant.
- TDD mode: not explicitly enabled; ordinary functional checks apply. Runner: the absolute PHP binary because `php` is not on PATH: `C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe`.
- Delivery strategy: feature-branch-chain, one commit per work unit, on `feat/aws-storage`, which is stacked on `feat/content-scheduling-module`.
- Branch note: this branch sits on top of the content module branch, which itself sits on the course-talks branch. That keeps the content module's storage-related test available to update here, but makes a three-level stack. Rebase onto `main` before opening a pull request if a flat history is preferred.

## Tasks
- [x] A1 — Install the S3 adapter and commit only the composer changes. Acceptance: the package is present in `vendor/`, the lock shows no unrelated updates, and existing tests still run.
- [x] A2 — Storage mode switch in configuration: `c7996c3`.
- [x] A3 — Remove the three local-path blockers: `b7c466c`.
- [x] A4 — Authorized delivery of private files by signed URL: `bf325ac`.
- [x] A5 — Public files and environment documentation: `fba10e2`. `FILESYSTEM_DISK_V2` was read by nothing and was removed; `.env.example` needed an authorized shell edit because the host safety policy blocks file tools on `.env`-named paths, which the operator explicitly approved.
- [x] A6 — Regression and delivery notes: `docs/ALMACENAMIENTO.md` plus the ADR and deployment updates.
- [x] A7 — Independent verification of the branch: returned `partial` with claims 1, 2, 3, 6, 7 and 8 verified and claims 4 and 5 falsified.
- [x] A8 — Bounded correction of the two falsified claims, plus the cutover documentation the verification surfaced: `81afc61`.

## Progress and evidence
- A1 done: adapter installed; composer.json gained one require line; the lock shows 5 installs, 0 updates, 0 removals; the smoke test (`tests/Feature/AuthTest.php`) still passes.
- Branch `feat/aws-storage` created from the content module tip.

## Final state
The feature is implemented, verified and documented in seven commits on `feat/aws-storage`. Everything the operator asked for works: with the three credentials present every disk in the CRM is backed by S3, and with any of them missing the same disks keep the project's local storage.

What the independent verification established:
- Mode resolution verified in both directions, including empty and whitespace-only values, and the local branch was proven a real behavioural equality rather than a passing test: the resolved disk configuration and an end-to-end local download are identical to the base branch.
- No stored user file is resolved as a local path any more; the surviving `->path()` and `storage_path()` occurrences are HTTP request paths, comments, the dompdf font directory and an incoming upload's temporary path.
- Authorization ordering verified by counting signing calls on a cloud disk: nine unauthorized attempts across documents, content attachments, QR certificates, commercial documents and the logo preview produced zero signing calls and no signature or bucket string in any header or body, while each authorized control produced exactly one and a redirect carrying a signature.
- The feature added zero routes and caused zero test failures.

Two requirements of A4 were falsified and then fixed in `81afc61`:
1. The signed redirect lost the download identity, so a cloud download arrived under the generated object key. It now carries the intended name and content type as object-response overrides, proven against the decoded query parameters of a locally presigned URL.
2. A disk that claimed it could sign but threw produced a server error instead of streaming. Signing failures are now logged and degrade to streaming.

What remains open, none of it code:
- Nothing was exercised against a live bucket. The presigned URL is proven, the signed GET is not, and neither are real object metadata, bucket ACL enforcement or CORS.
- A cutover step exists and is documented: objects stored before the switch are not copied anywhere, so they must be uploaded to the bucket preserving their relative paths and prefixes before credentials are filled, or they become unreachable. This is the one item that can lose data if it is skipped.
- The operator must decide whether the bucket is versioned and backed up, because user files have left the `storage-data` volume that the current backup guide covers.
- Assets that must stay local regardless of AWS: the deployment font cache and the images baked into the image for certificate and PDF rendering.
- The branch is stacked three levels deep, so rebase onto `main` before opening a pull request if a flat history is preferred.

## Next step
The feature is complete. The next action belongs to the operator: copy the existing stored objects to the bucket, then fill the three credentials, then verify a real upload and a real signed download.

## Result and evidence
- Branch `feat/aws-storage`, six commits on top of the content module: `fcc40cf` adapter, `c7996c3` mode switch, `b7c466c` path blockers, `bf325ac` signed-URL delivery, `fba10e2` environment examples and mode-agnostic test, and the A6 documentation commit.
- The switch is three variables. Filling `AWS_BUCKET`, `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` moves every disk to S3; any one missing keeps local storage. No call site changed for that, because the semantic disk names were kept and each cloud disk reuses the local tree as its root prefix (`private`, `private/docs`, `public`), so a stored relative path means the same object in both modes.
- Private downloads keep the same authorization decision and only then hand out a short-lived signed URL, carrying the intended download name and type. A refused request can never receive a URL; this is pinned by tests in the documents and content attachment suites.
- Cloud behaviour is proven without a real bucket: the S3 adapter signs presigned URLs locally, so the redirect branch is asserted with placeholder credentials, and the local branch is asserted through the existing streaming tests.
- The full suite at the end of A6: 1705 tests, 1690 passed. Storage-related suites are entirely green. No failure is caused by this work.
- Attribution correction worth keeping: of the failures, three are NOT pre-existing on the base branch. `Email\SendEmailMessageCorrelationTest` (both tests) and `Courses\CourseAcademicDocumentGenerationTest` fail because of the other feature's uncommitted working tree, which changed the mail job's provider call and made the certificate template embed the QR as base64. The earlier verification had folded these into the pre-existing set; they are in-flight-caused, not base-caused, and none is storage-caused.
- Temporary spreadsheet files are written and consumed synchronously by the same request, so multi-container visibility is not a risk today. Recorded in the new operator guide without changing that configuration.

## Open items after A6
- The operator guide `docs/ALMACENAMIENTO.md` is the entry point; ADR-011 now records that it is implemented and a derived ADR-017 points at it.
- Residual documentation that still describes local paths only, deliberately left out of A6 scope: the user-guide checklist mentions the logo under `storage/app/private/company/`, and the V2 baseline note still says files stay local until a later stage. Both should be revisited when the operator decides the rollout order.
- The content attachment service docblock still states the docs disk root as a local path unconditionally; it is now mode-dependent.
- Nothing was verified against a live bucket, and no production or infrastructure action was taken.
