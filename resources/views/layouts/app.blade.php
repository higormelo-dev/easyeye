<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-layout="mini" data-sidebar="light" data-topbar="white" data-color="info">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'EasyEye') }}</title>
    <!-- Favicon -->
    <link rel="shortcut icon" href="{{ asset('system/images/favicon.png') }}">
    <!-- Theme Config Js (must run before paint to avoid flash) -->
    <script src="{{ asset('js/preclinic-theme-script.js') }}"></script>
    {{-- Vendor + App bundles (all CSS/JS via npm/Vite) --}}
    <style>[x-cloak] { display: none !important; }</style>
    {{-- jQuery loaded synchronously so Yajra DataTables inline scripts ($dataTable->scripts()) --}}
    {{-- can use $ before Vite ES modules (which are deferred) execute. --}}
    <script src="{{ asset('js/jquery.min.js') }}"></script>
    @vite(['resources/css/vendor.css', 'resources/css/system.scss', 'resources/js/vendor.js', 'resources/js/app.js'])
    @stack('styles')
</head>
@php
    $userPhotoPath = 'system/images/users/' . auth()->id() . '.jpg';
    $userPhotoUrl  = file_exists(public_path($userPhotoPath))
        ? asset($userPhotoPath)
        : asset('system/images/team.png');
@endphp
<body>
    <!-- Start Main Wrapper -->
    <div class="main-wrapper">

        {{-- ═══════════════════ HEADER ═══════════════════ --}}
        <header class="navbar-header">
            <div class="page-container topbar-menu">
                <div class="d-flex align-items-center gap-2">
                    <!-- Logo -->
                    <a href="{{ route('panel.dashboard') }}" class="logo">
                        <span class="logo-light">
                            <span class="logo-lg"><img src="{{ asset('system/images/logo.svg') }}" alt="{{ config('app.name') }}"></span>
                            <span class="logo-sm"><img src="{{ asset('system/images/logo-small.svg') }}" alt="{{ config('app.name') }}"></span>
                        </span>
                        <span class="logo-dark">
                            <span class="logo-lg"><img src="{{ asset('system/images/logo-white.svg') }}" alt="{{ config('app.name') }}"></span>
                        </span>
                    </a>
                    <!-- Sidebar Mobile Button -->
                    <a id="mobile_btn" class="mobile-btn" href="#sidebar">
                        <i class="ti ti-menu-deep fs-24"></i>
                    </a>
                    <button class="sidenav-toggle-btn btn border-0 p-0 active" id="toggle_btn2">
                        <i class="ti ti-arrow-right"></i>
                    </button>
                </div>

                <div class="d-flex align-items-center">
                    <!-- Seletor de Idioma -->
                    <div class="header-item">
                        <div class="dropdown me-2">
                            <button class="topbar-link btn btn-icon dropdown-toggle drop-arrow-none"
                                    data-bs-toggle="dropdown" data-bs-offset="0,24"
                                    aria-haspopup="true" aria-expanded="false">
                                <i class="ti ti-world fs-16"></i>
                            </button>
                            <div class="dropdown-menu dropdown-menu-end p-2">
                                @foreach(\App\Http\Middleware\SetLocale::getSupportedLocales() as $code => $locale)
                                    <a href="{{ route('locale.switch', $code) }}"
                                       class="dropdown-item {{ app()->getLocale() === $code ? 'active' : '' }}">
                                        {{ $locale['flag'] }} {{ $locale['native'] }}
                                        @if(app()->getLocale() === $code)
                                            <i class="ti ti-check ms-2"></i>
                                        @endif
                                    </a>
                                @endforeach
                                @if(auth()->user()->locale)
                                    <div class="dropdown-divider"></div>
                                    <form action="{{ route('locale.user.clear') }}" method="POST" class="d-inline w-100">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="dropdown-item text-muted">
                                            <i class="ti ti-reload me-1"></i> {{ __('actions.use_entity_language') }}
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </div>

                    <!-- Light/Dark Mode -->
                    <div class="header-item d-none d-sm-flex me-2">
                        <button class="topbar-link btn btn-icon" id="light-dark-mode" type="button">
                            <i class="ti ti-moon fs-16"></i>
                        </button>
                    </div>

                    <!-- User Dropdown -->
                    <div class="dropdown profile-dropdown d-flex align-items-center justify-content-center">
                        <a href="javascript:void(0);" class="topbar-link dropdown-toggle drop-arrow-none position-relative"
                           data-bs-toggle="dropdown" data-bs-offset="0,22"
                           aria-haspopup="false" aria-expanded="false">
                            <img src="{{ $userPhotoUrl }}" width="32" class="rounded-circle d-flex" alt="{{ auth()->user()->name }}">
                            <span class="online text-success"><i class="ti ti-circle-filled d-flex bg-white rounded-circle border border-1 border-white"></i></span>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end dropdown-menu-md p-2">
                            <div class="d-flex align-items-center bg-light rounded-3 p-2 mb-2">
                                <img src="{{ $userPhotoUrl }}" class="rounded-circle" width="42" height="42" alt="">
                                <div class="ms-2">
                                    <p class="fw-medium text-dark mb-0">{{ auth()->user()->name }}</p>
                                    <span class="d-block fs-13">{{ auth()->user()->email }}</span>
                                </div>
                            </div>
                            <a href="{{ route('panel.profile.edit') }}" class="dropdown-item">
                                <i class="ti ti-user-circle me-1 align-middle"></i>
                                <span class="align-middle">{{ __('actions.edit_profile') }}</span>
                            </a>
                            @if(session()->has('impersonating'))
                                <div class="dropdown-divider"></div>
                                {!! html()->form('DELETE', route('panel.manager.impersonate.destroy'))->open() !!}
                                <button type="submit" class="dropdown-item text-danger">
                                    <i class="ti ti-user-x me-1 align-middle"></i>
                                    <span class="align-middle">{{ __('actions.impersonate.exit') }} <small class="text-muted">({{ session('impersonating.original_user_name') }})</small></span>
                                </button>
                                {!! html()->form()->close() !!}
                            @endif
                            <div class="pt-2 mt-2 border-top">
                                {!! html()->form('POST', route('logout'))
                                    ->attribute('style', 'display: inline;')
                                    ->open() !!}
                                <a href="#" class="dropdown-item text-danger"
                                   onclick="event.preventDefault(); this.closest('form').submit();">
                                    <i class="ti ti-logout me-1 fs-17 align-middle"></i>
                                    <span class="align-middle">{{ __('actions.log_out') }}</span>
                                </a>
                                {!! html()->form()->close() !!}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </header>
        {{-- ═══════════════════ END HEADER ═══════════════════ --}}

        {{-- ═══════════════════ SIDEBAR ═══════════════════ --}}
        <div class="sidebar" id="sidebar">
            <div class="sidebar-logo">
                <div>
                    <a href="{{ route('panel.dashboard') }}" class="logo logo-normal">
                        <img src="{{ asset('system/images/logo.svg') }}" alt="{{ config('app.name') }}">
                    </a>
                    <a href="{{ route('panel.dashboard') }}" class="logo-small">
                        <img src="{{ asset('system/images/logo-small.svg') }}" alt="{{ config('app.name') }}">
                    </a>
                    <a href="{{ route('panel.dashboard') }}" class="dark-logo">
                        <img src="{{ asset('system/images/logo-white.svg') }}" alt="{{ config('app.name') }}">
                    </a>
                </div>
                <button class="sidenav-toggle-btn btn border-0 p-0 active" id="toggle_btn">
                    <i class="ti ti-arrow-left"></i>
                </button>
                <button class="sidebar-close">
                    <i class="ti ti-x align-middle"></i>
                </button>
            </div>
            <div class="sidebar-inner" data-simplebar>
                <div id="sidebar-menu" class="sidebar-menu">
                    <ul>
                        <li class="menu-title"><span>Menu</span></li>
                        <li>
                            <ul>
                                {{-- Dashboard --}}
                                <li>
                                    <a href="{{ route('panel.dashboard') }}" class="{{ request()->routeIs('panel.dashboard') ? 'active' : '' }}">
                                        <i class="ti ti-layout-dashboard"></i><span>Dashboard</span>
                                    </a>
                                </li>

                                @if(!session()->get('selected_entity_is_client'))
                                    @include('components.sidemenu.manager-preclinic')
                                @else
                                    @include('components.sidemenu.tenant-preclinic')
                                @endif
                            </ul>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
        {{-- ═══════════════════ END SIDEBAR ═══════════════════ --}}

        {{-- ═══════════════════ PAGE WRAPPER ═══════════════════ --}}
        <div class="page-wrapper">
            @include('components.impersonation-banner')
            <div class="content">
                @yield('breadcrumb')
                @yield('content')
                @yield('modals')
            </div>
        </div>
        {{-- ═══════════════════ END PAGE WRAPPER ═══════════════════ --}}

    </div>
    <!-- End Main Wrapper -->

    {{-- ═══════════════════ SCRIPTS ═══════════════════ --}}
    {{-- jQuery, Bootstrap, Raphael/Morris, theme JS, plugins — all loaded via vendor.js (Vite) --}}

    {{-- Translations --}}
    <script>
        window.translations = {
            datatable: {
                "sEmptyTable": "{{ __('actions.datatable.sEmptyTable') }}",
                "sProcessing": "{{ __('actions.datatable.sProcessing') }}",
                "sLengthMenu": "{{ __('actions.datatable.sLengthMenu') }}",
                "sZeroRecords": "{{ __('actions.datatable.sZeroRecords') }}",
                "sInfo": "{{ __('actions.datatable.sInfo') }}",
                "sInfoEmpty": "{{ __('actions.datatable.sInfoEmpty') }}",
                "sInfoFiltered": "{{ __('actions.datatable.sInfoFiltered') }}",
                "sInfoPostFix": "",
                "sSearch": "{{ __('actions.datatable.sSearch') }}",
                "sUrl": "",
                "oPaginate": {
                    "sFirst": "{{ __('actions.datatable.oPaginate.sFirst') }}",
                    "sPrevious": "{{ __('actions.datatable.oPaginate.sPrevious') }}",
                    "sNext": "{{ __('actions.datatable.oPaginate.sNext') }}",
                    "sLast": "{{ __('actions.datatable.oPaginate.sLast') }}"
                },
                "oAria": {
                    "sSortAscending": "{{ __('actions.datatable.oAria.sSortAscending') }}",
                    "sSortDescending": "{{ __('actions.datatable.oAria.sSortDescending') }}"
                }
            },
            messages: {
                "register": "{{ __('actions.messages.register') }}",
                "edit": "{{ __('actions.messages.edit') }}",
                "view": "{{ __('actions.messages.view') }}",
                "delete_confirm_title": "{{ __('actions.messages.delete_confirm_title') }}",
                "delete_confirm_text": "{{ __('actions.messages.delete_confirm_text') }}",
                "restore_confirm_title": "{{ __('actions.messages.restore_confirm_title') }}",
                "restore_confirm_text": "{{ __('actions.messages.restore_confirm_text') }}",
                "confirm_yes": "{{ __('actions.messages.confirm_yes') }}",
                "confirm_no": "{{ __('actions.messages.confirm_no') }}"
            },
            actions: {
                "covertesttype": "{{ mb_convert_case(__('actions.covertesttype'), MB_CASE_LOWER, 'UTF-8') }}",
                "surgerytype": "{{ mb_convert_case(__('actions.surgerytype'), MB_CASE_LOWER, 'UTF-8') }}",
                "skintype": "{{ mb_convert_case(__('actions.skintype'), MB_CASE_LOWER, 'UTF-8') }}",
                "iristype": "{{ mb_convert_case(__('actions.iristype'), MB_CASE_LOWER, 'UTF-8') }}",
                "visittype": "{{ mb_convert_case(__('actions.visittype'), MB_CASE_LOWER, 'UTF-8') }}",
                "covenant": "{{ mb_convert_case(__('actions.covenant'), MB_CASE_LOWER, 'UTF-8') }}",
                "doctor": "{{ mb_convert_case(__('actions.doctor'), MB_CASE_LOWER, 'UTF-8') }}",
                "patient": "{{ mb_convert_case(__('actions.patient'), MB_CASE_LOWER, 'UTF-8') }}",
                "user": "{{ mb_convert_case(__('actions.user'), MB_CASE_LOWER, 'UTF-8') }}",
                "colorvisiontype": "{{ mb_convert_case(__('actions.colorvisiontype'), MB_CASE_LOWER, 'UTF-8') }}",
                "additiontype": "{{ mb_convert_case(__('actions.additiontype'), MB_CASE_LOWER, 'UTF-8') }}",
                "visualacuitytype": "{{ mb_convert_case(__('actions.visualacuitytype'), MB_CASE_LOWER, 'UTF-8') }}",
                "lense": "{{ mb_convert_case(__('actions.lense'), MB_CASE_LOWER, 'UTF-8') }}",
                "nearpointconvergence": "{{ mb_convert_case(__('actions.nearpointconvergence'), MB_CASE_LOWER, 'UTF-8') }}",
            }
        };
        window.dataTableTranslations = window.translations.datatable;
    </script>

    @yield('javascript')

    {{-- Footer Clock (Alpine.js) --}}
    <script>
    function footerClock() {
        return {
            display: '',
            start() {
                const locale = '{{ str_replace('_', '-', app()->getLocale()) }}';
                const fmt = () => new Date().toLocaleString(locale, {
                    weekday: 'short', year: 'numeric', month: '2-digit',
                    day: '2-digit', hour: '2-digit', minute: '2-digit'
                });
                this.display = fmt();
                setInterval(() => { this.display = fmt(); }, 30000);
            }
        };
    }
    </script>

    {{-- Session Timeout --}}
    <script>
    (function () {
        var SESSION_LIFETIME_MS = {{ config('session.lifetime') * 60 * 1000 }};
        var WARNING_BEFORE_MS   = 2 * 60 * 1000;
        var warningTimer, expireTimer, warningShown = false;

        function resetTimers() {
            clearTimeout(warningTimer);
            clearTimeout(expireTimer);
            warningShown = false;
            warningTimer = setTimeout(showWarning, SESSION_LIFETIME_MS - WARNING_BEFORE_MS);
            expireTimer  = setTimeout(expireSession, SESSION_LIFETIME_MS);
        }

        function showWarning() {
            if (warningShown) return;
            warningShown = true;
            var remaining = 120;
            Swal.fire({
                title: '{{ __("auth.session_expiring_title") }}',
                html: '{!! __("auth.session_expiring_html") !!}',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: '{{ __("auth.session_stay") }}',
                cancelButtonText: '{{ __("auth.session_logout") }}',
                allowOutsideClick: false,
                allowEscapeKey: false,
                didOpen: function () {
                    var el = document.getElementById('swal-session-countdown');
                    var interval = setInterval(function () {
                        remaining--;
                        var m = Math.floor(remaining / 60);
                        var s = remaining % 60;
                        if (el) el.textContent = m + ':' + (s < 10 ? '0' : '') + s;
                        if (remaining <= 0) clearInterval(interval);
                    }, 1000);
                    Swal.getPopup().__countdownInterval = interval;
                },
                willClose: function () {
                    clearInterval(Swal.getPopup().__countdownInterval);
                }
            }).then(function (result) {
                if (result.isConfirmed) {
                    fetch('{{ route('session.ping') }}', {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
                    })
                    .then(function (r) {
                        if (r.ok) { resetTimers(); } else { expireSession(); }
                    })
                    .catch(expireSession);
                } else {
                    expireSession();
                }
            });
        }

        function expireSession() {
            window.location.reload();
        }

        ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach(function (evt) {
            document.addEventListener(evt, resetTimers, { passive: true });
        });

        resetTimers();
    })();
    </script>
    @stack('scripts')
</body>
</html>
