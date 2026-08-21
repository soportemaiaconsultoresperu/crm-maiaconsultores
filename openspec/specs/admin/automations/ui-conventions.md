# Admin Automations — UI Conventions

> Module slice of `b12-ui`. Upstream: `openspec/changes/b12-ui/explore.md` (§2.4 layout + AdminLTE/Bootstrap 5 components, §6 Livewire 4 conventions) and `openspec/changes/b12-ui/proposal.md` (§7 visual contract references, §8 No-goals: no breadcrumbs / no bulk ops / no i18n stack).
> Pair with: every other spec — this is the visual + structural hygiene contract they all conform to.

---

## Purpose

Pin the UI conventions that B12-UI MUST respect so the new Livewire components, modals, tables, and badges match the AdminLTE 4 + Bootstrap 5 visual language already used by the B08 admin module (roles, audit, settings) without dragging in a parallel design system or a new navigation backbone.

---

## Requirements

### REQ-UI-01 — Layout and @yields

Every B12-UI Blade view SHALL extend `resources/views/layouts/app.blade.php` and SHALL `@yield('title')`, `@yield('page-title')`, `@yield('content')` plus `@stack('scripts')` at the bottom. Livewire full-page components SHALL use `#[Layout('layouts.app')]` (explore §8.15) so the same layout is reused without a second `extend`.

### REQ-UI-02 — AdminLTE/Bootstrap 5 component vocabulary

The system SHALL prefer the existing `resources/views/components/*.blade.php` primitives over raw HTML: `<x-table>` for tables (with `@slot('headers'|'rows'|'filters'|'pagination')`), `<x-text-input>` for `<input>`, `<x-select>` (with `options` assoc array) for `<select>`, `<x-modal>` for modals (no jQuery, `data-bs-*` toggles), `<x-alert type="success|error|warning|info">`, `<x-label>`, `<x-badge-status>`, and `<x-validation-error>` for inline form errors. No raw `<table class="table">` / `<input class="form-control">` markup is introduced unless the component primitive doesn't already cover it.

### REQ-UI-03 — Empty states

Every list (rules, trash, conditions, actions, executions, audit, cycle-breaks) SHALL render `@include('layouts.partials.empty-state', ['message' => '...'])` when the underlying collection is empty, with CTAs gated by `automations.manage` (or `automations.audit` for the audit block). Three distinct empty-state messages are required at minimum (proposal §9.1/§9.2/§9.3): "Aún no hay reglas", "Aún no hay ejecuciones registradas para esta regla", "No hay reglas en papelera".

### REQ-UI-04 — Sidebar + breadcrumbs (no new backbone)

The system SHALL NOT modify `resources/views/layouts/partials/sidebar.blade.php` for v1 — the `Automatizaciones` entry already lives at line ~92 inside the `$adminPerms` block under `@can('automations.view')` (explore §2.4). The system SHALL NOT introduce a breadcrumbs mechanism; `partials/breadcrumbs.blade.php` is left untouched and unused (explore §8.14). The rule's `show` page navigates via the page header back-link + the table link to executions — no breadcrumb driver.

### REQ-UI-05 — Livewire 4 component style

Livewire 4 components introduced by B12-UI (CRUD form, rule editor for conditions/actions, simulate modal, audit feed, reorder, papelera) SHALL be class-based, declared under `app/Livewire/Admin/Automations/`, SHALL use `#[Layout('layouts.app')]` on full-page components, `#[Computed]` for cached reads (`$triggers`, `$operators`, `$actionTypes`, `$visibleUsers`, `$visibleTeams`), and `#[On('event')]` for cross-component listeners. The system SHALL register `<livewire:…>` scripts via `@livewireStyles` and `@livewireScripts` already yielded by the layout (or add them to the layout if absent).

### REQ-UI-06 — Time zone

Every timestamp the UI displays (`updated_at`, `started_at`, `finished_at`, `detected_at`, `queued_at`) SHALL be formatted in `America/Lima` via a single Blade directive or helper (explore §8.12). No raw `Carbon::now()->format(...)` with a different TZ is introduced.

### REQ-UI-07 — Monospace + clipboard contract

Diagnostics strings (`idempotency_key`, simulated `response_json` payloads, error class names, headers / variables pairs in webhook & WhatsApp widgets) SHALL render inside `<code class="font-monospace user-select-all">` blocks. Clipboard actions use `navigator.clipboard.writeText(...)` and show a 2-second toast via `<x-badge-status variant="success">Copiado</x-badge-status>`. No custom clipboard libraries are introduced.

### REQ-UI-08 — Test-mode badge styling

The "Modo test" badge SHALL use Bootstrap purple (`bg-purple`/`text-bg-purple`) with `title="Modo test: simuló, no ejecutó acciones reales"` and `data-bs-toggle="tooltip"` so hover shows the literal copy (proposal §10 #6 + AC-7). The badge SHALL appear in the index, the rule's `show`, each execution row, and the execution detail header.

### REQ-UI-09 — Stub marker

Any rule with a `webhook` or `send_whatsapp_template` action SHALL carry a small gray pill "B14 stub" next to its name in the index (proposal §9.12). The form widget for those types SHALL additionally render the "Pendiente (B14) — la acción fallará con `NotImplementedException` hasta B14" banner above the inputs.

### REQ-UI-10 — No bulk-ops buttons (AC-12)

The index SHALL NOT render any checkbox column, no "Activar todos", "Desactivar todos", "Eliminar seleccionados", or "Reordenar en masa" button. Grep across the B12-UI views for those phrases MUST return zero matches (proposal §8 + AC-12).

### REQ-UI-11 — No `retry_policy_json` field (AC-10)

Zero Blade field names SHALL contain `retry_policy`. The B12-UI views MUST NOT render any input bound to the `retry_policy_json` column (proposal §10 #7 + AC-10). The engine ignores the column anyway.

### REQ-UI-12 — Spanish copy, no i18n stack

Status badges, action type labels, helper text, and CTA buttons SHALL be Spanish (e.g. `Modo test: simuló, no ejecutó acciones reales`, `Pendiente (B14)`, `Crear primera regla`, `Restaurar`, `Copiar`, `Copiado`). The system SHALL NOT introduce a Laravel localization stack in v1; copy is hard-coded Spanish (proposal §8 last bullet, explore §7).

### REQ-UI-13 — Accessibility + semantics baseline

Modals triggered from Livewire SHALL set `aria-labelledby` to a stable id and SHALL trap focus within the `<x-modal>` slot. Tables SHALL keep `<thead>` + `<th scope="col">`. Color-only badges SHALL be paired with a text label (no "red dot alone"). All interactive controls SHALL be reachable via keyboard (`tabindex` defaults; no `pointer-events: none` shortcuts).

### REQ-UI-14 — Vite-asset alignment

Any new JS file that B12-UI introduces MUST be registered in `vite.config.js` (or its Laravel preset) and `@vite('resources/js/app.js')` is already loaded by the layout (explore §2.4). The system SHALL NOT introduce a parallel bundler nor a CDN script tag inside a partial.

---

## Scenarios

#### SCN-UI-01 — Livewire component extends layout

- GIVEN the rule editor Livewire class uses `#[Layout('layouts.app')]`
- WHEN the admin opens `admin.automations.create`
- THEN the rendered HTML contains the AdminLTE sidebar (with the `Automatizaciones` link active) and the page-title yield populated.

#### SCN-UI-02 — Empty state on rule index

- GIVEN zero `AutomationRule` rows
- WHEN `GET admin.automations.index`
- THEN the response contains `layouts.partials.empty-state` with message "Aún no hay reglas" and CTA gated by `@can('automations.manage')`.

#### SCN-UI-03 — Sidebar link unchanged

- GIVEN the diff between pre- and post-B12-UI `partials/sidebar.blade.php`
- WHEN the audit looks at lines 60–110
- THEN no additions or removals appear; the `Automatizaciones` link remains at ~line 92 under the `$adminPerms` block.

#### SCN-UI-04 — Timestamps normalized

- GIVEN an execution with `started_at = 2026-02-03 14:00:00 UTC`
- WHEN the admin opens the execution detail
- THEN the rendered text shows "2026-02-03 09:00" (Lima) — no UTC leaks.

#### SCN-UI-05 — No bulk-ops buttons rendered

- GIVEN the index view
- WHEN a regex sweep runs for `/(?:activar|desactivar|eliminar)\s+todos?|reordenar\s+en\s+masa/`
- THEN zero matches are returned (AC-12).

#### SCN-UI-06 — No `retry_policy` inputs rendered

- GIVEN grep across `resources/views/admin/automations/` + `resources/views/livewire/admin/automations/`
- WHEN the regex is `retry_policy`
- THEN zero matches are returned (AC-10).

#### SCN-UI-07 — Livewire 4 attributes used

- GIVEN the rule editor Livewire class
- WHEN the audit checks for `#[Layout`, `#[Computed`, `#[On`
- THEN at least one of each is present on a full-page component (matching explore §6 + §8.15).

---

## Cross-references

- Proposal: §7.10 (badge morado), §7.13 (audit contextual section), §8 (No-goals: bulk ops, retry hidden, no i18n, no breadcrumb), §9.1–§9.3 (empty states), §10 #6 + #7, AC-7 / AC-10 / AC-12.
- Explore: §2.4 (layout, components, sidebar line ~92), §6 (Livewire 4 conventions, `composer.json` pin `livewire/livewire: ^4.4`), §8.12 (America/Lima), §8.14 (no breadcrumb convention), §8.15 (Livewire 4 attribute style).
- Config: `openspec/config.yaml` — UI stack `AdminLTE, Bootstrap 5`, Livewire 4 in stack.
