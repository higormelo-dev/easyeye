/**
 * Re-exports the global jQuery instance loaded via a static <script> tag.
 * This ensures that Vite-bundled plugins share the same jQuery instance
 * as the template scripts (sidebarmenu, waves, custom).
 *
 * REQUIREMENT: jQuery must be loaded as a static <script> before this module runs.
 * Since <script type="module"> is always deferred, this is guaranteed when jQuery
 * is placed at the bottom of <body> as a regular (non-module) script.
 */
export default window.jQuery;
