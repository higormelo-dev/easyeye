import { handleAjaxError, showSuccessToast, showErrorToast } from './auxiliary_functions.js';

$(function () {
    let record_id;

    const trans      = window.translations;
    const entityName = trans.actions.doctor;

    let doctorsDataTable = window.LaravelDataTables?.['doctors_datatable'];

    $('#doctors_datatable').on('draw.dt', function () {
        $('[data-bs-toggle="tooltip"]').tooltip();
        doctorsDataTable = doctorsDataTable ?? window.LaravelDataTables?.['doctors_datatable'];
        doctorsDataTable?.columns.adjust();
    });

    function reloadCurrentView() {
        const component = window.doctorViewComponent;
        if (component && component.view === 'cards') {
            component.fetchCards(component.meta.current_page);
        } else {
            doctorsDataTable?.ajax.reload();
        }
    }

    window.addEventListener('doctor-saved', () => reloadCurrentView());

    // Visualizar
    $(document).on('click', '.btn-show', function () {
        record_id = $(this).data('id');
        $('.modal-title-default').empty().append(trans.messages.view.replace(':name', entityName));
        $('#btn-modal-default').css('display', 'none');
        $('.modal-dialog').removeClass('modal-md modal-lg modal-xl').addClass('modal-lg');
        $('#erro-default').removeClass('show').css('display', 'none');
        $.ajax({
            url: 'doctors/' + record_id,
            headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
            success: function (data) {
                $('#retorno-default').empty().append(data);
                $('#modal_default').modal('show');
            },
            error: handleAjaxError
        });
    });

    // Editar — delega ao Alpine crudForm via evento nativo
    $(document).on('click', '.btn-edit', function () {
        const id = $(this).data('id');
        window.dispatchEvent(new CustomEvent('edit-doctor', { detail: { id } }));
    });

    // Ativar / Inativar
    $(document).on('click', '.btn-active', function () {
        record_id = $(this).attr('data-id');
        $.ajax({
            url: 'doctors/' + record_id,
            type: 'put',
            dataType: 'json',
            data: { 'type_method': 1, 'active': parseInt($(this).attr('data-situation'), 10) },
            headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
            success: function (response) {
                showSuccessToast(response.message);
                reloadCurrentView();
            },
            error: handleAjaxError
        });
    });

    // Deletar
    $(document).on('click', '.btn-trash', function () {
        record_id = $(this).data('id');
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
                    url:      'doctors/' + record_id,
                    dataType: 'json',
                    headers:  { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                    success: function (response) {
                        showSuccessToast(response.message);
                        reloadCurrentView();
                    },
                    error: function (data) { showErrorToast(data.responseJSON?.message); }
                });
            }
        });
    });
});
