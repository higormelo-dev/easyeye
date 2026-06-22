/**
 * Vendor bundle — third-party JS libraries managed via npm.
 *
 * NOTE: All vendor CSS has been moved to resources/css/vendor.css
 * (loaded as a proper <link> in <head> to avoid FOUC).
 *
 * Load order:
 *   1. jQuery (npm) → window.jQuery / window.$
 *   2. Bootstrap JS
 *   3. Raphael + Morris (charts)
 *   4. Theme script (preclinic-script)
 *   5. All other plugins (DataTables, SweetAlert2, Flatpickr, etc.)
 */

// ── jQuery (MUST be first — exposes window.$ / window.jQuery) ─────────────────
import './jquery-global.js';

// ── Bootstrap 5 JS ───────────────────────────────────────────────────────────
import * as bootstrap from 'bootstrap';
window.bootstrap = bootstrap;

// ── Raphael + Morris (dashboard charts) ───────────────────────────────────────
import Raphael from 'raphael';
window.Raphael = Raphael;
import 'morris.js/morris.js';

// ── DataTables + responsive extension ────────────────────────────────────────
// MUST come before preclinic-script.js (which checks for $.fn.DataTable)
import 'datatables.net-bs5';
import 'datatables.net-responsive-bs5';

// ── Theme JS (sidebar, menus) ─────────────────────────────────────────────────
// Note: preclinic-theme-script.js stays as a static <script> in <head> to avoid
// flash of wrong theme (it must run before HTML is painted).
import './preclinic-script.js';

// ── SweetAlert2 ───────────────────────────────────────────────────────────────
import Swal from 'sweetalert2';
window.Swal = Swal;

// ── jQuery plugins ────────────────────────────────────────────────────────────
import 'jquery-toast-plugin';
import 'jquery-sparkline';

// ── Flatpickr (Alpine-friendly datepicker) ────────────────────────────────────
import flatpickr from 'flatpickr';
import { Portuguese } from 'flatpickr/dist/l10n/pt.js';
flatpickr.localize(Portuguese);
window.flatpickr = flatpickr;

// ── InputMask (jQuery bridge auto-registered via side-effect import) ──────────
import Inputmask from 'inputmask';
window.Inputmask = Inputmask;

// ── Feather Icons ─────────────────────────────────────────────────────────────
import feather from 'feather-icons';
window.feather = feather;

// ── Simplebar (custom scrollbar) ──────────────────────────────────────────────
import SimpleBar from 'simplebar';
window.SimpleBar = SimpleBar;
