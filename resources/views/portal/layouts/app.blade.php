<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('actions.partners.portal_title') }} — {{ config('app.name', 'EasyEye') }}</title>
    <link rel="shortcut icon" href="{{ Vite::asset('resources/img/system/favicon.png') }}">
    <style>[x-cloak] { display: none !important; }</style>
    <script src="{{ asset('js/jquery.min.js') }}"></script>
    @vite(['resources/css/vendor.css', 'resources/css/system.scss', 'resources/js/vendor.js', 'resources/js/app.js'])
</head>
<body class="bg-light">

@php
    $portalPartner = \App\Models\Partner::find(session('portal_partner_id'));
@endphp

<nav class="navbar navbar-expand-lg navbar-dark" style="background:linear-gradient(135deg,#26a69a 0%,#1565c0 100%);">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-semibold" href="{{ route('portal.dashboard') }}">
            <i class="fas fa-handshake me-2"></i>{{ __('actions.partners.portal_title') }}
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#portalNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="portalNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('portal.dashboard') ? 'active fw-semibold' : '' }}"
                       href="{{ route('portal.dashboard') }}">
                        <i class="fas fa-tachometer-alt me-1"></i>Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('portal.leads.*') ? 'active fw-semibold' : '' }}"
                       href="{{ route('portal.leads.index') }}">
                        <i class="fas fa-users me-1"></i>{{ __('actions.partners.my_leads') }}
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('portal.commissions.*') ? 'active fw-semibold' : '' }}"
                       href="{{ route('portal.commissions.index') }}">
                        <i class="fas fa-coins me-1"></i>{{ __('actions.partners.my_commissions') }}
                    </a>
                </li>
            </ul>

            <ul class="navbar-nav ms-auto align-items-center">
                @if($portalPartner)
                <li class="nav-item me-3">
                    <span class="navbar-text text-white-50 small">
                        <i class="fas fa-user-circle me-1"></i>{{ $portalPartner->name }}
                    </span>
                </li>
                @endif
                <li class="nav-item">
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="btn btn-outline-light btn-sm">
                            <i class="fas fa-sign-out-alt me-1"></i>Sair
                        </button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
</nav>

<div class="container-fluid px-4 py-4">
    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @yield('content')
</div>

@yield('javascript')
</body>
</html>
