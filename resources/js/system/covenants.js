import { handleAjaxError, showSuccessToast, showErrorToast } from './auxiliary_functions.js';

$(function () {
    let record_id, btn_action;

    // Obtém traduções do Laravel
    const trans = window.translations;
    const entityName = trans.actions.covenant;

    // Obtém a instância do DataTable já criada pelo Laravel DataTables
    let covenantsDataTable = window.LaravelDataTables['covenants_datatable'];

    // Evento de desenho da tabela - registra os handlers dos botões
    $('#covenants_datatable').on('draw.dt', function () {
        $('[data-bs-toggle="tooltip"]').tooltip();

        // Editar
        $('.btn-edit').off('click').on('click', function () {
            record_id = $(this).data('id');
            btn_action = 'update';
            $('.modal-title-default').empty().append(trans.messages.edit.replace(':name', entityName));
            $('#btn-modal-default').css('display', 'block');
            $('.modal-dialog').removeClass('modal-md modal-lg modal-xl').addClass('modal-xl');
            $('#btn-modal-default').attr('data-action', 'register');
            $('#btn-modal-default').removeAttr('data-id');
            $("#erro-default").removeClass('show').css('display', 'none');
            $.ajax({
                url: `covenants/${record_id}/edit`,
                type: 'get',
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                },
                beforeSend: function () {
                    $('#btn-modal-default').attr('disabled', false);
                    $("#erro-default").removeClass('show').css('display', 'none');
                    $("#erro-msg-default").empty();
                },
                success: function (response) {
                    $('#retorno-default').empty().append(response);
                    $('#modal_default').modal('show');
                    setTimeout(function() {
                        initModalEvents();
                    }, 100);
                },
                error: handleAjaxError
            });
        });
        // Visualizar
        $('.btn-show').off('click').on('click', function () {
            record_id = $(this).data('id');
            $('.modal-title-default').empty().append(trans.messages.view.replace(':name', entityName));
            $('#btn-modal-default').css('display', 'none');
            $('.modal-dialog').removeClass('modal-md modal-lg').addClass('modal-lg');
            $("#erro-default").removeClass('show').css('display', 'none');
            $.ajax({
                url: `covenants/${record_id}`,
                success: function (data) {
                    $('#retorno-default').empty().append(data);
                    $('#modal_default').modal('show');
                },
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                },
                error: handleAjaxError
            });
        });
        // Ativar ou inativar
        $('.btn-active').off('click').on('click', function () {
            record_id = $(this).data('id');
            $.ajax({
                url: `covenants/${record_id}`,
                type: 'put',
                dataType: 'json',
                data: {
                    'type_method': 1,
                    'active': $(this).data('situation')
                },
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                },
                success: function (response) {
                    showSuccessToast(response.message);
                    covenantsDataTable.ajax.reload();
                },
                error: handleAjaxError
            });
        });
        // Deletar
        $('.btn-trash').on('click', function () {
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
                        method: "delete",
                        url: `covenants/${record_id}`,
                        dataType: 'json',
                        headers: {
                            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                        },
                        success: function (response) {
                            showSuccessToast(response.message);
                            covenantsDataTable.ajax.reload();
                        },
                        error: function (data) {
                            let error = data.responseJSON;
                            showErrorToast(error.message);
                        }
                    });
                }
            });
        });
        // Restaurar
        $('.btn-restore').on('click', function () {
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
                        method: "get",
                        url: `covenants/${record_id}/restore`,
                        dataType: 'json',
                        headers: {
                            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                        },
                        success: function (response) {
                            showSuccessToast(response.message);
                            covenantsDataTable.ajax.reload();
                        },
                        error: function (data) {
                            let error = data.responseJSON;
                            showErrorToast(error.message);
                        }
                    });
                }
            });
        });
    });

    // Novo registro
    $('.new-register').on('click', function () {
        btn_action = 'store';
        $('.modal-title-default').empty().append(trans.messages.register.replace(':name', entityName));
        $('#btn-modal-default').css('display', 'block');
        $('.modal-dialog').removeClass('modal-md modal-lg modal-xl').addClass('modal-xl');
        $('#btn-modal-default').attr('data-action', 'register');
        $('#btn-modal-default').removeAttr('data-id');
        $.ajax({
            url: 'covenants/create',
            type: 'get',
            beforeSend: function () {
                $('#btn-modal-default').attr('disabled', true);
                $("#erro-default").removeClass('show').css('display', 'none');
                $("#erro-msg-default").empty();
            },
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'),
            },
            complete: function () {
                $('#btn-modal-default').attr('disabled', false);
            },
            success: function (response) {
                $('#retorno-default').empty().append(response);
                $('#modal_default').modal('show');
                setTimeout(function() {
                    initModalEvents();
                }, 100);
            },
            error: handleAjaxError
        });
    });
    $('#btn-modal-default').click(function () {
        let requestType = (btn_action === 'store') ? 'post' : 'put';
        let requestURL = (btn_action === 'store') ?
            'covenants' :
            `covenants/${record_id}`;
        let requestData = {
            'name': $('input[name=name]').val(),
            'color': $('input[name=color]').val(),
            'table': $('select[name=table]').val(),
        };

        if (requestType === 'put') {
            requestData['active'] = $('select[name=active]').val();
        }

        $.ajax({
            url: requestURL,
            type: requestType,
            dataType: 'json',
            data: requestData,
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            },
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
                covenantsDataTable.ajax.reload();
            },
            error: handleAjaxError
        });
    });

    function initModalEvents() {
        $('.colorpicker').asColorPicker();
    }
});