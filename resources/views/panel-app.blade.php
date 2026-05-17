<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      data-layout="mini"
      data-sidebar="light"
      data-topbar="white"
      data-color="info">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'EasyEye') }}</title>
    <link rel="shortcut icon" href="{{ Vite::asset('resources/img/system/favicon.png') }}">
    {{-- Theme config: executa antes do paint para evitar flash de tema --}}
    <script src="{{ asset('js/preclinic-theme-script.js') }}"></script>
    <style>[x-cloak] { display: none !important; }</style>
    {{-- jQuery síncrono: DataTables inline scripts ($dataTable->scripts()) precisam de $ antes dos ES modules --}}
    <script src="{{ asset('js/jquery.min.js') }}"></script>
    @routes
    @vite(['resources/css/vendor.css', 'resources/css/system.scss', 'resources/js/vendor.js', 'resources/js/panel.js'])
    @inertiaHead
</head>
<body>
    {{-- Traduções expostas globalmente para DataTables e helpers JS legados --}}
    <script>
        window.translations = {
            datatable: {
                "sEmptyTable":    "{{ __('actions.datatable.sEmptyTable') }}",
                "sProcessing":    "{{ __('actions.datatable.sProcessing') }}",
                "sLengthMenu":    "{{ __('actions.datatable.sLengthMenu') }}",
                "sZeroRecords":   "{{ __('actions.datatable.sZeroRecords') }}",
                "sInfo":          "{{ __('actions.datatable.sInfo') }}",
                "sInfoEmpty":     "{{ __('actions.datatable.sInfoEmpty') }}",
                "sInfoFiltered":  "{{ __('actions.datatable.sInfoFiltered') }}",
                "sInfoPostFix":   "",
                "sSearch":        "{{ __('actions.datatable.sSearch') }}",
                "sUrl":           "",
                "oPaginate": {
                    "sFirst":    "{{ __('actions.datatable.oPaginate.sFirst') }}",
                    "sPrevious": "{{ __('actions.datatable.oPaginate.sPrevious') }}",
                    "sNext":     "{{ __('actions.datatable.oPaginate.sNext') }}",
                    "sLast":     "{{ __('actions.datatable.oPaginate.sLast') }}"
                },
                "oAria": {
                    "sSortAscending":  "{{ __('actions.datatable.oAria.sSortAscending') }}",
                    "sSortDescending": "{{ __('actions.datatable.oAria.sSortDescending') }}"
                }
            },
            messages: {
                "register":             "{{ __('actions.messages.register') }}",
                "edit":                 "{{ __('actions.messages.edit') }}",
                "view":                 "{{ __('actions.messages.view') }}",
                "delete_confirm_title": "{{ __('actions.messages.delete_confirm_title') }}",
                "delete_confirm_text":  "{{ __('actions.messages.delete_confirm_text') }}",
                "restore_confirm_title":"{{ __('actions.messages.restore_confirm_title') }}",
                "restore_confirm_text": "{{ __('actions.messages.restore_confirm_text') }}",
                "confirm_yes":          "{{ __('actions.messages.confirm_yes') }}",
                "confirm_no":           "{{ __('actions.messages.confirm_no') }}"
            },
            actions: {
                "covertesttype":        "{{ mb_convert_case(__('actions.covertesttype'), MB_CASE_LOWER, 'UTF-8') }}",
                "surgerytype":          "{{ mb_convert_case(__('actions.surgerytype'), MB_CASE_LOWER, 'UTF-8') }}",
                "skintype":             "{{ mb_convert_case(__('actions.skintype'), MB_CASE_LOWER, 'UTF-8') }}",
                "iristype":             "{{ mb_convert_case(__('actions.iristype'), MB_CASE_LOWER, 'UTF-8') }}",
                "visittype":            "{{ mb_convert_case(__('actions.visittype'), MB_CASE_LOWER, 'UTF-8') }}",
                "covenant":             "{{ mb_convert_case(__('actions.covenant'), MB_CASE_LOWER, 'UTF-8') }}",
                "doctor":               "{{ mb_convert_case(__('actions.doctor'), MB_CASE_LOWER, 'UTF-8') }}",
                "patient":              "{{ mb_convert_case(__('actions.patient'), MB_CASE_LOWER, 'UTF-8') }}",
                "user":                 "{{ mb_convert_case(__('actions.user'), MB_CASE_LOWER, 'UTF-8') }}",
                "colorvisiontype":      "{{ mb_convert_case(__('actions.colorvisiontype'), MB_CASE_LOWER, 'UTF-8') }}",
                "additiontype":         "{{ mb_convert_case(__('actions.additiontype'), MB_CASE_LOWER, 'UTF-8') }}",
                "visualacuitytype":     "{{ mb_convert_case(__('actions.visualacuitytype'), MB_CASE_LOWER, 'UTF-8') }}",
                "lense":                "{{ mb_convert_case(__('actions.lense'), MB_CASE_LOWER, 'UTF-8') }}",
                "nearpointconvergence": "{{ mb_convert_case(__('actions.nearpointconvergence'), MB_CASE_LOWER, 'UTF-8') }}"
            }
        };
        window.dataTableTranslations = window.translations.datatable;
        window.sessionLifetimeMs = {{ config('session.lifetime') * 60 * 1000 }};
        window.sessionLocale     = '{{ str_replace('_', '-', app()->getLocale()) }}';
    </script>

    @inertia
</body>
</html>
