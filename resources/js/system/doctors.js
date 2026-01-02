$(function () {
    let record_id, btn_action;
    let dataTable = $('#record_datatable').DataTable({
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
            'url': 'ajax/datatables/doctors',
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
            {'data': 'name'},
            {'data': 'record'},
            {'data': 'email'},
            {'data': 'active', 'searchable': false, 'orderable': true},
            {'data': 'action', 'searchable': false, 'orderable': false},
        ],
        "columnDefs": [
            {
                'targets': 0,
                'className': 'text-left'
            },
            {
                'targets': 1,
                'className': 'text-left'
            },
            {
                'targets': 2,
                'className': 'text-left'
            },
            {
                'targets': 3,
                'className': 'text-left'
            },
            {
                'targets': 4,
                'className': 'text-center'
            },
            {
                'targets': 5,
                'className': 'text-end'
            }
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
            $('.modal-title-default').empty();
            $('.modal-title-default').append('Cadastrar médico');
            $('#btn-modal-default').css('display', 'block');
            $('.modal-dialog').removeClass('modal-md')
            $('.modal-dialog').removeClass('modal-lg')
            $('.modal-dialog').removeClass('modal-xl')
            $('.modal-dialog').addClass('modal-xl');
            $('#btn-modal-default').attr('data-action', 'register');
            $('#btn-modal-default').removeAttr('data-id');
            $.ajax({
                url: `doctors/${record_id}/edit`,
                type: 'get',
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                },
                beforeSend: function () {
                    $('#btn-modal-default').attr('disabled', false);
                    $("#erro-default").removeClass('show');
                    $("#erro-default").css('display', 'none');
                    $("#erro-msg-default").empty();
                },
                success: function (response) {
                    $('#retorno-default').empty();
                    $('#retorno-default').append(response);
                    $('#modal_default').modal('show');
                },
                error: function (response) {
                    let message = response.responseJSON.message;
                    let errors = response.responseJSON.errors;
                    if (errors && Object.keys(errors).length) {
                        $("#erro-default").addClass('show');
                        $("#erro-default").css('display', 'block');
                        $("#erro-msg-default").empty();
                        $("#erro-msg-default").append('<strong>Erro!</strong> Preencha corretamente os campos abaixo:<br>');
                        $.each(error, function (dataObject) {
                            $.each(error[dataObject], function (index, value) {
                                $("#erro-msg-default").append('- ' + value + '<br>');
                            });
                        });
                    } else {
                        $.toast({
                            heading: 'Erro!',
                            text: message,
                            position: 'top-right',
                            loaderBg: '#EF0107',
                            icon: 'error',
                            hideAfter: 5000
                        });
                    }
                }
            });
        });
        // Visualizar
        $('.btn-show').click(function () {
            record_id = $(this).data('id');
            $('.modal-title-default').empty();
            $('.modal-title-default').append('Visualizar médico');
            $('#btn-modal-default').css('display', 'none');
            $('.modal-dialog').removeClass('modal-md');
            $('.modal-dialog').removeClass('modal-lg');
            $('.modal-dialog').addClass('modal-lg');
            $.ajax({
                url: `doctors/${record_id}`,
                success: function (data) {
                    $('#retorno-default').empty();
                    $('#retorno-default').append(data);
                    $('#modal_default').modal('show');
                },
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                },
                error: function (data) {
                    let message = data.responseJSON.message;
                    let errors = data.responseJSON.errors;

                    if (errors && Object.keys(errors).length) {
                        $("#erro-default").addClass('show');
                        $("#erro-default").css('display', 'block');
                        $("#erro-msg-default").empty();
                        $("#erro-msg-default").append('<strong>Erro!</strong> Preencha corretamente os campos abaixo:<br>');
                        $.each(error, function (dataObject) {
                            $.each(error[dataObject], function (index, value) {
                                $("#erro-msg-default").append('- ' + value + '<br>');
                            });
                        });
                    } else {
                        $.toast({
                            heading: 'Erro!',
                            text: message,
                            position: 'top-right',
                            loaderBg: '#EF0107',
                            icon: 'error',
                            hideAfter: 5000
                        });
                    }
                }
            });
        });
        // Ativar ou inativar
        $('.btn-active').click(function () {
            record_id = $(this).data('id');
            $.ajax({
                url: `doctors/${record_id}`,
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
                    $.toast({
                        heading: 'Sucesso',
                        text: response.message,
                        position: 'top-right',
                        loaderBg: '#006A4E',
                        icon: 'success',
                        hideAfter: 3500,
                        stack: 6
                    });
                    dataTable.ajax.reload();
                },
                error: function (response) {
                    let message = response.responseJSON.message;
                    let errors = response.responseJSON.errors;
                    if (errors && Object.keys(errors).length) {
                        $("#erro").addClass('show');
                        $("#erro").css('display', 'block');
                        $("#erro-msg").empty();
                        $("#erro-msg").append('<strong>Erro!</strong> Preencha corretamente os campos abaixo:<br>');
                        $.each(errors, function (dataObject) {
                            $.each(errors[dataObject], function (index, value) {
                                $("#erro-msg").append('- ' + value + '<br>');
                            });
                        });
                    } else {
                        $.toast({
                            heading: 'Erro!',
                            text: message,
                            position: 'top-right',
                            loaderBg: '#EF0107',
                            icon: 'error',
                            hideAfter: 5000
                        });
                    }
                }
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
                        url: `doctors/${record_id}`,
                        dataType: 'json',
                        headers: {
                            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                        },
                        success: function (response) {
                            $.toast({
                                heading: 'Sucesso',
                                text: response.message,
                                position: 'top-right',
                                loaderBg: '#006A4E',
                                icon: 'success',
                                hideAfter: 3500,
                                stack: 6
                            });
                            dataTable.ajax.reload();
                        },
                        error: function (data) {
                            let error = data.responseJSON;
                            $.toast({
                                heading: 'Erro!',
                                text: error.message,
                                position: 'top-right',
                                loaderBg: '#EF0107',
                                icon: 'error',
                                hideAfter: 5000
                            });
                        }
                    });
                }
            });
        });
    });
    dataTable.draw();
    $('.novo-cadastro').click(function () {
        btn_action = 'store';
        $('.modal-title-default').empty();
        $('.modal-title-default').append('Cadastrar médico');
        $('#btn-modal-default').css('display', 'block');
        $('.modal-dialog').removeClass('modal-md')
        $('.modal-dialog').removeClass('modal-lg')
        $('.modal-dialog').removeClass('modal-xl')
        $('.modal-dialog').addClass('modal-xl');
        $('#btn-modal-default').attr('data-action', 'register');
        $('#btn-modal-default').removeAttr('data-id');
        $.ajax({
            url: 'doctors/create',
            type: 'get',
            beforeSend: function () {
                $('#btn-modal-default').attr('disabled', true);
                $("#erro-default").removeClass('show');
                $("#erro-default").css('display', 'none');
                $("#erro-msg-default").empty();
            },
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'),
            },
            complete: function () {
                $('#btn-modal-default').attr('disabled', false);
            },
            success: function (response) {
                $('#retorno-default').empty();
                $('#retorno-default').append(response);
                $('#modal_default').modal('show');
            },
            error: function (response) {
                let message = response.responseJSON.message;
                let error = response.responseJSON.errors;

                if (error && Object.keys(response.responseJSON.errors).length) {
                    $("#erro-default").addClass('show');
                    $("#erro-default").css('display', 'block');
                    $("#erro-msg-default").empty();

                    $("#erro-msg-default").append('<strong>Erro!</strong> Preencha corretamente os campos abaixo:<br>');
                    $.each(error, function (dataObject) {
                        $.each(error[dataObject], function (index, value) {
                            $("#erro-msg-default").append('- ' + value + '<br>');
                        });
                    });
                } else {
                }
            }
        });
    });
    $('#btn-modal-default').click(function () {
        let requestType = (btn_action === 'store') ? 'post' : 'put';
        let requestURL = (btn_action === 'store') ?
            'doctors' :
            `doctors/${record_id}`;
        let requestData = {
            'name': $('input[name=full_name]').val(),
            'national_registry': $('input[name=national_registry]').val(),
            'nickname': $('input[name=nickname]').val(),
            'record': $('input[name=record]').val(),
            'record_specialty': $('input[name=record_specialty]').val(),
            'color': $('input[name=color]').val(),
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
            'observation': $('textarea[name=observation]').val(),
            'partner': $('select[name=partner]').val()
        };

        if (requestType === 'post') {
            requestData['password'] = $('input[name=password]').val();
            requestData['password_confirmation'] = $('input[name=password_confirmation]').val();
        }

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
                $("#erro-default").removeClass('show');
                $("#erro-default").css('display', 'none');
                $("#erro-msg-default").empty();
            },
            complete: function () {
                $('#btn-modal-default').attr('disabled', false);
            },
            success: function (response) {
                $.toast({
                    heading: 'Sucesso',
                    text: response.message,
                    position: 'top-right',
                    loaderBg: '#006A4E',
                    icon: 'success',
                    hideAfter: 3500,
                    stack: 6
                });
                $('#modal_default').modal('hide');
                dataTable.ajax.reload();
            },
            error: function (response) {
                let message = response.responseJSON.message;
                let errors = response.responseJSON.errors;
                if (errors && Object.keys(errors).length) {
                    $("#erro-default").addClass('show');
                    $("#erro-default").css('display', 'block');
                    $("#erro-msg-default").empty();

                    $("#erro-msg-default").append('<strong>Erro!</strong> Preencha corretamente os campos abaixo:<br>');
                    $.each(errors, function (dataObject) {
                        $.each(errors[dataObject], function (index, value) {
                            $("#erro-msg-default").append('- ' + value + '<br>');
                        });
                    });
                } else {
                    $.toast({
                        heading: 'Erro!',
                        text: message,
                        position: 'top-right',
                        loaderBg: '#EF0107',
                        icon: 'error',
                        hideAfter: 5000
                    });
                }
            }
        });
    });
});