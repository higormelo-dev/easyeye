import { handleAjaxError, showSuccessToast, showErrorToast } from './auxiliary_functions.js';

$(function () {
    let record_id, btn_action;

    const trans = window.translations;
    const entityName = trans.actions.patient;

    // Obtém a instância do DataTable criada pelo Laravel DataTables
    let patientsDataTable = window.LaravelDataTables?.['patients_datatable'];

    // Após cada redraw do DataTable: tooltips + ajuste de colunas.
    // columns.adjust() aqui garante que a tabela já está visível e com dados
    // renderizados — resolve o problema de colunas bagunçadas ao voltar da view cards.
    $('#patients_datatable').on('draw.dt', function () {
        $('[data-bs-toggle="tooltip"]').tooltip();
        patientsDataTable = patientsDataTable ?? window.LaravelDataTables?.['patients_datatable'];
        patientsDataTable?.columns.adjust();
    });

    /**
     * Recarrega a view ativa (tabela ou cards).
     * Usado após criar, editar, excluir ou alterar status.
     */
    function reloadCurrentView() {
        const component = window.patientViewComponent;
        if (component && component.view === 'cards') {
            component.fetchCards(component.meta.current_page);
        } else {
            patientsDataTable?.ajax.reload();
        }
    }

    // ----------------------------------------------------------------
    // Handlers via document delegation — funcionam na tabela E nos cards
    // ----------------------------------------------------------------

    // Visualizar
    $(document).on('click', '.btn-show', function () {
        record_id = $(this).data('id');
        $('.modal-title-default').empty().append(trans.messages.view.replace(':name', entityName));
        $('#btn-modal-default').css('display', 'none');
        $('.modal-dialog').removeClass('modal-md modal-lg modal-xl').addClass('modal-lg');
        $("#erro-default").removeClass('show').css('display', 'none');
        $.ajax({
            url: `patients/${record_id}`,
            headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
            success: function (data) {
                $('#retorno-default').empty().append(data);
                $('#modal_default').modal('show');
            },
            error: handleAjaxError
        });
    });

    // Editar
    $(document).on('click', '.btn-edit', function () {
        record_id = $(this).data('id');
        btn_action = 'update';
        $('.modal-title-default').empty().append(trans.messages.edit.replace(':name', entityName));
        $('#btn-modal-default').css('display', 'block');
        $('.modal-dialog').removeClass('modal-md modal-lg modal-xl').addClass('modal-xl');
        $('#btn-modal-default').attr('data-action', 'register').removeAttr('data-id');
        $("#erro-default").removeClass('show').css('display', 'none');
        $.ajax({
            url: `patients/${record_id}/edit`,
            type: 'get',
            headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
            beforeSend: function () {
                $('#btn-modal-default').attr('disabled', false);
                $("#erro-default").removeClass('show').css('display', 'none');
                $("#erro-msg-default").empty();
            },
            success: function (response) {
                $('#retorno-default').empty().append(response);
                $('#modal_default').modal('show');
                setTimeout(initModalEvents, 100);
            },
            error: handleAjaxError
        });
    });

    // Ativar / Inativar
    $(document).on('click', '.btn-active', function () {
        record_id = $(this).data('id');
        $.ajax({
            url: `patients/${record_id}`,
            type: 'put',
            dataType: 'json',
            data: {
                'type_method': 1,
                'active': $(this).data('situation')
            },
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
            title: trans.messages.delete_confirm_title,
            text: trans.messages.delete_confirm_text,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: trans.messages.confirm_yes,
            cancelButtonText: trans.messages.confirm_no
        }).then((result) => {
            if (result.value) {
                $.ajax({
                    method: 'delete',
                    url: `patients/${record_id}`,
                    dataType: 'json',
                    headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                    success: function (response) {
                        showSuccessToast(response.message);
                        reloadCurrentView();
                    },
                    error: function (data) {
                        showErrorToast(data.responseJSON?.message);
                    }
                });
            }
        });
    });

    // Restaurar
    $(document).on('click', '.btn-restore', function () {
        record_id = $(this).data('id');
        Swal.fire({
            title: trans.messages.restore_confirm_title,
            text: trans.messages.restore_confirm_text,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: trans.messages.confirm_yes,
            cancelButtonText: trans.messages.confirm_no
        }).then((result) => {
            if (result.value) {
                $.ajax({
                    method: 'get',
                    url: `patients/${record_id}/restore`,
                    dataType: 'json',
                    headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                    success: function (response) {
                        showSuccessToast(response.message);
                        reloadCurrentView();
                    },
                    error: function (data) {
                        showErrorToast(data.responseJSON?.message);
                    }
                });
            }
        });
    });

    // ----------------------------------------------------------------
    // Novo registro (modal)
    // ----------------------------------------------------------------
    $('.new-register').on('click', function () {
        btn_action = 'store';
        $('.modal-title-default').empty().append(trans.messages.register.replace(':name', entityName));
        $('#btn-modal-default').css('display', 'block');
        $('.modal-dialog').removeClass('modal-md modal-lg modal-xl').addClass('modal-xl');
        $('#btn-modal-default').attr('data-action', 'register').removeAttr('data-id');
        $.ajax({
            url: 'patients/create',
            type: 'get',
            headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
            beforeSend: function () {
                $('#btn-modal-default').attr('disabled', true);
                $("#erro-default").removeClass('show').css('display', 'none');
                $("#erro-msg-default").empty();
            },
            complete: function () {
                $('#btn-modal-default').attr('disabled', false);
            },
            success: function (response) {
                $('#retorno-default').empty().append(response);
                $('#modal_default').modal('show');
                setTimeout(initModalEvents, 100);
            },
            error: handleAjaxError
        });
    });

    // Salvar (criar ou atualizar) via modal
    $('#btn-modal-default').on('click', function () {
        const requestType = btn_action === 'store' ? 'post' : 'put';
        const requestURL  = btn_action === 'store' ? 'patients' : `patients/${record_id}`;
        const requestData = {
            category: $('select[name=category]').val(),
            name: $('input[name=name]').val(),
        };

        if (requestType === 'put') {
            requestData.active = $('select[name=active]').val();
        }

        $.ajax({
            url: requestURL,
            type: requestType,
            dataType: 'json',
            data: requestData,
            headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
            beforeSend: function () {
                $('#btn-modal-default').attr('disabled', true);
                $("#erro-default").removeClass('show').css('display', 'none');
                $("#erro-msg-default").empty();
            },
            complete: function () {
                $('#btn-modal-default').attr('disabled', false);
            },
            success: function (response) {
                showSuccessToast(response.message);
                $('#modal_default').modal('hide');
                reloadCurrentView();
            },
            error: handleAjaxError
        });
    });

    function initModalEvents() {
        $('.colorpicker').asColorPicker();
    }
});
