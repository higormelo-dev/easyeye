/**
 * Register page — password visibility toggle.
 *
 * Handles both "password" and "confirm password" fields using the
 * shared password-toggle utility (data-toggle-password attribute).
 */
import { initPasswordToggles } from './password-toggle';

document.addEventListener('DOMContentLoaded', initPasswordToggles);

