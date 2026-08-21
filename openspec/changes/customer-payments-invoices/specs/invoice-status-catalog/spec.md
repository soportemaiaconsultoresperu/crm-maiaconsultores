# Invoice Status Catalog Specification

> Change: `customer-payments-invoices`. Upstream: `openspec/changes/customer-payments-invoices/proposal.md` and first-slice assumptions approved for spec.
> Scope: v1 catalog-backed invoice statuses and their meaning for invoice/payment behavior.

---

## Purpose

Define the catalog contract for invoice statuses so customer invoices use consistent, administrable states instead of free-text values.

---

## Requirements

### Requirement: REQ-STAT-01 — Invoice statuses are catalog-controlled

The system MUST use Catálogos as the source of truth for invoice statuses. Invoice create and edit flows MUST present/select catalog values and MUST NOT allow arbitrary status text outside the invoice status catalog.

#### Scenario: Status list comes from Catálogos

- GIVEN the invoice status catalog contains active values
- WHEN an authorized financial writer opens invoice create or edit
- THEN the status choices are loaded from Catálogos.

#### Scenario: Unknown status is rejected

- GIVEN a submitted invoice status does not exist as an active invoice status catalog value
- WHEN the invoice is validated
- THEN the system rejects the request and does not persist the unknown status.

### Requirement: REQ-STAT-02 — Initial required values

The system MUST provide the initial invoice status values `Pagado`, `Vencida`, `En proceso`, and `Nota de crédito` before the invoice feature is usable in production.

#### Scenario: Required statuses exist

- GIVEN the customer payments feature is enabled
- WHEN an administrator or financial writer opens the invoice status selector
- THEN `Pagado`, `Vencida`, `En proceso`, and `Nota de crédito` are available as selectable catalog values.

### Requirement: REQ-STAT-03 — Catalog administration remains in Catálogos

The system MUST keep invoice status administration in the Catálogos module. The **Pagos** card MUST NOT create, rename, activate, deactivate, or delete invoice status values directly.

#### Scenario: Missing status cannot be created from payment card

- GIVEN an expected invoice status is missing from Catálogos
- WHEN a financial writer opens the **Pagos** card or invoice form
- THEN the system does not offer inline catalog creation and SHOULD direct an authorized administrator to Catálogos.

### Requirement: REQ-STAT-04 — Pagado is paid and non-alertable

The system MUST treat invoices with status `Pagado` as paid. `Pagado` invoices MUST NOT be presented as active due-date payment alerts.

#### Scenario: Paid invoice has no active payment alert

- GIVEN an invoice status is changed to `Pagado`
- WHEN the calendar evaluates due-date payment alerts
- THEN the invoice is not shown as an active unpaid invoice alert.

### Requirement: REQ-STAT-05 — Nota de crédito is non-chargeable and non-alertable in v1

The system MUST treat `Nota de crédito` as non-chargeable and non-alertable for due-date payment alerts in v1. It MUST NOT be considered an unpaid invoice requiring collection alert behavior.

#### Scenario: Credit note status suppresses alert

- GIVEN an invoice has status `Nota de crédito` and a due date
- WHEN the calendar evaluates due-date payment alerts
- THEN no active unpaid invoice alert is shown for that invoice.

### Requirement: REQ-STAT-06 — Vencida is automatic and persisted for overdue chargeable invoices

The system MUST keep `Vencida` as an invoice status catalog value and MUST automatically persist an active chargeable invoice as `Vencida` by updating its stored `status_id` when its due date is past, its status is not `Pagado` or `Nota de crédito`, and the invoice has not been retired/anulled. This automatic behavior MUST NOT be display-only or only an effective computed status.

#### Scenario: En proceso past due becomes persisted Vencida automatically

- GIVEN an active invoice has status `En proceso`, due date yesterday, and is not retired/anulled
- WHEN the automatic overdue process evaluates invoice status
- THEN the invoice `status_id` is persisted as the catalog value `Vencida` without requiring a manual user status change.

#### Scenario: Persisted status is used for display and alerts

- GIVEN an eligible overdue invoice has already been processed automatically
- WHEN the system evaluates invoice status for customer display or calendar alert behavior
- THEN the system uses the invoice's persisted `Vencida` status.

#### Scenario: Retired overdue invoice is not automatically persisted as Vencida

- GIVEN an invoice has status `En proceso`, due date yesterday, and has been retired/anulled through the approved non-destructive flow
- WHEN the automatic overdue process evaluates invoice status
- THEN the invoice `status_id` is not changed to `Vencida`.

### Requirement: REQ-STAT-07 — Paid and credit-note statuses block automatic Vencida

The system MUST NOT automatically transition or update invoices with status `Pagado` or `Nota de crédito` to `Vencida`, regardless of due date.

#### Scenario: Pagado past due remains non-overdue for collection

- GIVEN an invoice has status `Pagado` and due date yesterday
- WHEN the system evaluates automatic overdue behavior
- THEN the invoice `status_id` is not changed to `Vencida`.

#### Scenario: Nota de crédito past due remains non-chargeable

- GIVEN an invoice has status `Nota de crédito` and due date yesterday
- WHEN the system evaluates automatic overdue behavior
- THEN the invoice `status_id` is not changed to `Vencida`.

---

## Cross-references

- Proposal §5, §8, §12, §15.
- Assumptions: `Nota de crédito` non-cobrable/non-alertable; `Vencida` is automatic and persisted in BD for overdue active chargeable invoices; design will decide the persistence mechanism.
