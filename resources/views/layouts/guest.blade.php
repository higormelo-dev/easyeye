<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-sidebar="light" data-topbar="white" data-color="info">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'EasyEye') }}</title>
    <!-- Favicon -->
    <link rel="shortcut icon" href="{{ asset('system/images/preclinic/favicon.png') }}">
    <!-- Theme Config Js (must run before paint to avoid flash) -->
    <script src="{{ asset('js/preclinic-theme-script.js') }}"></script>
    {{-- Vendor + App bundles (all CSS/JS via npm/Vite) --}}
    @vite(['resources/css/vendor.css', 'resources/js/vendor.js'])
    @stack('styles')
</head>
<body>
    <div class="main-wrapper auth-bg position-relative overflow-hidden">
        <!-- Start Content -->
        <div class="container-fuild position-relative z-1">
            <div class="w-100 overflow-hidden position-relative flex-wrap d-block vh-100">
                <div class="row justify-content-center align-items-center vh-100 overflow-auto flex-wrap py-3">
                    <div class="col-lg-4 mx-auto">
                        <div class="d-flex flex-column justify-content-lg-center p-4 p-lg-0 pb-0 flex-fill">
                            <div class="mx-auto mb-4 text-center">
                                <img src="{{ asset('system/images/preclinic/logo.svg') }}" class="img-fluid" alt="{{ config('app.name') }}">
                            </div>
                            <div class="card border-1 p-lg-3 shadow-md rounded-3 mb-4">
                                <div class="card-body">
                                    @yield('content')

                                    {{-- Seletor de idioma --}}
                                    @php $currentLocale = \App\Http\Middleware\SetLocale::getSupportedLocales()[app()->getLocale()] ?? null; @endphp
                                    <div class="border-top pt-3 mt-3 d-flex justify-content-center">
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
                                    </div>
                                </div>
                            </div>
                            <p class="text-dark text-center">
                                &copy; {{ \Carbon\Carbon::now()->year }} {{ config('app.name', 'EasyEye') }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- End Content -->
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
</body>
</html>
