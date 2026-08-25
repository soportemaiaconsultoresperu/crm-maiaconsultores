// CRM Maia Consultores — application bootstrap (ADR-010: no jQuery).
//
// AdminLTE 4 ships only its own widgets (sidebar, card, push-menu) in
// adminlte.min.js — it does NOT bundle the Bootstrap 5 JS plugins used by
// data-bs-* attributes (collapse, modal, dropdown, etc.). Bootstrap JS is
// installed transitively via AdminLTE's peer dependency, so we just import
// the bundle explicitly to wire data-bs-toggle="collapse" on the catalog
// editor row and any other Bootstrap-driven controls we add later.
//
// SweetAlert2: bundled globally for use via the swal-helpers module.
// All destructive confirmations and toast feedback go through it.

import "bootstrap/dist/js/bootstrap.bundle.min.js";
import "admin-lte/dist/js/adminlte.min.js";
import Swal from "sweetalert2";

import "./swal-helpers.js";
import "./calendar-navigation.js";

// Re-export for any other module that wants to use SweetAlert directly.
window.Swal = Swal;