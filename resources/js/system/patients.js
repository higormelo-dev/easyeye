import { handleAjaxError, showSuccessToast, showErrorToast, searchAddressByZipcode } from './auxiliary_functions.js';

$(function () {
    let record_id, btn_action;

    let dataTable = $('#patient_datatable').DataTable({
        "retrieve": true,
        "order": [
            [0, 'desc']
        ],
        "searching": true,
        "bLengthChange": true,
        "bPaginate": true,
        "pageLength": 10,
        "processing": true,
        "serverSide": true,
        "lengthMenu": [
            [5, 10, 25, 50, 100],
            [5, 10, 25, 50, 100]
        ],
        "pagingType": "full_numbers",
        "ajax": {
            'url': 'ajax/datatables/patients',
            'dataType': 'json',
            'type': 'POST',
            'data': {
                '_token': $('meta[name="csrf-token"]').attr('content')
            }
        },
        "createdRow": function (row, data, dataIndex) {
            $(row).attr('id', data.id);
        },
        "drawCallback": function (settings) {
            $('[data-bs-toggle="tooltip"]').tooltip();
        },
        "columns": [
            {'data': 'created_at', 'searchable': false, 'orderable': true},
            {'data': 'code'},
            {'data': 'name'},
            {'data': 'gender', 'searchable': false, 'orderable': true},
            {'data': 'cellphone'},
            {'data': 'active', 'searchable': false, 'orderable': true},
            {'data': 'action', 'searchable': false, 'orderable': false},
        ],
        "columnDefs": [
            /*{
                'targets': [0, 1, 2, 3, 4],
                'className': 'text-left'
            },
            {
                'targets': 5,
                'className': 'text-center'
            },
            {
                'targets': 6,
                'className': 'text-end'
            }*/
        ],
        "language": {
            "sEmptyTable": "Nenhum registro encontrado",
            "sProcessing": "A processar...",
            "sLengthMenu": "Mostrar _MENU_ registos",
            "sZeroRecords": "Não foram encontrados resultados",
            "sInfo": "Mostrando de _START_ até _END_ de _TOTAL_ registos",
            "sInfoEmpty": "Mostrando de 0 até 0 de 0 registos",
            "sInfoFiltered": "(filtrado de _MAX_ registos no total)",
            "sInfoPostFix": "",
            "sSearch": "Procurar:",
            "sUrl": "",
            "oPaginate": {
                "sFirst": "Primeiro",
                "sPrevious": "Anterior",
                "sNext": "Seguinte",
                "sLast": "Último"
            },
            "oAria": {
                "sSortAscending": ": Ordenar colunas de forma ascendente",
                "sSortDescending": ": Ordenar colunas de forma descendente"
            }
        }

    });
    dataTable.on('draw', function () {
        // Editar
        $('.btn-edit').click(function () {
            record_id = $(this).data('id');
            btn_action = 'update';
            $('.modal-title-default').empty().append('Cadastrar paciente');
            $('#btn-modal-default').css('display', 'block');
            $('.modal-dialog').removeClass('modal-md modal-lg modal-xl').addClass('modal-xl');
            $('#btn-modal-default').attr('data-action', 'register');
            $('#btn-modal-default').removeAttr('data-id');
            $.ajax({
                url: `patients/${record_id}/edit`,
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
        $('.btn-show').click(function () {
            record_id = $(this).data('id');
            $('.modal-title-default').empty().append('Visualizar paciente');
            $('#btn-modal-default').css('display', 'none');
            $('.modal-dialog').removeClass('modal-md modal-lg').addClass('modal-lg');
            $.ajax({
                url: `patients/${record_id}`,
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
        $('.btn-active').click(function () {
            record_id = $(this).data('id');
            $.ajax({
                url: `patients/${record_id}`,
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
                    dataTable.ajax.reload();
                },
                error: handleAjaxError
            });
        });
        // Deletar
        $('.btn-trash').on('click', function () {
            record_id = $(this).data('id');
            Swal.fire({
                title: 'Deletar?',
                text: "Você tem certeza que deseja deletar o registro?\nEsta ação não poderá ser desfeita.",
                type: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Sim',
                cancelButtonText: 'Não'
            }).then((result) => {
                if (result.value) {
                    $.ajax({
                        method: "delete",
                        url: `patients/${record_id}`,
                        dataType: 'json',
                        headers: {
                            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                        },
                        success: function (response) {
                            showSuccessToast(response.message);
                            dataTable.ajax.reload();
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
    dataTable.draw();
    $('.new-register').click(function () {
        btn_action = 'store';
        $('.modal-title-default').empty().append('Cadastrar paciente');
        $('#btn-modal-default').css('display', 'block');
        $('.modal-dialog').removeClass('modal-md modal-lg modal-xl').addClass('modal-xl');
        $('#btn-modal-default').attr('data-action', 'register');
        $('#btn-modal-default').removeAttr('data-id');
        $.ajax({
            url: 'patients/create',
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
            'patients' :
            `patients/${record_id}`;
        let requestData = {
            'name': $('input[name=full_name]').val(),
            'national_registry': $('input[name=national_registry]').val(),
            'nickname': $('input[name=nickname]').val(),
            'birth_date': $('input[name=birth_date]').val(),
            'gender': $('select[name=gender]').val(),
            'marital_status': $('select[name=marital_status]').val(),
            'email': $('input[name=email]').val(),
            'mother_name': $('input[name=mother_name]').val(),
            'father_name': $('input[name=father_name]').val(),
            'state_registry': $('input[name=state_registry]').val(),
            'state_registry_agency': $('input[name=state_registry_agency]').val(),
            'state_registry_initial': $('select[name=state_registry_initial]').val(),
            'state_registry_date': $('input[name=state_registry_date]').val(),
            'telephone': $('input[name=telephone]').val(),
            'cellphone': $('input[name=cellphone]').val(),
            'whatsapp': $('select[name=whatsapp]').val(),
            'zipcode': $('input[name=zipcode]').val(),
            'address': $('input[name=address]').val(),
            'number': $('input[name=number]').val(),
            'complement': $('input[name=complement]').val(),
            'district': $('input[name=district]').val(),
            'city': $('input[name=city]').val(),
            'state': $('select[name=state]').val(),
            'covenant_id': $('select[name=covenant_id]').val(),
            'card_number': $('input[name=card_number]').val(),
            'skin_id': $('select[name=skin_id]').val(),
            'iris_id': $('select[name=iris_id]').val(),
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
                dataTable.ajax.reload();
            },
            error: handleAjaxError
        });
    });

    function initModalEvents() {
        $('select[name=covenant_id]').off('change').on('change', function() {
            $('input[name=card_number]').prop('disabled', ($(this).find('option:selected').text().trim() === 'Particular')).val('');
        });

        $('input[name=zipcode]').off('blur').on('blur', function() {
            searchAddressByZipcode($(this).val());
        });

        $('input[name=telephone]').inputmask('(99) 9999-9999');
        $('input[name=cellphone]').inputmask('(99) 99999-9999');
        $('input[name=zipcode]').inputmask('99999-999');
        $('input[name=national_registry]').inputmask('999.999.999-99');
    }

});