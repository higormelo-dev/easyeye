import "jquery";
import { handleAjaxError, showSuccessToast, showErrorToast } from './auxiliary_functions.js';

/**
 * Initializes common DataTable CRUD handlers for a setting entity.
 * @param {string} tableId  - e.g. 'skintypes_datatable'
 * @param {string} prefix   - e.g. 'skintypes' (relative URL prefix)
 */
export function initSettingDatatable({ tableId, prefix }) {
    let dt = window.LaravelDataTables?.[tableId];

    $(`#${tableId}`).on('draw.dt', function () {
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
            bootstrap.Tooltip.getOrCreateInstance(el);
        });
        dt = dt ?? window.LaravelDataTables?.[tableId];
    });

    const trans = window.translations;

    // Edit — delegate to Alpine crudForm via custom event
    $(document).on('click', '.btn-edit', function () {
        const id = $(this).data('id');
        window.dispatchEvent(new CustomEvent('edit-setting', { detail: { id } }));
    });

    // Toggle active/inactive
    $(document).on('click', '.btn-active', function () {
        const id = $(this).data('id');
        $.ajax({
            url: `${prefix}/${id}`,
            type: 'put',
            dataType: 'json',
            data: { active: $(this).data('situation') },
            headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
            success: function (response) {
                showSuccessToast(response.message);
                dt?.ajax.reload();
            },
            error: handleAjaxError,
        });
    });

    // Delete
    $(document).on('click', '.btn-trash', function () {
        const id = $(this).data('id');
        Swal.fire({
            title:              trans.messages.delete_confirm_title,
            text:               trans.messages.delete_confirm_text,
            icon:               'warning',
            showCancelButton:   true,
            confirmButtonColor: '#dc3545',
            confirmButtonText:  trans.messages.confirm_yes,
            cancelButtonText:   trans.messages.confirm_no,
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    method:   'delete',
                    url:      `${prefix}/${id}`,
                    dataType: 'json',
                    headers:  { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                    success: function (response) {
                        showSuccessToast(response.message);
                        dt?.ajax.reload();
                    },
                    error: function (data) { showErrorToast(data.responseJSON?.message); },
                });
            }
        });
    });

    // Restore
    $(document).on('click', '.btn-restore', function () {
        const id = $(this).data('id');
        Swal.fire({
            title:             trans.messages.restore_confirm_title,
            text:              trans.messages.restore_confirm_text,
            icon:              'warning',
            showCancelButton:  true,
            confirmButtonText: trans.messages.confirm_yes,
            cancelButtonText:  trans.messages.confirm_no,
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    method:   'get',
                    url:      `${prefix}/${id}/restore`,
                    dataType: 'json',
                    headers:  { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                    success: function (response) {
                        showSuccessToast(response.message);
                        dt?.ajax.reload();
                    },
                    error: function (data) { showErrorToast(data.responseJSON?.message); },
                });
            }
        });
    });

    // Reload after successful save via Alpine crudForm
    window.addEventListener('setting-saved', () => dt?.ajax.reload());
}
