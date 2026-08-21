# Customer Payments Card Specification

> Change: `customer-payments-invoices`. Upstream: `openspec/changes/customer-payments-invoices/proposal.md` and first-slice assumptions approved for spec.
> Scope: customer detail card, customer-level payment modality, financial visibility and write permissions. Invoice records, status catalog, and calendar alerts are specified separately.

---

## Purpose

Define the v1 customer detail experience for viewing payment context and customer invoices without turning the CRM into a full financial module.

---

## Requirements

### Requirement: REQ-PAYCARD-01 — Tarjeta Pagos in customer detail

The system MUST show a **Pagos** card in `/customers/{id}` for users with explicit financial read permission. The card MUST coexist with existing customer cards and MUST NOT degrade Datos del cliente, Contactos, Historial comercial, Actividades, Cotizaciones, or Documentos.

#### Scenario: Authorized user sees the card

- GIVEN a user with customer access and financial read permission
- WHEN they open `/customers/{id}`
- THEN the **Pagos** card is visible with customer payment modality and invoice summary/list content.

#### Scenario: User without financial read permission does not see sensitive financial data

- GIVEN a user can open the customer detail but lacks financial read permission
- WHEN they open `/customers/{id}`
- THEN the **Pagos** card and invoice amounts/statuses MUST be hidden or replaced by an authorization-safe state.

### Requirement: REQ-PAYCARD-02 — Customer-level payment modality

The system MUST store and display payment modality as a simple customer-level text/value in v1. The value MUST NOT be modeled as a separate catalog and MUST NOT be required to create invoices.

#### Scenario: Customer has payment modality

- GIVEN a customer has payment modality set to `Transferencia`
- WHEN an authorized user views the **Pagos** card
- THEN the card shows `Transferencia` as customer-level context, not as a per-invoice field.

#### Scenario: Customer has no payment modality

- GIVEN a customer has no payment modality value
- WHEN an authorized user views the **Pagos** card
- THEN the card shows a clear empty/pending modality state and still allows invoice viewing and permitted invoice creation.

### Requirement: REQ-PAYCARD-03 — Payment modality editing is permission-gated

The system MUST require explicit financial write permission to create, update, or clear the customer payment modality. Read-only financial users MUST NOT be able to modify it.

#### Scenario: Writer updates modality

- GIVEN a user has financial write permission for customers
- WHEN they save a new payment modality value
- THEN the customer's modality is updated and shown on the next card render.

#### Scenario: Read-only user cannot edit modality

- GIVEN a user has financial read permission but not financial write permission
- WHEN they view the **Pagos** card
- THEN modality edit controls are unavailable or blocked, and submitted edits MUST be rejected.

### Requirement: REQ-PAYCARD-04 — Invoice list summary inside the card

The system MUST show the customer's invoices in the **Pagos** card with at least visible invoice identifier, due date, total amount, status, and action affordances permitted for the current user.

#### Scenario: Customer with invoices

- GIVEN a customer has invoices with different statuses and due dates
- WHEN an authorized user views the **Pagos** card
- THEN each invoice row shows identifier, due date, total amount, and catalog status.

#### Scenario: Overdue chargeable invoice is persisted and shown as Vencida

- GIVEN a customer has an active invoice with status `En proceso`, due date yesterday, and no retire/anul state
- WHEN the automatic overdue process runs before or as part of the supported financial workflow
- THEN the invoice's persisted `status_id` is updated to the catalog value `Vencida`
- AND an authorized user viewing the **Pagos** card sees `Vencida` from persisted invoice status.

#### Scenario: Customer without invoices

- GIVEN a customer has no invoices
- WHEN an authorized user views the **Pagos** card
- THEN the card shows a useful empty state, and the create CTA appears only when the user has financial write permission.

### Requirement: REQ-PAYCARD-05 — V1 scope boundaries are visible

The system MUST keep v1 payment UI limited to customer modality, manual invoices, total amount, status, due date, and minimal notes. The system MUST NOT present controls for partial payments, payment references, payment evidence, reconciliation, tax breakdowns, invoice lines, automatic invoice issuance, or accounting integrations in this slice.

#### Scenario: User opens invoice controls

- GIVEN an authorized financial writer opens invoice create or edit controls from the **Pagos** card
- WHEN the form is rendered
- THEN no out-of-scope fields for partial payment, reconciliation, tax lines, references, proof files, or automatic issuance are shown.

---

## Cross-references

- Proposal §4, §5, §7, §10, §15.
- `../customer-invoices/spec.md` covers invoice data and lifecycle.
- `../invoice-status-catalog/spec.md` covers allowed statuses.
- `../invoice-calendar-alerts/spec.md` covers due-date alerts.
