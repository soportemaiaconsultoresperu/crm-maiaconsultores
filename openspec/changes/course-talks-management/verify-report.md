# Verify report — `course-talks-management`

- **Phase**: sdd-verify (independent judgement; read-only on the product).
- **Artifact store**: OpenSpec (`openspec/changes/course-talks-management/`).
- **Repo**: `C:/laragon/www/crm-maia-consultores`, branch `feat/course-talks-slice-6-ui`, HEAD `7dfbcc2`, working tree clean.
- **Runner**: `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test` (bare `php` is not on PATH). Every run sequential, one command per shell block.
- **Skills loaded**: `acceptance-checklist`, `project-discovery` (injected paths).
- **Strict TDD**: active (`openspec/config.yaml` `delivery.strict_tdd: true`; the note that `config.yaml` nominally documents the unrelated `b12-ui` change stands, but the strict-TDD requirement was supplied for this phase and is honoured).
- **Structured status consumed (parent-supplied, native)**: `artifactStore=openspec`, `taskProgress=117/117` (`allComplete: true`), `nextRecommended=verify`, `verify=ready`, `archive=blocked` (verifyReport missing). Items this report produced that the status could not see are listed under Findings.

---

## 1. Verdict summary

| Requirement (delta spec) | Verdict |
|---|---|
| Unified activities module | **PARTIAL** — unified list and visible `Tipo` pass; the required filter by `Curso`/`Charla`/all is not implemented |
| Activity, edition, and class model | PASS |
| Edition states and modality | PASS |
| Participants and enrollment | PASS |
| Enrollment and attendance states | PASS |
| Courses versus talks | PASS |
| Course grades and averaging | PASS |
| Payment completion before documents | PASS |
| Document types | PASS |
| Automatic certificate generation | **FAIL** — nothing generates when the final condition completes |
| Configurable PDF template matching reference design | PASS |
| Certificate filename pattern | PASS |
| Unique secure QR access | PASS |
| Revocation and regeneration | PASS |
| Receipts, invoices, and 18% IGV | PASS |
| Email delivery | PASS |
| Assisted WhatsApp delivery | PASS |
| Delivery history and last sent date | PASS |
| Pending and one-day configurable alerts | PASS |
| Permissions | **PARTIAL** — every clause enforced except “viewing audit/history”, whose permission has no surface |
| Auditability | PASS |
| v1 non-goals | PASS (structural absence; no dedicated test for the tax-generation non-goal) |

**Counts: 22 requirements — 19 PASS, 2 PARTIAL, 1 FAIL. 30 scenarios — 28 satisfied, 2 unmet.**

---

## 2. Requirement-by-requirement evidence

### 2.1 PASS — Activity, edition, and class model

- **Create an edition from an activity** — `tests/Feature/Courses/CourseEditionCreateHttpTest.php::test_authorized_user_can_open_the_form_and_create_an_edition` (authorized user opens the form and the edition is persisted under its activity).
- **Reject duplicate activity code** — `tests/Feature/Courses/CourseActivityCreateHttpTest.php::test_duplicate_activity_code_is_rejected_without_creating_a_second_record` (`assertDatabaseCount`, original code/row unchanged) and `tests/Feature/Courses/CourseActivityEditionServiceTest.php::test_activity_service_rejects_duplicate_manual_codes_and_emits_course_audit_event`.
- **Structural (activity/edition/class fields)** — `database/migrations/2026_08_26_000001_create_course_domain_foundation_tables.php:4` (`course_activities`: unique `code`, `type`, `name`, `official_academic_hours`, `base_syllabus_json`, `reference_price`, talk flags); `:5` (`course_editions`: `state`, `modality`, `starts_on`/`ends_on`, `address`, `access_url`, `price_amount`, `currency`, `syllabus_override_json`, `responsible_user_id`, `delivery_due_days`); `:6` (`course_edition_teachers`); `:7` (`course_sessions`: `session_date`, `starts_at`, `ends_at`, `teacher_name`, `topic`); `:11` (`course_attendances`).

### 2.2 PASS — Edition states and modality

- **Configure hybrid edition** — `tests/Feature/Courses/CourseEditionValidationTest.php::test_hybrid_editions_require_address_and_access_url`; `tests/Feature/Courses/CourseEditionCreateHttpTest.php::test_hybrid_edition_without_access_link_is_rejected_on_the_access_url_field`.
- **States `Borrador`/`Programada`/`En curso`/`Finalizada`/`Cancelada`** — `app/Enums/Courses/CourseEditionState.php:3` (`label()` returns the spec’s Spanish labels verbatim); transitions `CourseEditionValidationTest::test_valid_edition_state_progression_is_persisted`, `::test_cancellation_is_allowed_only_before_finished`, `::test_invalid_state_transitions_are_rejected_without_mutating_edition`.
- **Modalities `Presencial`/`Virtual`/`Híbrida`** — `app/Enums/Courses/CourseModality.php:3`; `CourseEditionValidationTest::test_presential_editions_require_an_address`, `::test_virtual_editions_require_an_access_url`.

### 2.3 PASS — Participants and enrollment

- **Enroll participant with minimum data** — `tests/Feature/Courses/CourseEnrollmentHttpTest.php::test_individual_enrollment_creates_a_minimum_participant_record`; `tests/Feature/Courses/CourseEnrollmentServiceTest.php::test_it_links_an_existing_contact_and_rejects_a_duplicate_edition_enrollment`.
- **Company pays for multiple participants, separate academic records** — `CourseEnrollmentHttpTest::test_group_enrollment_creates_the_payer_group_and_one_enrollment_per_participant`; `CourseEnrollmentServiceTest::test_it_creates_minimum_participants_with_separate_academic_records_under_one_group_payer`. One row per edition+participant is enforced at the schema (`migration:10`, `unique(['course_edition_id','course_participant_id'])`) and in `CourseEnrollmentService`.

### 2.4 PASS — Enrollment and attendance states

- **Confirm talk participation** — `tests/Feature/Courses/CourseAttendanceAndGradesTest.php::test_talk_attendance_marks_participation_and_requests_eligibility_after_commit`; `tests/Feature/Courses/CourseAttendanceHttpTest.php::test_talk_attendance_confirms_participation_and_the_matrix_shows_it`, `::test_talk_participation_is_confirmed_by_excused_and_late_statuses`.
- **Attendance informative for courses** — `CourseAttendanceAndGradesTest::test_course_attendance_is_informational_and_does_not_request_document_eligibility`; `CourseAttendanceHttpTest::test_course_attendance_is_presented_as_informational`.
- **States supported** — `app/Enums/Courses/CourseEnrollmentState.php:3` (`enrolled`, `confirmed`, `in_progress`, `completed`, `withdrawn`, `no_show`).

### 2.5 PASS — Courses versus talks

- **Talk has no grades** — `CourseAttendanceAndGradesTest::test_talk_editions_reject_grade_recording`; `tests/Feature/Courses/CourseGradeHttpTest.php::test_talk_editions_offer_no_grade_inputs_and_reject_a_submission_with_a_visible_error` (no inputs rendered **and** the submission is refused — the assertion covers both the view and the domain, not just the view).

### 2.6 PASS — Course grades and averaging

- `tests/Unit/Courses/CourseGradeCalculatorTest.php::test_1249_average_is_participation` (`exactAverage '12.4900'`, `displayAverage '12.49'`, `roundedResult 12`, `FinalResult::Participation`).
- `CourseGradeCalculatorTest::test_half_up_boundary_approves_1250_and_1260` (`12.50 → 13`/`Approved`, `12.60 → 13`/`Approved`).
- `CourseGradeCalculatorTest::test_equal_weights_and_display_round_to_two_decimals_without_float_drift` (`12.3367` exact, `12.34` display — equal weight, two decimals, decimal-string arithmetic).
- Persistence of the exact/display average and rounded result on the enrollment: `tests/Feature/Courses/CourseAttendanceAndGradesTest.php::test_course_attendance_is_informational_for_grade_result_and_decimal_average_has_no_float_drift`; one principal grade per session+enrollment at `migration:12` (`unique(['course_session_id','course_enrollment_id'])`).

### 2.7 PASS — Payment completion before documents

- `tests/Feature/Courses/CourseEligibilityTest.php::test_unpaid_invalid_participant_unvalidated_course_reports_explicit_missing_conditions` (`missingConditions === ['payment','participant_data','edition_validations','academic_result']`; payment and academic state tracked independently).
- `tests/Feature/Courses/CourseAcademicDocumentHttpTest.php::test_generation_is_refused_for_an_ineligible_enrollment_with_a_visible_spanish_error` (payment `Pending` → refusal, `assertDatabaseCount('course_academic_documents', 0)`).
- `CourseEligibilityTest::test_refunded_withdrawn_and_no_show_enrollments_are_ineligible`.

### 2.8 PASS — Document types

- `tests/Feature/Courses/CourseAcademicDocumentGenerationTest.php::test_selects_participation_constancy_and_talk_certificate_from_eligibility_result` (`ParticipationConstancy` for a participation result, `TalkCertificate` for a certificate-bearing talk).
- `CourseEligibilityTest::test_confirmed_paid_talk_with_certificate_is_eligible_for_talk_certificate`; `::test_talk_requires_confirmed_participation_and_certificate_enabled`.
- Approval certificate on the paid+approved path: `CourseEligibilityTest::test_approved_paid_course_with_complete_data_and_validated_edition_is_eligible_for_approval_certificate`.

### 2.9 FAIL — Automatic certificate generation

**MUST:** *“The system MUST automatically generate the applicable academic document when all conditions are complete … WHEN the final missing condition becomes complete THEN the system MUST generate the corresponding PDF document automatically.”*

**Observed:** the final-condition trigger fires an event and queues a job, and the job does nothing.

- `app/Services/Courses/CourseEligibilityTriggerService.php:35` dispatches `EvaluateCourseDocumentEligibility` after commit for payment, grade, participation and edition-validation changes.
- `app/Jobs/Courses/EvaluateCourseDocumentEligibility.php` evaluates eligibility and then returns **without generating**; the code states it in a comment: `// Future slice: dispatch document generation here. This slice only evaluates eligibility and intentionally leaves records unchanged.`
- The only invocation of `CourseDocumentGenerationService::generate()` in `app/` is the operator-triggered HTTP action `app/Http/Controllers/CourseTalks/CourseAcademicDocumentController.php:121` (`POST course-talks/enrollments/{enrollment}/documents`, route `course-talks.documents.generate`). There is no listener for `CourseEligibilityEvaluationRequested` (`app/Listeners/` contains only V2 automations), no `GenerateCourseAcademicDocument` job, and no other auto-generation path.
- The suite encodes the gap as intended behaviour: `tests/Feature/Courses/CourseEligibilityAutomationTest.php::test_job_evaluates_eligibility_but_does_not_create_documents_or_mutate_enrollment_data` asserts `assertDatabaseCount('course_academic_documents', 0)` after handling the job for a fully-eligible enrollment.

The second sentence of the requirement (*type selected without the user choosing it*) **is** satisfied — the type is derived from `CourseEligibilityService`, never posted by the user.

**Why this is a finding and not a documented limit:** `known-limitations.md` does **not** list it. `tasks.md` records the no-op on the Slice 2 row (“as a no-op until document generation exists”) — a forward reference that was never completed after Slice 3 built the generator — and `tasks.md` 7.d reframes it as a rollback virtue (“no asynchronous generation to stop”, “a documented no-op”). A documented deferred limitation and an undeclared, spec-contradicting gap are not the same thing, and this one was never surfaced as failing the delta spec.

### 2.10 PASS — Configurable PDF template matching reference design

- `tests/Feature/Courses/CourseCertificateTemplateTest.php::test_reference_template_renders_required_certificate_and_temario_pages` asserts the rendered HTML contains the participant, activity, modality, date range, `24 horas académicas`, issue location/date, certificate code, both signatures, `Temario` and the syllabus topics, and that the temario page is separated (`page-break-after: always`).
- `resources/views/course-talks/certificates/reference.blade.php:40-59` (modalidad, duración, firmas, QR, código, temario).
- Admin-configurable without removing business data: `CourseCertificateTemplateTest::test_admin_settings_customize_text_and_signatures_without_removing_required_data`; template CRUD + allowlists: `CourseCertificateTemplateTest` (18 tests) and `tests/Feature/Courses/CourseCertificateTemplateHttpTest.php` (18 tests).

### 2.11 PASS — Certificate filename pattern

- `tests/Unit/Courses/CourseCertificateFilenameTest.php::test_builds_human_readable_reference_filename` asserts the **exact** spec sample: `Certificado_Alvaro Segundo Alama Silva_Curso Avanzado de Saneamiento Ambiental_01-04.07.26_Maia Consultores.pdf`.
- `app/Services/Courses/CourseCertificateFilenameService.php` builds `Certificado_{participante}_{curso}_{rango-fechas}_{empresa}.pdf` and sanitizes only forbidden filesystem characters (spaces preserved). Triangulated by `::test_sanitizes_forbidden_filesystem_characters_without_collapsing_spaces`.

### 2.12 PASS — Unique secure QR access

- `tests/Feature/Courses/CourseCertificateQrSecurityTest.php::test_token_creation_persists_only_hmac_hash_and_public_qr_streams_current_private_pdf` — stores `hash_hmac('sha256', $token, config('app.key'))`, `assertDatabaseMissing(... ['qr_token_hash' => $token])`, and the public route streams only the private PDF (`application/pdf`, streamed content).
- `CourseCertificateQrSecurityTest::test_missing_revoked_or_replaced_tokens_return_same_generic_response_without_personal_data` — 404 with `Documento no vigente o no disponible.` and explicit assertions that document number, email, phone, grade and participant name are **absent** from the body.
- `::test_qr_route_is_rate_limited_after_sixty_requests_without_leaking_private_data`; `::test_qr_token_hash_has_a_database_index_for_current_token_lookups`.

### 2.13 PASS — Revocation and regeneration

- `tests/Feature/Courses/CourseAcademicDocumentGenerationTest.php::test_regeneration_requires_reason_and_authorized_actor_replaces_and_revokes_the_old_document`.
- `tests/Feature/Courses/CourseAcademicDocumentHttpTest.php::test_annulment_requires_a_reason`, `::test_annulment_revokes_the_qr_token_and_persists_the_actor_and_reason`, `::test_regeneration_replaces_the_current_document_with_a_new_current_one`, `::test_an_annulled_or_replaced_document_cannot_be_annulled_again`.
- Domain guard re-read under lock: `CourseCertificateQrSecurityTest::test_annulment_refuses_a_stale_instance_whose_persisted_status_is_no_longer_current`.

### 2.14 PASS — Receipts, invoices, and 18% IGV

- `tests/Unit/Courses/CourseCommercialDocumentMoneyTest.php::test_boleta_and_factura_add_configured_igv_to_activity_and_certificate_charges` — `100.00 + 20.00 → subtotal 120.00, igv_rate 0.1800, igv 21.60, total 141.60` (exactly the spec scenario). `::test_uses_integer_half_up_arithmetic_without_float_rounding` proves the half-up integer arithmetic; `::test_recibo_has_no_igv`.
- **Upload external invoice** — `tests/Feature/Courses/CourseCommercialDocumentRegistrationTest.php::test_it_registers_an_external_factura_for_one_enrollment_with_pending_upload_and_audit_values` + `::test_it_uploads_private_attachments_and_preserves_the_replaced_document_for_audit`; field persistence asserted in `tests/Feature/Courses/CourseCommercialDocumentHttpTest.php:336-341` (`series F001`, `payer_document_type`, `payer_document_number`, `issue_date`) and in `app/Services/Courses/CourseCommercialDocumentService.php:184-195`.
- Attachment is a private `documents` row under `course-commercial-documents/{id}/…`; no public symlink.

### 2.15 PASS — Email delivery

- `tests/Feature/Courses/CourseDocumentEmailDeliveryTest.php::test_it_records_a_successful_email_attempt_with_recipient_override_snapshot_and_actor_activity`.
- `::test_it_sanitizes_email_failures_without_marking_the_document_sent` (failure keeps the document unsent with a sanitized, visible error).
- `::test_it_marks_an_unconfirmed_mail_operation_as_failed_without_a_send_timestamp`; `::test_it_publishes_the_email_job_only_after_the_enclosing_transaction_commits`.
- Same contract for the commercial channel: `tests/Feature/Courses/CourseCommercialDocumentDeliveryTest.php::test_the_queued_commercial_email_carries_the_document_through_a_working_signed_link`, `::test_the_queued_commercial_email_names_the_specific_document_type`.

### 2.16 PASS — Assisted WhatsApp delivery

- `tests/Feature/Courses/CourseDocumentWhatsAppDeliveryTest.php::test_it_creates_an_idempotent_whatsapp_handoff_with_a_secure_document_link_and_pending_snapshot` (handoff opened → still `pending`).
- `::test_it_confirms_an_existing_handoff_with_actor_and_recipient_before_marking_sent`; `::test_it_rejects_confirmation_when_the_recipient_does_not_match_the_existing_handoff`; commercial twin `CourseCommercialDocumentDeliveryTest::test_it_keeps_assisted_whatsapp_pending_until_manual_confirmation_for_an_enrollment_commercial_document`.

### 2.17 PASS — Delivery history and last sent date

- `tests/Feature/Courses/CourseAcademicDocumentDeliveryHttpTest.php::test_resending_appends_a_new_history_entry_without_touching_the_previous_one`.
- `CourseDocumentEmailDeliveryTest::test_failed_email_history_remains_intact_when_a_later_resend_succeeds`; `::test_it_short_circuits_an_exact_duplicate_but_appends_a_resend_with_a_new_key`.
- `CourseCommercialDocumentDeliveryHttpTest::test_the_history_shows_every_appended_attempt_of_one_comprobante`.

### 2.18 PASS — Pending and one-day configurable alerts

- **Overdue after one calendar day** — `tests/Feature/Courses/CourseDeliveryAlertsTest.php::test_a_follow_up_turns_overdue_only_after_the_configured_calendar_days_elapse` (issued exactly one day ago → pending, not overdue; last second of the boundary day → still not overdue; next calendar day → overdue).
- **Configurable threshold** — `::test_the_configured_due_days_change_which_follow_ups_are_overdue`; default in `config/courses.php` (`delivery_due_days => 1`).
- **Discard with reason, audit preserved, document still valid** — `::test_discarding_closes_the_alert_and_records_the_reason_the_actor_and_the_audit_entry` (`causer_id` and `course-delivery-alert-discarded` asserted; reason stored) and `::test_discarding_the_follow_up_leaves_the_certificate_and_its_qr_token_untouched`.
- Dashboard/module visibility: `tests/Feature/Courses/CourseDeliveryAlertsDashboardTest.php::test_the_main_dashboard_shows_the_course_delivery_counts_to_a_user_who_can_see_the_module`, `::test_the_main_dashboard_shows_nothing_about_the_module_to_a_user_who_cannot_see_it`.

### 2.19 PARTIAL — Permissions

- **User without grade permission cannot grade** — `tests/Feature/Courses/CourseGradeHttpTest.php::test_users_without_grade_permission_neither_read_nor_write_the_matrix`.
- **User without revocation permission cannot annul** — `CourseAcademicDocumentHttpTest::test_annulment_is_denied_without_the_revoke_permission`, `::test_generation_and_regeneration_are_denied_without_the_generate_permission`, `::test_regeneration_and_annulment_also_require_the_revoke_ability_the_domain_enforces`.
- Separate permissions seeded and assignable — `tests/Feature/Courses/CoursePermissionPolicyTest.php::test_course_permissions_are_seeded_and_assignable` (13 `course-talks.*` permissions in `database/seeders/CoursePermissionsSeeder.php`); denials — `::test_course_policies_deny_restricted_actions_without_granular_permissions`; delivery/commercial/template denials — `CourseAcademicDocumentDeliveryHttpTest::test_all_delivery_actions_are_denied_without_the_send_permission`, `CourseCommercialDocumentHttpTest::test_the_commercial_actions_are_denied_and_not_offered_without_the_commercial_permission`, `CourseCertificateTemplateHttpTest::test_a_user_without_the_templates_permission_sees_no_access_control_and_is_denied_every_route`; module-wide denial — `tests/Feature/Courses/CourseTalksNavigationTest.php::test_user_without_module_view_permission_is_denied_every_module_screen_by_url` (403, never 200 and never 500).
- **Unmet clause — “viewing audit/history”:** `course-talks.audit.view` is seeded and `CourseActivityPolicy::viewAudit` (`app/Policies/Courses/CourseActivityPolicy.php:33-36`) consumes it, but **no route, controller or view invokes that ability** — the module has no audit surface and the generic viewer uses the unrelated `audit.view`. Recorded in `known-limitations.md` item 9. The permission exists but is not enforced by anything reachable, so the clause is not satisfied in the running system.

### 2.20 PASS — Auditability

- **Grade correction** — `tests/Feature/Courses/CourseAuditTest.php::test_a_grade_correction_records_who_changed_it_when_the_previous_and_new_value_and_the_affected_enrollment` (who, when, previous value, new value, participant/edition).
- 23 further enumerated changes covered by the same suite; the suite itself found and fixed two real defects (attendance had no trail; the explicit actor never reached the activitylog causer). Additional: `::test_a_grade_entered_by_an_explicit_actor_is_not_attributed_to_a_different_authenticated_user`, `::test_the_audit_trail_records_the_qr_token_hash_and_never_the_raw_qr_token`, `::test_no_activity_payload_carries_a_private_document_path_or_a_signed_link`.

### 2.21 PASS — v1 non-goals

- **No automatic WhatsApp send** — `CourseDocumentWhatsAppDeliveryTest::test_it_creates_an_idempotent_whatsapp_handoff_with_a_secure_document_link_and_pending_snapshot` (opening the handoff never marks it sent); `CourseAcademicDocumentDeliveryHttpTest::test_opening_the_whatsapp_handoff_creates_a_pending_entry_and_never_marks_the_document_sent`.
- **No automatic tax generation** — structural absence: `grep -rln -i sunat app/ tests/ database/` returns only an unrelated 2026-08-20 migration; no tax-document generation, SUNAT, accounting-provider, gateway, student-portal or videoconference code exists in the module. There is **no dedicated test** for this non-goal (see Finding 6); the requirement is a MUST-NOT and is satisfied by absence.

---

## 3. Task completion

- `openspec/changes/course-talks-management/tasks.md`: **117 checked, 0 unchecked.** `grep -c '^\s*- \[ \]'` → `0`; `grep -c '^\s*- \[x\]'` → `117`. This matches the native dispatcher’s `taskProgress 117/117 (allComplete: true)` and the reconciled ledger. **No unchecked implementation task remains.**
- The reconciliation section was read before judging, and its claims were checked rather than trusted:
  - “Slices 1–5 implementation rows backed by the four-lens review plus parent verification” — the referenced per-unit commands/counts exist in `apply-progress.md` and the ones I re-ran match (see §4 and §6).
  - `--filter=Course` regression figures — the latest recorded figure is 463 / 3,527 (foundation corrective unit); my re-run returned exactly **463 / 3,527**.
  - “`CourseRolloutTest` runs the REAL full seed” — confirmed by reading the test (`$this->seed(DatabaseSeeder::class)`; the other course suites seed `CoursePermissionsSeeder` themselves).
  - “Two rows were REFORMULATED rather than ticked” (view split, dashboard-scope refactor) — both state the residual and are recorded in `known-limitations.md`; accurate.
- **Caveat:** the ledger does not claim the automatic-generation requirement, and every row can be `[x]` while §2.9 still fails — the ledger tracks the planned slices (which explicitly scoped the eligibility job as a no-op “until document generation exists”), not the delta spec’s outcome. A complete ledger is not by itself spec conformance.

---

## 4. Test and validation commands (exact, sequential, real output)

1. **Module regression** —
   `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test --filter=Course`
   → `{"tool":"phpunit","result":"passed","tests":463,"passed":463,"assertions":3527,"duration_ms":44729}`
2. **Full suite** —
   `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan test`
   → `{"tool":"phpunit","result":"failed","tests":1267,"passed":1244,"assertions":6613,"duration_ms":163287,"failed":11, ... "errors":12}`

**Full-suite failures (the 11), verified identical to `suite-baseline.md`:**

| # | Test | Group |
|---|---|---|
| 1 | `AdminHttpTest::test_settings_update_round_trip_through_admin_form` | B |
| 2 | `HistoryAndAuditCycleBreakTest::test_show_execution_renders_cycle_break_details_block` | A |
| 3 | `HistoryAndAuditTest::test_show_filters_by_subject_type_query` | A |
| 4 | `ActionEditorLivewireTest::test_webhook_action_renders_b14_banner` | A |
| 5 | `ActionEditorLivewireTest::test_send_whatsapp_template_action_renders_b14_banner` | A |
| 6 | `SendWhatsAppTemplateWidgetLivewireTest::test_b14_banner_is_present` | A |
| 7 | `WebhookWidgetLivewireTest::test_b14_banner_is_present` | A |
| 8 | `WebhookWidgetLivewireTest::test_empty_allow_list_shows_warning_message` | A |
| 9 | `SettingsServiceTest::test_set_persists_typed_values_and_audits` | B |
| 10 | `GmailProviderTest::test_send_returns_documented_error_envelope_when_credentials_missing` | C |
| 11 | `GoogleCalendarWebhookTest::test_remote_edit_for_crm_origin_link_is_overwritten_by_crm_projection` | C |

The **12 errors** are the pre-existing Campaign/Livewire ones (`CampaignMetricsServiceTest`, `CampaignItemActionHttpTest`, `CampaignRunLifecycleTest`, `CampaignTemplateHttpTest`) that read `User::where('email', env('ADMIN_EMAIL'))->first()` and then use it; they are the 12 the baseline’s own arithmetic folded into its 1,189 total (1,166 + 11 + 12). **No new failure appeared.** A corroborating check: `git log --oneline main..HEAD --` over the seven failing tests’ files is **empty**, i.e. this branch never touched the admin, settings, email-provider, calendar or automation code those failures live in.

3. **Migration state (read-only, real MySQL connection from `.env`)** —
   `/c/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan migrate:status` (filtered)
   → all six course-related migrations report `Pending`:
   `2026_08_26_000001_create_course_domain_foundation_tables`, `…_000002_add_course_academic_document_qr_token_hash_index`, `…_000003_add_email_message_id_to_outbound_deliveries`, `…_000004_add_delivery_status_to_course_commercial_documents`, `…_000005_add_course_commercial_document_idempotency_key`, `…_000006_add_delivery_discard_reason_to_course_commercial_documents`. `grep -c Pending` → `6`.

---

## 5. Strict-TDD verification

- `apply-progress.md` contains a `TDD Cycle Evidence` section/table for **every** unit (search `TDD Cycle Evidence` → 40 occurrences). The gate is satisfied.
- **Reported test files cross-referenced against the codebase:** every class named in the evidence exists (`tests/Feature/Courses/*` ×32, `tests/Unit/Courses/*` ×4, plus `DocumentServiceTest`, `DocumentHttpTest`, `tests/Feature/Email/SendEmailMessageCorrelationTest`, `tests/Feature/SeedersTest`). No ghost file.
- **GREEN still true:** the relevant suites are green in my own runs — `--filter=Course` 463/3,527 green, and the sampled units (`CourseAcademicDocumentGenerationTest`, `CourseAcademicDocumentHttpTest`, `CourseEligibilityAutomationTest`, `CourseCertificateQrSecurityTest`, `CourseDeliveryAlertsTest`, `CourseRolloutTest`) are included in that green run.
- **RED sampled and checked (behavioural failure, not a fatal):**

| Unit | Recorded RED (real) | Behavioural? |
|---|---|---|
| Delivery cycle (Defect A — commercial terminal state) | `{"result":"failed","tests":7,"passed":4,"assertions":33,"failed":3}` — `Failed asserting that two strings are identical -'sent' +'queued'`; `-'failed' +'queued'`; `Failed asserting that null is identical to 'No fue posible confirmar…'` | Yes — assertion failures on the state the defect is about |
| Duplicate-certificate refusal (Defect B) | `Failed asserting that 2 is identical to 1` (a second `Current` row), and at HTTP `Session is missing expected key [errors]` | Yes — assertion failures |
| Actor attribution (7.c) | 18 of 24 failed, all `causer=NULL` or `causer=4` (wrong session user); none a fatal | Yes — assertion failures |
| Document-deletion guard | `DocumentServiceTest` `{"passed":5,"failed":2,"errors":2}` — two assertion failures (`El archivo privado … Failed asserting that false is true`) **plus two uncaught FK `QueryException` errors that are the defect itself**; `DocumentHttpTest` `Expected response status code [409] but received 500` | Yes — the two “errors” are the defective delete path throwing, and the HTTP RED is a status assertion; not a test-harness fatal |
| Rollout seeding (7.d) | `{"tests":9,"passed":2,"failed":4,"errors":3}` — 4 assertion failures (permission absent, no sidebar entry, 0/13 permissions) and 3 errors from `hasPermissionTo('course-talks.view')` throwing `PermissionDoesNotExist` | Yes — the errors are the missing permission manifesting, not a fatal; the two passes are the by-design negative guard and the eligibility no-op |

- **Assertion-quality audit:** the sampled suites assert concrete values (exact filenames, `120.00/21.60/141.60`, `12.49→12`, HTTP status + session error bag + DB counts, streamed `application/pdf` content), not types or existence alone. No `markTestSkipped`, no `expectNotToPerformAssertions`, no `assertTrue(true)`, no ghost loops. One genuine implementation-detail assertion was found (Finding 5).

---

## 6. Review-workload / PR-boundary verification

- `tasks.md` `Review Workload Forecast`: `Chained PRs recommended: Yes`, `400-line budget risk: High`, `Chain strategy: stacked-to-main (approved)`, `Decision needed before apply: No`.
- The artifacts record the overruns honestly: the commercial delivery cycle 369 lines (under budget); **R1 641**; **foundation corrective 616** (831 counting bookkeeping); **7.c 1,043** (2.6× budget); 7.d exactly 400; 6.f-1b, 6.t1 and 6.t2 each above budget; `apply-progress.md` explicitly recommends `size:exception` for 7.c and the foundation unit.
- **No formal `size:exception` record exists in `tasks.md`**; only recommendations in `apply-progress.md`. The 400-line budget was exceeded repeatedly and the exception was never formally recorded (Finding 4).
- **PR boundary:** relative to `main` the change is a single branch (`feat/course-talks-slice-6-ui`, 29 commits, **205 files, +32,958 / −188**). The units are separable commits (e.g. `c72ac0c`, `be1b793`, `a0165df`, `038f312`, `1eb1260`, `7dfbcc2`), but the approved stacked chain of seven separate branches/PRs was not materialised at branch level. I could only verify the branch/commit structure; whether separate PRs exist outside git was not observable here.

---

## 7. Findings

### CRITICAL-1 — Automatic certificate generation is not implemented (contradicts the delta spec; not declared)

- **Requirement:** “Automatic certificate generation” — *WHEN the final missing condition becomes complete THEN the system MUST generate the corresponding PDF document automatically.*
- **Evidence:** `app/Jobs/Courses/EvaluateCourseDocumentEligibility.php` returns before generating (`// Future slice: dispatch document generation here…`); `app/Services/Courses/CourseEligibilityTriggerService.php:35` is the only caller and it dispatches that no-op; the sole generation path in `app/` is the operator POST handled by `CourseAcademicDocumentController.php:121`; the suite pins the gap in `CourseEligibilityAutomationTest::test_job_evaluates_eligibility_but_does_not_create_documents_or_mutate_enrollment_data`.
- **Attributable to this change:** yes (Slice 2 deferred it; Slice 3 built the generator; neither changed the job). The tests are green **because they assert the wrong edge** — exactly the failure shape this change’s own history already produced once (the delivery cycle whose tests stopped where the defect began).
- **Not in `known-limitations.md`.** It must be either implemented or explicitly declared as a deliberately unmet spec requirement before archive.

### WARNING-1 — The required filter by activity type is missing

- **Requirement:** “Unified activities module” — *the user MUST be able to filter by `Curso`, `Charla`, or all activities.*
- **Evidence:** `app/Http/Controllers/CourseTalks/CourseActivityReadController.php:14-24` loads every activity (`orderBy('type')->orderBy('name')`) with no query-parameter handling; `resources/views/course-talks/activities/index.blade.php` renders no filter form or control (the `filters` slot holds only action buttons); `grep` over the `CourseTalks` controllers finds no `type` filter anywhere except the alert screen’s own eight filters and the create form’s type selector. `CourseTalksReadOnlyHttpTest::test_authorized_user_can_view_all_activity_types_and_activity_detail` asserts the unified list only — it never exercises a filter.
- **Attributable to this change:** yes. Verdict for the requirement: PARTIAL.

### WARNING-2 — `known-limitations.md` item 3 understates the migration gap (says two; measured six)

- **Declared:** “**Two migrations** are NOT applied to any real database” (`…000005`, `…000006`), with the consequence “commercial registration idempotency and commercial follow-up discard will fail on a real database with a missing-column error”.
- **Measured:** `artisan migrate:status` against the real MySQL database reports **all six** course-related migrations `Pending`, including `2026_08_26_000001_create_course_domain_foundation_tables` (which creates the module’s 12 tables) and `…000003_add_email_message_id_to_outbound_deliveries`. The real consequence is broader than the artifact states: on that database the module has **no tables at all**, so every module screen fails, not just idempotency/discard; and a shared-infrastructure migration is pending too.
- **Attributable to this change:** yes — an artifact accuracy defect in the deployment-readiness record. Not a spec contradiction (the spec has no deployment requirement), but it must be corrected before archive because the rollout story is materially wrong.

### WARNING-3 — `course-talks.audit.view` is seeded but enforces nothing

- As recorded in `known-limitations.md` item 9 and confirmed by reading the policies: `CourseActivityPolicy::viewAudit` (`app/Policies/Courses/CourseActivityPolicy.php:33-36`) is the only consumer and nothing invokes it; the module exposes no audit surface and the generic viewer uses the unrelated `audit.view`. The “Permissions” requirement’s *viewing audit/history* clause is therefore not enforced. Documented open decision — report, not fix. Verdict for the requirement: PARTIAL.

### WARNING-4 — Review budget repeatedly exceeded and no `size:exception` was recorded

- See §6. Largest unit **7.c at 1,043 changed lines** (~2.6×) and the foundation corrective at 616 (831 with bookkeeping). Recommendations for `size:exception` appear in `apply-progress.md` but no exception token is recorded in `tasks.md`, and the approved stacked chain landed as one 205-file branch. Attributable to this change.

### SUGGESTION-1 — One implementation-detail assertion

- `tests/Feature/Courses/CourseCertificateTemplateTest.php:53` asserts `page-break-after: always` — a CSS declaration rather than rendered structure. It is paired with content assertions (participant, temario topics), so it is not a smoke-only test, but it is the one presentation-detail assertion in the audited surface.

### SUGGESTION-2 — The tax-generation non-goal has no dedicated test

- “No automatic tax generation” is satisfied by structural absence (no SUNAT/accounting-provider/gateway code in `app/`, confirmed by grep) but there is no test asserting the absence of tax side effects, unlike the WhatsApp non-goal which has explicit coverage.

### Accepted open product decisions (documented; do **not** contradict any spec requirement)

Both were consciously left to the owner and are **not** findings:

1. **`course-talks.view` grants participant-PII read access across all editions with no team data-scope.** Confirmed: `CourseActivityPolicy::viewAny`/`view` and `CourseEditionPolicy::viewAny` check only the permission, and `CourseEditionPolicy::view` is `permission OR responsible_user_id` — so the permission alone opens every edition’s enrollments/documents/commercial surfaces. The spec’s “Permissions” requirement asks for permission separation and “unauthorized users MUST NOT perform restricted actions”; it does not require team/ownership scoping, so nothing in the delta spec is contradicted.
2. **Mutation policies take no model instance, so one permission mutates any resource by id.** Confirmed by reading all six policies (`create`/`update`/`delete`/`manage`/`generate`/`revoke`/`send`/`manageAttendance`/`manageGrades` are all `User`-only). The spec is satisfied (the actions are permission-gated and unauthorized users are denied); cross-resource scoping is a product decision, not a spec gap.

### Documented limitations reviewed — none contradicts a spec requirement

`known-limitations.md` items 1 (queued-email terminal transition with a null causer — the human act is audited; the technical transition has no actor to attribute), 2 (`course_edition_teachers` unauditable — teacher changes are not in the spec’s enumerated audit list), 4 (the inert `mailOperation` stub — nothing in `app/` calls it; the wired paths use the real queued email), 5 (`HasAuditColumns` session-only — a different mechanism from the activitylog causer, which is what the spec asks to record), 6 (academic/commercial duplication — no defect), 7 (the 11 pre-existing failures — excluded, confirmed), 8 (destructive schema rollback — no spec requirement), 10 (reserved commercial `sent` status — a declared schema value with live readers), 11 (two raw status columns without enums — no spec requirement), 12 (referenced documents fail closed) — **each is a genuine documented limitation and none contradicts a delta-spec requirement.** Item 3 is the exception: it is documented but factually understated (WARNING-2).

---

## 8. What is NOT verified (no coverage claimed)

- **Deployment / migrations.** No migration was applied and none will be by me. All six course-related migrations are **pending** on the real (dev MySQL) database — the module has no tables there. No real-database schema, unique index (including the MySQL/InnoDB “unique index permits many NULLs” property, documented but not measured), seeding (`php artisan db:seed`) or rollback was executed or exercised. Anything requiring a real database is unverified.
- **Browser / human acceptance.** Nothing was run in a browser. `apply-progress.md` correctly records every human scenario as `not run`, including the rollout drills and the duplicate-registration double-click. Human acceptance remains pending.
- **PDF rendering fidelity.** The PDF assertions are Blade-render assertions and DomPDF is faked at the service boundary in tests; the visual output against the approved official reference design was not inspected.
- **Real email/WhatsApp transport.** The email pipeline is exercised with fakes/queued jobs; no real message was sent and no real `wa.me` handoff was opened.
- **Secondary baseline claims.** The baseline’s “fails identically on `main`” was corroborated (the branch never touched those files: `git log main..HEAD -- <files>` empty) but I did not check out `main` or run the suite there — a read-only verify must not mutate the worktree.
- **PR structure.** Whether the approved chained PRs were created outside this repository was not observable; only the branch/commit structure vs `main` is verified.
- **The automatic-generation gap’s blast radius on the product** is inferred from code reading, not observed in a running deployment.

---

## 9. Archive-gate recommendation

**Do not archive yet.** The module is substantively delivered and most of the delta spec is met with behavioural, independently re-run evidence: the module suite is green (463/3,527) and the full suite shows no new failure against the documented baseline (11 failures + 12 pre-existing errors, identical set). Tasks are 117/117 with a credible reconciliation.

Two things block a clean archive:

1. **CRITICAL-1** — the delta spec requires automatic generation and the system does not do it, and the gap is undeclared. Either wire `EvaluateCourseDocumentEligibility` (or a `GenerateCourseAcademicDocument` job) to `CourseDocumentGenerationService` and add the RED→GREEN test that proves generation on the final condition, **or** get an explicit owner decision to record it as a deliberately unmet requirement in `known-limitations.md` and amend the spec. A green suite that asserts the opposite of the scenario is not acceptance.
2. **WARNING-2** — correct `known-limitations.md` item 3 (six pending migrations, not two) so the rollout story is truthful, and make `php artisan migrate` an explicit owner gate.

**Recommended (not blocking by themselves):** WARNING-1 (activity-type filter — implement or record as a known gap), WARNING-3 (`audit.view` — decide surface or drop), WARNING-4 (formally record the `size:exception`), and the two suggestions.

Once CRITICAL-1 is resolved (implemented or explicitly re-scoped with the owner) and WARNING-2 is corrected, this change is archive-ready on the evidence above.
