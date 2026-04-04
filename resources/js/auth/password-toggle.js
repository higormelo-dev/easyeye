/**
 * Password visibility toggle — shared utility.
 *
 * Discovers every [data-toggle-password] button on the page and wires up
 * click handlers that toggle the linked <input> between type="password"
 * and type="text", swapping the icon accordingly.
 *
 * Usage (Blade):
 *   <button type="button" class="btn btn-outline-secondary"
 *           data-toggle-password="#my-input" tabindex="-1">
 *       <i class="ti ti-eye-off"></i>
 *   </button>
 */
export function initPasswordToggles() {
    document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const input = document.querySelector(btn.getAttribute('data-toggle-password'));
            const icon  = btn.querySelector('i');

            if (!input || !icon) return;

            const isHidden = input.type === 'password';

            input.type = isHidden ? 'text' : 'password';

            if (icon.classList.contains('mdi') || icon.className.includes('mdi-')) {
                icon.classList.toggle('mdi-eye', isHidden);
                icon.classList.toggle('mdi-eye-off', !isHidden);
                return;
            }

            icon.classList.toggle('ti-eye', isHidden);
            icon.classList.toggle('ti-eye-off', !isHidden);
        });
    });
}
