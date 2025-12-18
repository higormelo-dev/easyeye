<!DOCTYPE html>
    <html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ config('app.name', 'EasyEye') }}</title>
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('system/images/favicon.png') }}}">
        <link href="{{ asset('system/plugins/morrisjs/morris.css') }}" rel="stylesheet">
        <link href="{{ asset('system/plugins/toast-master/css/jquery.toast.css') }}" rel="stylesheet">
        <link href="{{ asset('system/plugins/datatables.net-bs4/css/responsive.dataTables.min.css') }}" rel="stylesheet">
        <link href="{{ asset('system/plugins/sweetalert2/dist/sweetalert2.min.css') }}" rel="stylesheet">
        <link href="{{ asset('system/css/style.min.css') }}" rel="stylesheet">
        <link href="{{ asset('system/css/pages/dashboard1.css') }}" rel="stylesheet">
        <!-- HTML5 Shim and Respond.js IE8 support of HTML5 elements and media queries -->
        <!-- WARNING: Respond.js doesn't work if you view the page via file:// -->
        <!--[if lt IE 9]>
        <script src="https://oss.maxcdn.com/libs/html5shiv/3.7.0/html5shiv.js"></script>
        <script src="https://oss.maxcdn.com/libs/respond.js/1.4.2/respond.min.js"></script>
        <![endif]-->
    </head>
    <body class="skin-blue-dark fixed-layout mini-sidebar">
    @include('components.preloader')
    <div id="main-wrapper">
        <header class="topbar">
            <nav class="navbar top-navbar navbar-expand-md navbar-dark">
                <div class="navbar-header">
                    <a class="navbar-brand" href="{{ route('panel.dashboard') }}">
                        <b>
                            <img src="{{ asset('system/images/logo-icon.png') }}"
                                 alt="{{ config('app.name', 'EasyEye') }}" class="dark-logo"/>
                            <img src="{{ asset('system/images/logo-light-icon.png') }}"
                                 alt="{{ config('app.name', 'EasyEye') }}" class="light-logo"/>
                            <span class="hidden-xs"><span class="font-bold">Easy</span>Eye</span>
                        </b>
                    </a>
                </div>
                <div class="navbar-collapse">
                    <ul class="navbar-nav me-auto">
                        <li class="nav-item">
                            <a class="nav-link nav-toggler d-block d-md-none waves-effect waves-dark"
                               href="javascript:void(0)">
                                <i class="ti-menu"></i>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link sidebartoggler d-none d-lg-block d-md-block waves-effect waves-dark"
                               href="javascript:void(0)">
                                <i class="icon-menu"></i>
                            </a>
                        </li>
                    </ul>
                    <ul class="navbar-nav my-lg-0">
                        <li class="nav-item dropdown u-pro">
                            <a class="nav-link dropdown-toggle waves-effect waves-dark profile-pic" href=""
                               data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <img src="{{ asset('system/images/users/1.jpg') }}" alt="{{ auth()->user()->name }}"
                                     class="">
                                <span class="hidden-md-down">
                                            {{ auth()->user()->name }} &nbsp;<i class="fa fa-angle-down"></i>
                                        </span>
                            </a>
                            <div class="dropdown-menu dropdown-menu-end animated flipInY">
                                <a href="javascript:void(0)" class="dropdown-item">
                                    <i class="ti-user"></i> {{ __('actions.edit_profile') }}
                                </a>
                                <div class="dropdown-divider"></div>
                                {!! html()->form('POST', route('logout'))
                                            ->attribute('style', 'display: inline;')
                                            ->open() !!}
                                {!! html()->a('#')
                                    ->class('dropdown-item')
                                    ->attribute('onclick', 'event.preventDefault(); this.closest(\'form\').submit();')
                                    ->html('<i class="fa fa-power-off me-2"></i>' . __('actions.log_out')) !!}
                                {!! html()->form()->close() !!}
                            </div>
                        </li>
                    </ul>
                </div>
            </nav>
        </header>
        <aside class="left-sidebar">
            <div class="scroll-sidebar">
                <nav class="sidebar-nav">
                    <ul id="sidebarnav">
                        <li class="user-pro">
                            <a class="has-arrow waves-effect waves-dark" href="javascript:void(0)"
                               aria-expanded="false">
                                <img src="{{ asset('system/images/users/1.jpg') }}" alt="{{ auth()->user()->name }}"
                                     class="img-circle">
                                <span class="hide-menu">{{ auth()->user()->name }}</span>
                                <ul aria-expanded="false" class="collapse">
                                    <li>
                                        <a href="javascript:void(0)">
                                            <i class="ti-user"></i> {{ __('actions.edit_profile') }}
                                        </a>
                                    </li>
                                    <li>
                                        {!! html()->form('POST', route('logout'))
                                            ->attribute('style', 'display: inline;')
                                            ->open() !!}
                                        {!! html()->a('#')
                                            ->class('nav-link')
                                            ->attribute(
                                                "onclick",
                                                "event.preventDefault(); this.closest('form').submit();"
                                            )
                                            ->html(
                                                '<i class="fa fa-power-off me-2"></i>' . __('actions.log_out')
                                            )
                                        !!}
                                        {!! html()->form()->close() !!}
                                    </li>
                                </ul>
                            </a>
                        </li>
                        <li>
                            <a class="waves-effect waves-dark" href="{{ route('panel.dashboard') }}">
                                <i class="icon-speedometer"></i><span class="hide-menu">Dashboard</span>
                            </a>
                        </li>
                        @if(!session()->get('selected_entity_is_client'))
                            @include('components.sidemenu.manager')
                        @else
                            @include('components.sidemenu.tenant')
                        @endif
                    </ul>
                </nav>
            </div>
        </aside>
        <div class="page-wrapper">
            <div class="container-fluid">
                @yield('breadcrumb')
                @yield('content')
                @yield('modals')
            </div>
        </div>
        <footer class="footer">
            © {{ \Carbon\Carbon::now()->year }} {{ config('app.name', 'EasyEye') }}
        </footer>
    </div>
    <script src="{{ asset('system/plugins/jquery/dist/jquery.min.js') }}"></script>
    <script src="{{ asset('system/plugins/bootstrap/dist/js/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset('system/js/perfect-scrollbar.jquery.min.js') }}"></script>
    <script src="{{ asset('system/plugins/raphael/raphael-min.js') }}"></script>
    <script src="{{ asset('system/plugins/morrisjs/morris.min.js') }}"></script>
    <script src="{{ asset('system/plugins/jquery-sparkline/jquery.sparkline.min.js') }}"></script>
    <script src="{{ asset('system/plugins/toast-master/js/jquery.toast.js') }}"></script>
    <script src="{{ asset('system/plugins/datatables.net/js/jquery.dataTables.min.js') }}"></script>
    <script src="{{ asset('system/plugins/datatables.net-bs4/js/dataTables.responsive.min.js') }}"></script>
    <script src="{{ asset('system/plugins/sweetalert2/dist/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('system/js/waves.js') }}"></script>
    <script src="{{ asset('system/js/sidebarmenu.js') }}"></script>
    <script src="{{ asset('system/js/custom.min.js') }}"></script>
    @yield('javascript')


    {{-- <div class="min-h-screen bg-gray-100 dark:bg-gray-900"> --}}
    {{--     @include('layouts.navigation') --}}

    {{--     <!-- Page Heading --> --}}
    {{--     @isset($header) --}}
    {{--         <header class="bg-white dark:bg-gray-800 shadow"> --}}
    {{--             <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8"> --}}
    {{--                 {{ $header }} --}}
    {{--             </div> --}}
    {{--         </header> --}}
    {{--     @endisset --}}

    {{--     <!-- Page Content --> --}}
    {{--     <main> --}}
    {{--         {{ $slot }} --}}
    {{--     </main> --}}
    {{-- </div> --}}
    </body>
</html>
