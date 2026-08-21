{{--
    B12-UI / PR 5 — Reusable test-mode badge component (HIST-05, UI-08).

    Renders the canonical purple "Modo test" badge when `$mode === 'test'`,
    and nothing otherwise. The exact tooltip text is mandated by spec AC-7
    and MUST stay byte-for-byte identical across views.

    Bootstrap 5 has no built-in `bg-purple` class, so we ship the badge with
    an inline `style="background:#6f42c1;color:#fff"` — spec-allowed.

    Usage:
        <x-test-mode-badge :mode="$rule->mode" />

    Props:
        - mode (string, required) — the rule's `mode` column (`'live'|'test'`).
        - extraClass (string, optional) — extra CSS classes (e.g. 'ms-1').
        - idPrefix (string, optional) — override the data-testid prefix.
--}}
@props([
    'mode' => 'live',
    'extraClass' => '',
    'idPrefix' => 'test-mode-badge',
])

@php
    $isTest = ($mode === 'test');
    $tooltip = 'Modo test: simuló, no ejecutó acciones reales';
    $badgeId = $idPrefix . '-' . substr(sha1($mode . '|' . $extraClass), 0, 8);
@endphp

@if ($isTest)
    <span id="{{ $badgeId }}"
          class="badge {{ trim('bg-purple ' . $extraClass) }}"
          title="{{ $tooltip }}"
          data-bs-toggle="tooltip"
          data-testid="test-mode-badge"
          style="background:#6f42c1;color:#fff;">
        Modo test
    </span>
@endif
