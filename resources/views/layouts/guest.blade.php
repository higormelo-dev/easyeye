<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-sidebar="light" data-topbar="white" data-color="info">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'EasyEye') }}</title>
    <!-- Favicon -->
    <link rel="shortcut icon" href="{{ Vite::asset('resources/img/system/favicon.png') }}">
    <!-- Apply guest dark mode before paint to avoid flash -->
    <script>(function(){var t=localStorage.getItem('ee-guest-theme');if(t==='dark'){document.documentElement.setAttribute('data-bs-theme','dark');}})();</script>
    <!-- Theme Config Js (must run before paint to avoid flash) -->
    <script src="{{ asset('js/preclinic-theme-script.js') }}"></script>
    {{-- Vendor + App bundles (all CSS/JS via npm/Vite) --}}
    @vite(['resources/css/vendor.css', 'resources/js/vendor.js'])
    @stack('styles')
    <style>
        .ee-auth-shell {
            max-width: 560px;
        }

        .ee-auth-card {
            border: 1px solid #e7ebf3;
            border-radius: 16px;
            box-shadow: 0 14px 34px rgba(10, 27, 57, 0.08);
        }

        .ee-auth-header h4 {
            color: #0f172a;
            font-size: 1.45rem;
            font-weight: 700;
            margin-bottom: .35rem;
        }

        .ee-auth-header p {
            color: #6c7688;
            font-size: .92rem;
            margin-bottom: 0;
        }

        .ee-auth-form .form-label {
            color: #334155;
            font-size: .88rem;
            font-weight: 600;
            margin-bottom: .45rem;
        }

        .ee-auth-form .form-control,
        .ee-auth-form .form-select {
            border-radius: 10px;
            min-height: 42px;
        }

        .ee-auth-form .input-group-text {
            border-radius: 10px 0 0 10px;
        }

        .ee-auth-form .input-group > .form-control {
            border-radius: 0;
        }

        .ee-auth-form .input-group > .btn {
            border-radius: 0 10px 10px 0;
        }

        .ee-auth-hero .hero-title {
            color: #fff;
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: .5rem;
        }

        .ee-auth-hero .hero-text {
            color: #eef2ff;
            font-size: .95rem;
            margin-bottom: 0;
        }

        .ee-auth-hero-image {
            max-width: 420px;
        }

        .ee-login-illustration-wrapper {
            background: linear-gradient(90deg, #ebecf5 0 58%, #ffffff 58%);
        }

        .ee-auth-illustration-wrapper {
            background: linear-gradient(90deg, #ebecf5 0 58%, #ffffff 58%);
        }

        .ee-login-illustration-side {
            background: #ebecf5;
        }

        .ee-auth-illustration-side {
            background: #ebecf5;
        }

        .ee-login-illustration-image {
            width: 100%;
            max-width: 760px;
            padding: 2rem;
        }

        .ee-auth-illustration-image {
            width: 100%;
            max-width: 760px;
            padding: 2rem;
        }

        .ee-login-form-side {
            background: #ffffff;
        }

        .ee-auth-form-side {
            background: #ffffff;
        }

        .ee-login-shell {
            max-width: 640px;
        }

        .ee-auth-illustration-shell {
            max-width: 640px;
        }

        .ee-auth-brand {
            width: 190px;
        }

        .ee-auth-illustration-card {
            border: 1px solid #d8dce7;
            border-radius: 10px;
            box-shadow: none;
        }

        @media (max-width: 991.98px) {
            .ee-auth-shell {
                max-width: 640px;
            }

            .ee-login-illustration-wrapper {
                background: #ffffff;
            }

            .ee-auth-illustration-wrapper {
                background: #ffffff;
            }

            .ee-login-shell {
                max-width: 560px;
            }

            .ee-auth-illustration-shell {
                max-width: 560px;
            }
        }

        /* ── Dark mode icon state (CSS-driven, no flash) ── */
        .ee-dark-icon-sun { display: none; }
        [data-bs-theme="dark"] .ee-dark-icon-moon { display: none; }
        [data-bs-theme="dark"] .ee-dark-icon-sun  { display: inline; }

        /* ── Dark mode overrides for guest pages ── */
        [data-bs-theme="dark"] .main-wrapper.auth-bg { background: #0f1117; }

        [data-bs-theme="dark"] .ee-login-illustration-side,
        [data-bs-theme="dark"] .ee-auth-illustration-side { background: #1a1d2e; }

        [data-bs-theme="dark"] .ee-login-illustration-wrapper { background: linear-gradient(90deg, #1a1d2e 0 58%, #0f1117 58%); }
        [data-bs-theme="dark"] .ee-auth-illustration-wrapper  { background: linear-gradient(90deg, #1a1d2e 0 58%, #0f1117 58%); }

        @media (max-width: 991.98px) {
            [data-bs-theme="dark"] .ee-login-illustration-wrapper,
            [data-bs-theme="dark"] .ee-auth-illustration-wrapper { background: #0f1117; }
        }

        [data-bs-theme="dark"] .ee-login-form-side,
        [data-bs-theme="dark"] .ee-auth-form-side { background: #0f1117; }

        [data-bs-theme="dark"] .ee-auth-card,
        [data-bs-theme="dark"] .ee-auth-illustration-card { border-color: #2d3560; }

        [data-bs-theme="dark"] .ee-auth-header h4 { color: #e2e8f0; }
        [data-bs-theme="dark"] .ee-auth-header p  { color: #94a3b8; }

        [data-bs-theme="dark"] .ee-auth-form .form-label { color: #cbd5e1; }
    </style>
</head>
<body>
    @php
        $pageTitle    = trim($__env->yieldContent('auth_page_title')) ?: config('app.name', 'EasyEye');
        $pageSubtitle = trim($__env->yieldContent('auth_page_subtitle')) ?: '';
        $heroTitle    = trim($__env->yieldContent('auth_hero_title')) ?: config('app.name', 'EasyEye');
        $heroText     = trim($__env->yieldContent('auth_hero_text')) ?: __('Sistema médico completo para sua clínica');
        $heroImage    = trim($__env->yieldContent('auth_hero_image')) ?: Vite::asset('resources/img/system/auth/reg-illustration-img.png');
        $coverImage   = trim($__env->yieldContent('auth_cover_image')) ?: Vite::asset('resources/img/system/auth/cover-imgs-1.png');
        $authLayout   = trim($__env->yieldContent('auth_layout')) ?: 'default';
        $hideHeader   = trim($__env->yieldContent('auth_hide_header')) === '1';
        $hideLocale   = trim($__env->yieldContent('auth_hide_locale')) === '1';
        $isLoginIllustration = $authLayout === 'login-illustration';
        $isRegisterIllustration = $authLayout === 'register-illustration';
        $currentLocale = \App\Http\Middleware\SetLocale::getSupportedLocales()[app()->getLocale()] ?? null;
    @endphp

    <div class="main-wrapper auth-bg position-relative overflow-hidden min-vh-100 {{ $isLoginIllustration ? 'ee-login-illustration-wrapper' : ($isRegisterIllustration ? 'ee-auth-illustration-wrapper' : '') }}">
        <div class="container-fluid p-0">
            @if($isLoginIllustration)
                <div class="row g-0 min-vh-100">
                    <div class="col-lg-7 d-none d-lg-flex align-items-center justify-content-center ee-login-illustration-side">
                        @if(View::hasSection('auth_left_panel'))
                            @yield('auth_left_panel')
                        @else
                            <img src="{{ $heroImage }}" class="img-fluid ee-login-illustration-image" alt="{{ $heroTitle }}">
                        @endif
                    </div>
                    <div class="col-12 col-lg-5 d-flex align-items-center justify-content-center px-3 px-md-4 py-4 ee-login-form-side">
                        <div class="w-100 ee-auth-shell ee-login-shell">
                            @yield('content')
                        </div>
                    </div>
                </div>
            @elseif($isRegisterIllustration)
                <div class="row g-0 min-vh-100">
                    <div class="col-lg-7 d-none d-lg-flex align-items-center justify-content-center ee-auth-illustration-side">
                        @if(View::hasSection('auth_left_panel'))
                            @yield('auth_left_panel')
                        @else
                            <img src="{{ $heroImage }}" class="img-fluid ee-auth-illustration-image" alt="{{ $heroTitle }}">
                        @endif
                    </div>
                    <div class="col-12 col-lg-5 d-flex align-items-center justify-content-center px-3 px-md-4 py-4 ee-auth-form-side">
                        <div class="w-100 ee-auth-shell ee-auth-illustration-shell">
                            <div class="text-center mb-4">
                                <a href="{{ route('login') }}" class="d-inline-block">
                                    <img src="{{ Vite::asset('resources/img/system/icons/logo.svg') }}" class="img-fluid ee-auth-brand" alt="{{ config('app.name') }}">
                                </a>
                            </div>

                            <div class="card ee-auth-illustration-card">
                                <div class="card-body p-4 p-xl-5 ee-auth-form">
                                    @unless($hideHeader)
                                        <div class="text-center mb-4 ee-auth-header">
                                            <h4>{{ $pageTitle }}</h4>
                                            @if($pageSubtitle !== '')
                                                <p>{{ $pageSubtitle }}</p>
                                            @endif
                                        </div>
                                    @endunless

                                    @yield('content')

                                    <div class="border-top pt-3 mt-4 d-flex align-items-center justify-content-center gap-1">
                                        @unless($hideLocale)
                                            <div class="dropdown">
                                                <button type="button"
                                                        class="btn btn-link btn-sm text-muted text-decoration-none dropdown-toggle"
                                                        data-bs-toggle="dropdown" aria-expanded="false"
                                                        style="font-size:.8rem;">
                                                    @if($currentLocale)
                                                        {{ $currentLocale['flag'] }} {{ $currentLocale['native'] }}
                                                    @else
                                                        {{ app()->getLocale() }}
                                                    @endif
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end" style="min-width:0">
                                                    @foreach(\App\Http\Middleware\SetLocale::getSupportedLocales() as $code => $locale)
                                                        <li>
                                                            <a href="{{ route('locale.switch', $code) }}"
                                                               class="dropdown-item {{ app()->getLocale() === $code ? 'active fw-semibold' : '' }}"
                                                               style="font-size:.85rem;">
                                                                {{ $locale['flag'] }} {{ $locale['native'] }}
                                                            </a>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @endunless
                                        <button type="button"
                                                class="btn btn-link btn-sm text-muted p-1 ee-guest-dark-toggle"
                                                title="{{ __('actions.switch') }}"
                                                style="font-size:1rem; line-height:1;">
                                            <i class="ti ti-moon ee-dark-icon-moon"></i>
                                            <i class="ti ti-sun ee-dark-icon-sun"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <p class="text-center mt-3 mb-0 ee-guest-copy">
                                &copy; {{ \Carbon\Carbon::now()->year }} {{ config('app.name', 'EasyEye') }}
                            </p>
                        </div>
                    </div>
                </div>
            @else
                <div class="row g-0 min-vh-100">
                    <div class="col-12 col-lg-5 d-flex align-items-center justify-content-center px-3 px-md-4 py-4">
                        <div class="w-100 ee-auth-shell">
                            @unless($hideHeader)
                                <div class="text-center mb-4 ee-auth-header">
                                    <a href="{{ route('login') }}" class="d-inline-block mb-2">
                                        <img src="{{ Vite::asset('resources/img/system/logo.svg') }}" class="img-fluid" alt="{{ config('app.name') }}">
                                    </a>
                                    <h4>{{ $pageTitle }}</h4>
                                    @if($pageSubtitle !== '')
                                        <p>{{ $pageSubtitle }}</p>
                                    @endif
                                </div>
                            @endunless

                            <div class="card ee-auth-card">
                                <div class="card-body p-4 p-xl-5 ee-auth-form">
                                    @yield('content')

                                    <div class="border-top pt-3 mt-4 d-flex align-items-center justify-content-center gap-1">
                                        @unless($hideLocale)
                                            <div class="dropdown">
                                                <button type="button"
                                                        class="btn btn-link btn-sm text-muted text-decoration-none dropdown-toggle"
                                                        data-bs-toggle="dropdown" aria-expanded="false"
                                                        style="font-size:.8rem;">
                                                    @if($currentLocale)
                                                        {{ $currentLocale['flag'] }} {{ $currentLocale['native'] }}
                                                    @else
                                                        {{ app()->getLocale() }}
                                                    @endif
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end" style="min-width:0">
                                                    @foreach(\App\Http\Middleware\SetLocale::getSupportedLocales() as $code => $locale)
                                                        <li>
                                                            <a href="{{ route('locale.switch', $code) }}"
                                                               class="dropdown-item {{ app()->getLocale() === $code ? 'active fw-semibold' : '' }}"
                                                               style="font-size:.85rem;">
                                                                {{ $locale['flag'] }} {{ $locale['native'] }}
                                                            </a>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @endunless
                                        <button type="button"
                                                class="btn btn-link btn-sm text-muted p-1 ee-guest-dark-toggle"
                                                title="{{ __('actions.switch') }}"
                                                style="font-size:1rem; line-height:1;">
                                            <i class="ti ti-moon ee-dark-icon-moon"></i>
                                            <i class="ti ti-sun ee-dark-icon-sun"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <p class="text-dark text-center mt-3 mb-0">
                                &copy; {{ \Carbon\Carbon::now()->year }} {{ config('app.name', 'EasyEye') }}
                            </p>
                        </div>
                    </div>

                    <div class="col-lg-7 d-none d-lg-block position-relative login-backgrounds">
                        <img src="{{ Vite::asset('resources/img/system/auth/auth-bg-top.png') }}" alt="" class="element-01">
                        <img src="{{ Vite::asset('resources/img/system/auth/auth-bg-bot.png') }}" alt="" class="element-02">
                        <img src="{{ $coverImage }}" alt="" class="cover-img">
                        <div class="authentication-card h-100 d-flex align-items-center justify-content-center">
                            <div class="authen-overlay-item text-center mx-auto">
                                <div class="authen-head ee-auth-hero mb-4">
                                    <h2 class="hero-title">{{ $heroTitle }}</h2>
                                    <p class="hero-text">{{ $heroText }}</p>
                                </div>
                                <img src="{{ $heroImage }}" class="img-fluid ee-auth-hero-image authen-overlay-img" alt="{{ $heroTitle }}">
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- jQuery, Bootstrap, theme JS, plugins — all loaded via vendor.js (Vite) --}}

    {{-- Traduções para uso no JavaScript (Alpine.js) --}}
    <script>
        window._trans = {
            email_taken:        '{{ __('auth.register.email_taken') }}',
            field_required:     '{{ __('auth.register.field_required') }}',
            passwords_mismatch: '{{ __('auth.register.passwords_mismatch') }}',
        };
    </script>
    @stack('scripts')
    @yield('javascript')
    <script>
        (function () {
            document.querySelectorAll('.ee-guest-dark-toggle').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var html = document.documentElement;
                    var next = html.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
                    html.setAttribute('data-bs-theme', next);
                    localStorage.setItem('ee-guest-theme', next);
                });
            });
        })();
    </script>
</body>
</html>
