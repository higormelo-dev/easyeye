/**
 * medical-records.js — Page-level script for the medical record create/edit views.
 *
 * The main Alpine component (medicalRecordForm) is registered globally in app.js.
 * This file handles any page-specific jQuery/Bootstrap initializations.
 */
import "jquery";

$(function () {
    // Initialize Bootstrap tooltips on the form
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
        bootstrap.Tooltip.getOrCreateInstance(el);
    });
});
