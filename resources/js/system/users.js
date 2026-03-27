import "jquery";
import { handleAjaxError, showSuccessToast, showErrorToast } from './auxiliary_functions.js';

$(function () {
    let record_id, btn_action;

    const trans      = window.translations;
    const entityName = trans.actions.user;

    let usersDataTable = window.LaravelDataTables?.['users_datatable'];

    $('#users_datatable').on('draw.dt', function () {
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
            bootstrap.Tooltip.getOrCreateInstance(el);
        });
        usersDataTable = usersDataTable ?? window.LaravelDataTables?.['users_datatable'];
        usersDataTable?.columns.adjust();
    });

    function reloadCurrentView() {
        const component = window.userViewComponent;
        if (component && component.view === 'cards') {
            component.fetchCards(component.meta.current_page);
        } else {
            usersDataTable?.ajax.reload();
        }
    }

    // ── Handlers via delegation — funcionam na tabela E nos cards ────────────

    // Visualizar
    $(document).on('click', '.btn-show', function () {
        record_id = $(this).data('id');
        $('.modal-title-default').empty().append(trans.messages.view.replace(':name', entityName));
        $('#btn-modal-default').css('display', 'none');
        $('.modal-dialog').removeClass('modal-md modal-lg modal-xl').addClass('modal-lg');
        $("#erro-default").removeClass('show').css('display', 'none');
        $.ajax({
            url: 'users/' + record_id,
            headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
            success: function (data) {
                $('#retorno-default').empty().append(data);
                bootstrap.Modal.getOrCreateInstance(document.getElementById('modal_default')).show();
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
            url: 'users/' + record_id + '/edit',
            type: 'get',
            headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
            beforeSend: function () {
                $('#btn-modal-default').attr('disabled', false);
                $("#erro-default").removeClass('show').css('display', 'none');
                $("#erro-msg-default").empty();
            },
            success: function (response) {
                $('#retorno-default').empty().append(response);
                bootstrap.Modal.getOrCreateInstance(document.getElementById('modal_default')).show();
                setTimeout(initModalEvents, 100);
            },
            error: handleAjaxError
        });
    });

    // Ativar / Inativar
    $(document).on('click', '.btn-active', function () {
        record_id = $(this).attr('data-id');
        $.ajax({
            url: 'users/' + record_id,
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
                    url: 'users/' + record_id,
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
                    url: 'users/' + record_id + '/restore',
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

    // ── Novo registro ─────────────────────────────────────────────────────────
    $('.new-register').on('click', function () {
        btn_action = 'store';
        $('.modal-title-default').empty().append(trans.messages.register.replace(':name', entityName));
        $('#btn-modal-default').css('display', 'block');
        $('.modal-dialog').removeClass('modal-md modal-lg modal-xl').addClass('modal-xl');
        $('#btn-modal-default').attr('data-action', 'register').removeAttr('data-id');
        $.ajax({
            url: 'users/create',
            type: 'get',
            headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
            beforeSend: function () {
                $('#btn-modal-default').attr('disabled', true);
                $("#erro-default").removeClass('show').css('display', 'none');
                $("#erro-msg-default").empty();
            },
            complete: function () { $('#btn-modal-default').attr('disabled', false); },
            success: function (response) {
                $('#retorno-default').empty().append(response);
                bootstrap.Modal.getOrCreateInstance(document.getElementById('modal_default')).show();
                setTimeout(initModalEvents, 100);
            },
            error: handleAjaxError
        });
    });

    // Salvar (criar ou atualizar)
    $('#btn-modal-default').on('click', function () {
        const requestType = btn_action === 'store' ? 'post' : 'put';
        const requestURL  = btn_action === 'store' ? 'users' : 'users/' + record_id;
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
            complete: function () { $('#btn-modal-default').attr('disabled', false); },
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
