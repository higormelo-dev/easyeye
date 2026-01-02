@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')
    <div class="row mb-3">
        <div class="col-12">
            @include('system.users.subnav')
        </div>
    </div>
    <div class="row">
        <div class="col-12">
            <div class="card">
                <h5 class="card-header">{{ $meta['action'] }}</h5>
                <div class="card-body">
                    <div class="row">
                        <div class="col">
                            <div class="table-responsive">
                                <table id="record_datatable" class="table table-striped">
                                    <thead>
                                    <tr>
                                        <th>{{ __('actions.created_at') }}</th>
                                        <th>{{ __('actions.user') }}</th>
                                        <th>{{ __('actions.email') }}</th>
                                        <th class="text-center">{{ __('actions.active') }}</th>
                                        <th class="text-center">{{ __('actions.actions') }}</th>
                                    </tr>
                                    </thead>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('modals')
    @include('components.modal_default')
@endsection

@section('javascript')
    <script>
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
                    'url': `{{ route('panel.ajax.datatables.users') }}`,
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
                        'className': 'text-center'
                    },
                    {
                        'targets': 3,
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
                    $('.modal-title-default').append('Editar usuário');
                    $('#btn-modal-default').css('display', 'block');
                    $('.modal-dialog').removeClass('modal-md');
                    $('.modal-dialog').removeClass('modal-lg');
                    $('.modal-dialog').addClass('modal-lg');
                    $.ajax({
                        url: `{{ route('panel.accesscontrol.users.index') }}/${record_id}/edit`,
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
                    $('.modal-title-default').append('Visualizar usuário');
                    $('#btn-modal-default').css('display', 'none');
                    $('.modal-dialog').removeClass('modal-md');
                    $('.modal-dialog').removeClass('modal-lg');
                    $('.modal-dialog').addClass('modal-lg');
                    $.ajax({
                        url: `{{ route('panel.accesscontrol.users.index') }}/${record_id}`,
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
                        url: `{{ route('panel.accesscontrol.users.index') }}/${record_id}`,
                        type: 'put',
                        dataType: 'json',
                        data: {
                            'type_method': 1,
                            'name': $(this).data('name'),
                            'email': $(this).data('email'),
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
                                url: `{{ route('panel.accesscontrol.users.index') }}/${record_id}`,
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
                $('.modal-title-default').append('Cadastrar usuário');
                $('#btn-modal-default').css('display', 'block');
                $('.modal-dialog').removeClass('modal-md')
                $('.modal-dialog').removeClass('modal-lg')
                $('.modal-dialog').addClass('modal-lg');
                $('#btn-modal-default').attr('data-action', 'register');
                $('#btn-modal-default').removeAttr('data-id');
                $.ajax({
                    url: `{{ route('panel.accesscontrol.users.create') }}`,
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
                    `{{ route('panel.accesscontrol.users.store') }}` :
                    `{{ route('panel.accesscontrol.users.index') }}/${record_id}`;
                let requestData = {
                    'name': $('input[name=name]').val(),
                    'email': $('input[name=email]').val(),
                    'password': $('input[name=password]').val(),
                    'password_confirmation': $('input[name=password_confirmation]').val(),
                    'rule': $('select[name=rule]').val()
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
    </script>
@endsection