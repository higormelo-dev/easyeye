/**
 * Password visibility toggle — shared utility.
 *
 * Discovers every [data-toggle-password] button on the page and wires up
 * click handlers that toggle the linked <input> between type="password"
 * and type="text", swapping the MDI icon accordingly.
 *
 * Usage (Blade):
 *   <button type="button" class="btn btn-outline-secondary"
 *           data-toggle-password="#my-input" tabindex="-1">
 *       <i class="mdi mdi-eye-off"></i>
 *   </button>
 */
export function initPasswordToggles() {
    document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const input = document.querySelector(btn.getAttribute('data-toggle-password'));
            const icon  = btn.querySelector('.mdi');

            if (!input || !icon) return;

            const isHidden = input.type === 'password';

            input.type = isHidden ? 'text' : 'password';

            icon.classList.toggle('mdi-eye',     isHidden);
            icon.classList.toggle('mdi-eye-off', !isHidden);
        });
    });
}

