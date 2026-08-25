// SweetAlert2 helpers — auto-attached via data-* attributes.
//
// Usage in Blade:
//   <button data-swal-confirm
//           data-swal-title="¿Eliminar?"
//           data-swal-text="No se puede deshacer"
//           data-swal-type="warning">Eliminar</button>
//
//   <button data-swal-confirm
//           data-swal-input="textarea"
//           data-swal-input-name="reason"
//           data-swal-input-label="Motivo"
//           data-swal-input-required="true">Desactivar</button>
//
//   <form data-swal-form data-swal-loading>...</form>
//
// Toasts from server flash messages are emitted via <x-swal-toast> rendered
// by the layout; see resources/views/components/swal-toast.blade.php.

import Swal from "sweetalert2";

const DEFAULT_OPTIONS = {
    title: "¿Confirmar acción?",
    text: "Esta acción no se puede deshacer.",
    icon: "warning",
    confirmButtonText: "Sí, continuar",
    cancelButtonText: "Cancelar",
    reverseButtons: true,
    focusCancel: true,
};

/**
 * Navigate to a URL only when it's safe: same origin and no JS/data URIs.
 * Defense-in-depth against open-redirect via data-* attributes.
 */
function safeNavigate(url) {
    if (!url) {
        return;
    }
    if (/^\s*(javascript|data|vbscript):/i.test(url)) {
        return;
    }
    let resolved;
    try {
        resolved = new URL(url, window.location.origin);
    } catch {
        return;
    }
    if (resolved.origin !== window.location.origin) {
        return;
    }
    // Only same-origin relative URLs reach this point — safe to navigate.
    window.location.assign(resolved.toString());
}

function buildSwalOptions(trigger) {
    const options = {
        title: trigger.dataset.swalTitle || DEFAULT_OPTIONS.title,
        text: trigger.dataset.swalText || DEFAULT_OPTIONS.text,
        icon: trigger.dataset.swalType || DEFAULT_OPTIONS.icon,
        confirmButtonText:
            trigger.dataset.swalConfirmText ||
            DEFAULT_OPTIONS.confirmButtonText,
        cancelButtonText:
            trigger.dataset.swalCancelText || DEFAULT_OPTIONS.cancelButtonText,
        reverseButtons: DEFAULT_OPTIONS.reverseButtons,
        focusCancel: DEFAULT_OPTIONS.focusCancel,
    };

    // Optional inline input (text or textarea) — the user fills it before
    // confirming. The value is written to a hidden field on the form so it
    // ends up in the submitted payload.
    if (trigger.dataset.swalInput) {
        const type = trigger.dataset.swalInput;
        options.input = type === "textarea" ? "textarea" : "text";
        options.inputPlaceholder = trigger.dataset.swalInputPlaceholder || "";
        options.inputValue = "";
        options.inputRequired = trigger.dataset.swalInputRequired === "true";
        options.inputLabel = trigger.dataset.swalInputLabel || undefined;
        options.inputAttributes = {
            "aria-label": trigger.dataset.swalInputLabel || "Motivo",
        };
        options.inputValidator = (value) => {
            if (options.inputRequired && !value.trim()) {
                return trigger.dataset.swalInputLabel
                    ? `El campo ${trigger.dataset.swalInputLabel.toLowerCase()} es obligatorio.`
                    : "Este campo es obligatorio.";
            }
            return null;
        };
    }

    return options;
}

/**
 * After confirmation, write the inline input value (if any) into a hidden
 * field on the form, then submit the form.
 */
function commitInputAndSubmit(trigger, form, result) {
    const inputName = trigger.dataset.swalInputName;
    if (inputName && result.value !== undefined) {
        // Remove any previous hidden for the same name, then append the new value.
        form.querySelectorAll(
            `input[type="hidden"][name="${CSS.escape(inputName)}"]`,
        ).forEach((el) => el.remove());
        const hidden = document.createElement("input");
        hidden.type = "hidden";
        hidden.name = inputName;
        hidden.value = result.value;
        form.appendChild(hidden);
    }

    if (typeof form.requestSubmit === "function") {
        const canUseTriggerAsSubmitter =
            trigger instanceof HTMLElement &&
            trigger.form === form &&
            (trigger.matches('button[type="submit"], input[type="submit"]') ||
                (trigger.tagName === "BUTTON" &&
                    !trigger.hasAttribute("type")));

        if (canUseTriggerAsSubmitter) {
            form.requestSubmit(trigger);
        } else {
            form.requestSubmit();
        }
    } else {
        form.submit();
    }
}

function attachConfirmHandlers() {
    document.addEventListener("click", (event) => {
        const trigger = event.target.closest("[data-swal-confirm]");
        if (!trigger) {
            return;
        }
        event.preventDefault();

        const form = trigger.closest("form");

        Swal.fire(buildSwalOptions(trigger)).then((result) => {
            if (!result.isConfirmed) {
                return;
            }

            const targetSelector = trigger.dataset.formTarget;
            const target = targetSelector
                ? document.querySelector(targetSelector)
                : form;

            if (!target) {
                if (targetSelector) {
                    safeNavigate(targetSelector);
                }
                return;
            }

            if (target.tagName === "FORM") {
                commitInputAndSubmit(trigger, target, result);
            } else {
                safeNavigate(target.dataset.href || targetSelector);
            }
        });
    });
}

/**
 * On submit of any <form data-swal-loading>, show a loading overlay so the
 * user knows the request is in flight. Disables the submit button to prevent
 * double submission.
 */
function attachLoadingHandlers() {
    document.addEventListener("submit", (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        if (!form.matches("[data-swal-loading]")) {
            return;
        }
        // Disable all submit buttons inside the form to prevent double-submit.
        form.querySelectorAll(
            'button[type="submit"], input[type="submit"]',
        ).forEach((btn) => {
            btn.disabled = true;
        });

        Swal.fire({
            title: "Procesando…",
            allowOutsideClick: false,
            allowEscapeKey: false,
            didOpen: () => {
                Swal.showLoading();
            },
        });
    });
}

/**
 * Render any pending toasts declared via <x-swal-toast> on page load.
 */
function renderPendingToasts() {
    document.querySelectorAll("[data-swal-toast]").forEach((node) => {
        const icon = node.dataset.swalType || "info";
        const message = node.dataset.swalMessage || "";
        const timer = parseInt(node.dataset.swalTimer || "3500", 10);
        Swal.fire({
            toast: true,
            position: "top-end",
            icon,
            title: message,
            showConfirmButton: false,
            timer,
            timerProgressBar: true,
        });
        node.remove();
    });
}

/**
 * Bootstrap 5.3 modal patch — works around the "_config is undefined"
 * error in `_initializeBackDrop`. The error fires during Modal
 * CONSTRUCTION (not after), so the previous-event-handler approach was
 * too late. Instead we monkey-patch `Modal.getOrCreateInstance` so it
 * always disposes any stale instance and creates a fresh one with proper
 * `_config`. This is the recommended workaround for this Bootstrap 5.3
 * regression when the DOM has been mutated (Livewire, Alpine, etc.).
 */
function attachBootstrapModalPatch() {
    const Modal = window.bootstrap && window.bootstrap.Modal;
    if (!Modal || typeof Modal.getOrCreateInstance !== "function") {
        return;
    }
    // Idempotent: avoid double-patching if init() runs twice (HMR).
    if (Modal.__patchedBySwalHelpers) {
        return;
    }
    Modal.__patchedBySwalHelpers = true;

    Modal.getOrCreateInstance = function patchedGetOrCreateInstance(
        element,
        config,
    ) {
        // Dispose any stale instance to avoid the broken _config.
        try {
            const stale = Modal.getInstance(element);
            if (stale) {
                stale.dispose();
            }
        } catch (e) {
            // best-effort
        }
        return new Modal(element, config);
    };
}

function init() {
    attachConfirmHandlers();
    attachLoadingHandlers();
    renderPendingToasts();
    attachBootstrapModalPatch();
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
} else {
    init();
}
