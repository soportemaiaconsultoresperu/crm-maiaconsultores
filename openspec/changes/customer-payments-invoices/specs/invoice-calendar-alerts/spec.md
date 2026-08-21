# Invoice Calendar Alerts Specification

> Change: `customer-payments-invoices`. Upstream: `openspec/changes/customer-payments-invoices/proposal.md` and first-slice assumptions approved for spec.
> Scope: global calendar visibility for unpaid customer invoice due dates.

---

## Purpose

Define how v1 customer invoices produce global calendar signals for unpaid due dates without adding customer notifications, accounting workflows, or payment metadata, while respecting automatic persisted `Vencida` status for overdue active chargeable invoices.

---

## Requirements

### Requirement: REQ-CAL-01 — Unpaid chargeable invoices appear on global Calendar

The system MUST expose a due-date alert in the global **Calendario** for each active chargeable invoice that has a due date and a status other than `Pagado` or `Nota de crédito`. If such an invoice is past due, the system MUST persist its status as `Vencida` before relying on that status for calendar behavior.

#### Scenario: En proceso invoice appears on due date

- GIVEN an active customer invoice has status `En proceso` and due date `2026-09-15`
- WHEN an authorized calendar user views global **Calendario** for `2026-09-15`
- THEN an invoice payment alert is shown for that invoice.

#### Scenario: En proceso invoice past due is persisted as Vencida before alert behavior

- GIVEN an active customer invoice has status `En proceso`, due date yesterday, and is not retired/anulled
- WHEN the automatic overdue process evaluates invoice alerts
- THEN the invoice `status_id` is persisted as `Vencida`
- AND an authorized calendar user viewing global **Calendario** or overdue payment alerts sees behavior based on persisted `Vencida`.

#### Scenario: Vencida invoice appears by due date

- GIVEN an active customer invoice has status `Vencida` and due date `2026-09-15`
- WHEN an authorized calendar user views global **Calendario** for `2026-09-15`
- THEN an invoice payment alert is shown for that invoice.

### Requirement: REQ-CAL-02 — Paid and credit-note invoices are not active payment alerts

The system MUST NOT show active unpaid payment alerts for invoices with status `Pagado` or `Nota de crédito`. These statuses MUST NOT automatically transition or persist as `Vencida` for alert purposes even when their due date is past.

#### Scenario: Pagado suppresses active alert

- GIVEN an invoice has status `Pagado` and a due date
- WHEN global **Calendario** is rendered
- THEN the invoice is not shown as an active unpaid payment alert.

#### Scenario: Nota de crédito suppresses active alert

- GIVEN an invoice has status `Nota de crédito` and a due date
- WHEN global **Calendario** is rendered
- THEN the invoice is not shown as an active unpaid payment alert.

### Requirement: REQ-CAL-03 — Calendar alert links invoice and customer context

The system MUST make each invoice due-date alert clearly attributable to both the customer and the invoice. The alert MUST provide enough navigation or reference for an authorized user to return to the relevant customer/invoice detail.

#### Scenario: Calendar alert identifies source

- GIVEN an unpaid chargeable invoice exists for customer `Cliente A`
- WHEN an authorized user views its calendar alert
- THEN the alert shows or links to `Cliente A` and the invoice identifier.

### Requirement: REQ-CAL-04 — Alert date follows invoice due date

The system MUST place the invoice alert on the invoice due date. If the due date changes, the alert MUST reflect the new date and MUST NOT leave an active orphan alert on the old date.

#### Scenario: Due date changes

- GIVEN an unpaid invoice has due date `2026-09-15`
- WHEN an authorized financial writer changes the due date to `2026-09-20`
- THEN the active calendar alert appears on `2026-09-20` and no active alert remains for that invoice on `2026-09-15`.

### Requirement: REQ-CAL-05 — Alert lifecycle follows invoice chargeability

The system MUST resolve, hide, or otherwise stop presenting an active unpaid alert when an invoice becomes non-alertable through status `Pagado`, status `Nota de crédito`, or the project's approved non-destructive retire/anul convention. Overdue persistence MUST apply only while the invoice remains active and chargeable.

#### Scenario: Invoice is marked paid after due date

- GIVEN an unpaid invoice has an active due-date alert
- WHEN an authorized financial writer changes the invoice status to `Pagado`
- THEN the alert is no longer presented as an active unpaid invoice alert while any existing audit/history convention is preserved.

#### Scenario: Invoice is retired non-destructively

- GIVEN an unpaid invoice has an active due-date alert
- WHEN an authorized financial writer retires/anuls it through the approved non-destructive flow
- THEN the alert is no longer presented as an active unpaid invoice alert and no active orphan calendar item remains.

### Requirement: REQ-CAL-06 — Calendar alerts are idempotent per invoice

The system MUST prevent duplicate active calendar alerts for the same invoice/due-date state when an invoice is saved repeatedly without changing alert-relevant fields.

#### Scenario: Re-saving invoice does not duplicate alert

- GIVEN an unpaid chargeable invoice has due date `2026-09-15`
- WHEN an authorized writer saves the invoice multiple times without changing due date, status, customer, or active/retired state
- THEN global **Calendario** shows at most one active payment alert for that invoice.

### Requirement: REQ-CAL-07 — Calendar visibility respects financial permissions

The system MUST respect explicit financial read permission when showing invoice payment alert details in global **Calendario**. Users lacking financial read permission MUST NOT see sensitive invoice amount/status details through calendar alerts.

#### Scenario: Authorized calendar user sees financial alert details

- GIVEN a user has calendar access and financial read permission
- WHEN they view an invoice due-date alert
- THEN they can see the customer, invoice identifier, due date context, status, and amount allowed by the financial read permission.

#### Scenario: Calendar user without financial read permission is protected

- GIVEN a user has calendar access but lacks financial read permission
- WHEN they view global **Calendario**
- THEN invoice financial details are hidden or the alert is not shown, according to the project's authorization-safe calendar convention.

### Requirement: REQ-CAL-08 — No customer-facing notifications in v1

The system MUST NOT send customer email, WhatsApp, payment reminders, or external notifications from invoice due-date alerts in v1. Calendar behavior is internal CRM visibility only.

#### Scenario: Due date arrives

- GIVEN an unpaid chargeable invoice is due today
- WHEN the calendar alert becomes visible internally
- THEN no customer-facing email, WhatsApp, payment link, or external notification is sent by this v1 feature.

---

## Cross-references

- Proposal §4, §5, §9, §12, §15.
- Assumptions: `Nota de crédito` non-alertable; `Vencida` is automatic and persisted in BD for overdue active chargeable invoices; design will decide the persistence mechanism.
