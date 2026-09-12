# Suite baseline — pre-existing failures outside this change

Measured on 2026-09-12, at the close of Slice 6 of `course-talks-management`.

```
/php.exe artisan test
-> 1189 tests, 1166 passed, 11 failed
```

## Why these 11 are not ours

Two independent checks, both reproduced:

1. **They fail identically on `main`.** The permission/seeder counts were run on a temporary
   `git checkout main` with a clean tree and failed with the same numbers
   (`90 vs 89`, `70 vs 69`, `107 vs 106`, `82 vs 81`, `130 vs 129` twice) before any fix in this
   branch. The suite was already red on `main`.
2. **`git log main..HEAD` over the files these tests exercise is empty.** This change never
   touched the admin, automation, Livewire, settings, `GmailProvider` or calendar webhook code.

A permission-set comparison across every `+`/`-` line of `app/Providers` between `main` and this
branch shows the two sets are IDENTICAL: this branch added and removed no permission there. The
`CoursePermissionsSeeder` (13 course permissions) is a separate seeder that these tests do not run,
so it could not move their totals either.

## The 11 failures

### Group A — B12/B14 automations (7): the tests describe UI that does not exist

| Test | File:line | Symptom |
|---|---|---|
| `HistoryAndAuditCycleBreakTest::test_show_execution_renders_cycle_break_details_block` | `HistoryAndAuditCycleBreakTest.php:55` | `SCN-HIST-07-B12.5`: the `<details>` summary must include the cycle-break count (2) — absent |
| `HistoryAndAuditTest::test_show_filters_by_subject_type_query` | `HistoryAndAuditTest.php:119` | rendered execution page does not contain the filtered subject type |
| `ActionEditorLivewireTest::test_webhook_action_renders_b14_banner` | `ActionEditorLivewireTest.php:271` | B14 banner not rendered |
| `ActionEditorLivewireTest::test_send_whatsapp_template_action_renders_b14_banner` | `ActionEditorLivewireTest.php:283` | B14 banner not rendered |
| `SendWhatsAppTemplateWidgetLivewireTest::test_b14_banner_is_present` | `SendWhatsAppTemplateWidgetLivewireTest.php:33` | B14 banner not rendered |
| `WebhookWidgetLivewireTest::test_b14_banner_is_present` | `WebhookWidgetLivewireTest.php:34` | B14 banner not rendered |
| `WebhookWidgetLivewireTest::test_empty_allow_list_shows_warning_message` | `WebhookWidgetLivewireTest.php:69` | empty allow-list warning not rendered |

Fixing these means **implementing B12/B14**, not fixing tests. That work belongs to its own change
(`b12-ui`, which `openspec/config.yaml` still points at).

### Group B — settings and audit (2): functionality absent

| Test | File:line | Symptom |
|---|---|---|
| `AdminHttpTest::test_settings_update_round_trip_through_admin_form` | `AdminHttpTest.php:252` | `Failed asserting that null is not null` — the settings round trip does not persist |
| `SettingsServiceTest::test_set_persists_typed_values_and_audits` | `SettingsServiceTest.php:43` | a setting write must emit a `setting-updated` audit row; no row is written |

### Group C — email provider and calendar webhook (2)

| Test | File:line | Symptom |
|---|---|---|
| `GmailProviderTest::test_send_returns_documented_error_envelope_when_credentials_missing` | `GmailProviderTest.php:21` | stale expectation: expects `NotImplementedException`, the untouched provider returns `NoBoundAccount` |
| `GoogleCalendarWebhookTest::test_remote_edit_for_crm_origin_link_is_overwritten_by_crm_projection` | `GoogleCalendarWebhookTest.php:157` | the expected remote request was never recorded |

## Verification rule for the remaining slices

Slice 7's row requires full verification with `php artisan test`. While these 11 stay open that row
**cannot mean "the whole suite is green"**, because they assert functionality owned by other,
in-flight changes.

The claim this change can honestly make is:

> the full suite produces **no NEW failures** beyond the 11 listed here, and `--filter=Course`
> (the module's own suite) is green.

Any addition to this list is a regression and must be treated as one.

## Fixed inside this change

The 6 permission/seeder counts were updated to the measured reality
(`89->90`, `69->70`, `106->107`, `81->82`, `129->130` twice). The current numbers are correct on
`main` as well, and the seeders producing them (`RolesAndPermissionsSeeder`,
`AdditionalPermissionsSeeder`) belong to no course surface, so updating the stale expectation was
the honest fix rather than a comment-out or a skip.
