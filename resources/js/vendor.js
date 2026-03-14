/**
 * Vendor bundle — third-party libraries managed via npm.
 *
 * Load order in app.blade.php:
 *   1. jQuery (static <script> at end of body) → window.jQuery / window.$
 *   2. Raphael + Morris (static, need window.jQuery before running)
 *   3. Theme scripts: sidebarmenu, waves, custom (static, use $(document).ready())
 *   4. This module (type="module", deferred) — runs before DOMContentLoaded
 *   5. DOMContentLoaded → jQuery ready callbacks (DataTable init, etc.)
 *
 * Note: jQuery, Raphael and Morris stay as static scripts because Morris requires
 * window.Raphael at parse-time and both execute before the deferred Vite module.
 */

// ── CSS ──────────────────────────────────────────────────────────────────────
import 'bootstrap/dist/css/bootstrap.min.css';
import 'datatables.net-bs5/css/dataTables.bootstrap5.min.css';
import 'datatables.net-responsive-bs5/css/responsive.bootstrap5.min.css';
import 'sweetalert2/dist/sweetalert2.min.css';
import 'jquery-toast-plugin/dist/jquery.toast.min.css';
import 'jquery-asColorPicker/dist/css/asColorPicker.css';
import 'flatpickr/dist/flatpickr.min.css';

// ── Bootstrap 5 JS (no jQuery dependency) ────────────────────────────────────
import * as bootstrap from 'bootstrap';
window.bootstrap = bootstrap;

// ── DataTables + responsive extension ────────────────────────────────────────
// These use window.jQuery (via the jquery-global.js alias in vite.config.js)
import 'datatables.net-bs5';
import 'datatables.net-responsive-bs5';

// ── SweetAlert2 ───────────────────────────────────────────────────────────────
import Swal from 'sweetalert2';
window.Swal = Swal;

// ── jQuery plugins ────────────────────────────────────────────────────────────
import 'jquery-toast-plugin';
import 'jquery-sparkline';
import 'jquery-asColorPicker';

// ── Flatpickr (Alpine-friendly datepicker) ────────────────────────────────────
import flatpickr from 'flatpickr';
import { Portuguese } from 'flatpickr/dist/l10n/pt.js';
flatpickr.localize(Portuguese);
window.flatpickr = flatpickr;

// ── InputMask (jQuery bridge auto-registered via side-effect import) ──────────
import Inputmask from 'inputmask';
window.Inputmask = Inputmask;
