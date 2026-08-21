# Customer Invoices Specification

> Change: `customer-payments-invoices`. Upstream: `openspec/changes/customer-payments-invoices/proposal.md` and first-slice assumptions approved for spec.
> Scope: v1 manual invoice records associated to customers. Status catalog and calendar alert behavior are specified separately.

---

## Purpose

Define the v1 contract for manually registering, editing, viewing, and non-destructively retiring customer invoices from the customer detail **Pagos** card.

---

## Requirements

### Requirement: REQ-INV-01 — Invoice belongs to exactly one customer

The system MUST persist every invoice against exactly one customer. The system MUST NOT allow an invoice record without a customer association in v1.

#### Scenario: Invoice is created from customer detail

- GIVEN an authorized financial writer is viewing customer `C`
- WHEN they create an invoice from the **Pagos** card
- THEN the invoice is associated to customer `C` and appears only in that customer's invoice list.

#### Scenario: Missing customer is rejected

- GIVEN an invoice create request has no customer association
- WHEN the system validates the request
- THEN the request is rejected and no orphan invoice is created.

### Requirement: REQ-INV-02 — Minimum invoice fields

The system MUST store, at minimum, a visible invoice identifier/number, due date, total amount, catalog status, customer association, timestamps, and responsible user when the project's existing patterns support actor tracking. Minimal notes MAY be stored when provided.

#### Scenario: Valid invoice is saved

- GIVEN an authorized financial writer enters identifier `FAC-001`, due date `2026-09-15`, total amount `1500.00`, and status `En proceso`
- WHEN they save the invoice
- THEN the invoice is persisted with those values and is visible in the customer's **Pagos** card.

### Requirement: REQ-INV-03 — Due date is required for alertable invoices

The system MUST require a due date for normal chargeable invoices in v1. If future implementation allows a non-alertable invoice without due date, it MUST NOT create a calendar alert for that record.

#### Scenario: Chargeable invoice without due date is rejected

- GIVEN an authorized financial writer creates a normal invoice without due date
- WHEN they submit the form
- THEN the system rejects the invoice and explains that due date is required.

### Requirement: REQ-INV-04 — Total amount is positive in v1

The system MUST accept only positive total amounts for v1 invoices. The system MUST NOT implement negative invoices, line items, tax breakdowns, discounts, retentions, multiple currencies, partial payments, or remaining balances in this slice.

#### Scenario: Positive amount is accepted

- GIVEN an invoice total amount is `1.00` or higher
- WHEN the invoice is saved with all required fields
- THEN the system accepts the amount as the invoice total.

#### Scenario: Zero or negative amount is rejected

- GIVEN an invoice total amount is `0`, empty, or negative
- WHEN the invoice is submitted
- THEN the system rejects the value and no invoice is created or updated with that amount.

### Requirement: REQ-INV-05 — Invoice status comes from catalog

The system MUST require each invoice status to be selected from the invoice status catalog. The system MUST NOT accept free-text statuses outside the configured catalog values.

#### Scenario: Catalog status is accepted

- GIVEN `En proceso` exists in the invoice status catalog
- WHEN an authorized financial writer saves an invoice with status `En proceso`
- THEN the invoice stores that catalog-backed status.

#### Scenario: Free-text status is rejected

- GIVEN a request submits status `Pendiente especial` that is not in the catalog
- WHEN the invoice is validated
- THEN the request is rejected and the invalid status is not persisted.

### Requirement: REQ-INV-06 — Marking paid changes status only

The system MUST support marking an invoice as paid by changing only its status to `Pagado` in v1. The system MUST NOT require payment date, reference, proof, bank account, reconciliation, or payment allocation metadata.

#### Scenario: Invoice is marked paid

- GIVEN an invoice is `En proceso`
- WHEN an authorized financial writer marks it as paid
- THEN the invoice status becomes `Pagado` and no payment metadata is required.

### Requirement: REQ-INV-07 — Automatic overdue persistence

The system MUST automatically persist an active chargeable invoice as `Vencida` by updating its stored `status_id` to the catalog value `Vencida` when its due date is past, its current status is neither `Pagado` nor `Nota de crédito`, and the invoice has not been retired/anulled. This behavior MUST result in a database state change for eligible invoices and MUST NOT be only a computed/display-only effective status.

#### Scenario: Active chargeable invoice passes due date

- GIVEN an active invoice has status `En proceso`, due date yesterday, and is not retired/anulled
- WHEN the automatic overdue process evaluates eligible invoices
- THEN the system persists the invoice `status_id` as `Vencida` without requiring a manual user status change.

#### Scenario: Persisted Vencida appears in payment card and calendar

- GIVEN an eligible overdue invoice has been processed automatically
- WHEN the invoice is shown in the **Pagos** card or evaluated for calendar behavior
- THEN the system reads the persisted `Vencida` status from the invoice record.

#### Scenario: Paid invoice past due is protected

- GIVEN an active invoice has status `Pagado` and due date yesterday
- WHEN automatic overdue behavior is evaluated
- THEN the invoice remains non-alertable and MUST NOT be updated as `Vencida`.

#### Scenario: Credit-note invoice past due is protected

- GIVEN an active invoice has status `Nota de crédito` and due date yesterday
- WHEN automatic overdue behavior is evaluated
- THEN the invoice remains non-chargeable/non-alertable and MUST NOT be updated as `Vencida`.

#### Scenario: Retired/anulled invoice past due is protected

- GIVEN an invoice has status `En proceso`, due date yesterday, and has been retired/anulled through the approved non-destructive flow
- WHEN automatic overdue behavior is evaluated
- THEN the invoice MUST NOT be updated as `Vencida`.

### Requirement: REQ-INV-08 — Invoice writes require explicit financial write permission

The system MUST require explicit financial write permission to create invoices, edit invoice fields, change invoice status, or retire/anul invoice records. Users with read permission only MUST NOT perform these actions.

#### Scenario: Writer edits invoice

- GIVEN a user has financial write permission
- WHEN they update an invoice due date or status
- THEN the system persists the change and refreshes customer/card/calendar behavior accordingly.

#### Scenario: Read-only user cannot edit invoice

- GIVEN a user has financial read permission only
- WHEN they attempt to submit an invoice create, edit, status-change, or retire action
- THEN the system rejects the action and the invoice remains unchanged.

### Requirement: REQ-INV-09 — Normal UI does not hard-delete invoices

The system MUST NOT physically delete invoices from normal v1 UI flows. User-facing removal MUST use the project's existing annul, inactive, credit-note, or equivalent non-destructive convention so auditability and calendar cleanup can be preserved.

#### Scenario: Invoice loaded by mistake is retired non-destructively

- GIVEN an authorized financial writer needs to remove a mistaken invoice from active handling
- WHEN they use the available v1 removal action
- THEN the invoice is no longer treated as an active chargeable invoice, but the record is not hard-deleted by the normal UI.

### Requirement: REQ-INV-10 — Invoice changes are traceable when project auditing applies

The system SHOULD record creation, edits, status changes, automatic overdue transitions, and non-destructive retire/anul actions using the project's audit pattern when that pattern is available for comparable customer-sensitive records.

#### Scenario: Status changes to Pagado

- GIVEN the project audit mechanism is enabled for customer financial records
- WHEN an authorized user changes an invoice status to `Pagado`
- THEN the actor, timestamp, invoice, customer, and changed status are traceable.

#### Scenario: Automatic overdue transition is audited as system action

- GIVEN the project audit mechanism is enabled for customer financial records
- WHEN the automatic overdue process persists an eligible invoice as `Vencida`
- THEN the invoice, customer, old status, new status, timestamp, and system actor/action are traceable without duplicating audit entries on repeated processing.

---

## Cross-references

- Proposal §4, §5, §8, §10, §11, §12, §15.
- Assumptions: no hard delete in normal UI; paid status changes status only; write actions require explicit permission; `Vencida` is automatic and persisted in BD for overdue active chargeable invoices through the mechanism chosen in design.
