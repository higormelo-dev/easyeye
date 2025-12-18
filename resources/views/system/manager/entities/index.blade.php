@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')
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
                                            <th>{{ __('actions.entity') }}</th>
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

@section('javascript')
    <script>
        $(function () {
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
                    'url': `{{ route('panel.ajax.datatables.entities') }}`,
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
            dataTable.on('draw', function () {});
        });
    </script>
@endsection