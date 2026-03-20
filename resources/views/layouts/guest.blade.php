<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ config('app.name', 'Laravel') }}</title>
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('system/images/favicon.png') }}">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <link href="{{ asset('system/css/pages/login-register-lock.css') }}" rel="stylesheet">
        <link href="{{ asset('system/css/style.min.css') }}" rel="stylesheet">
        <style>
            /* Permite scroll quando o conteúdo ultrapassa o viewport (ex: tela de registro) */
            .login-register {
                position: relative !important;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 2rem 1rem;
            }
            /* Deixa o card se ajustar ao conteúdo sem trancar em 400px */
            .login-box {
                width: auto !important;
                max-width: min(560px, calc(100vw - 2rem));
            }
        </style>
        @stack('styles')
        <!-- HTML5 Shim and Respond.js IE8 support of HTML5 elements and media queries -->
        <!-- WARNING: Respond.js doesn't work if you view the page via file:// -->
        <!--[if lt IE 9]>
        <script src="https://oss.maxcdn.com/libs/html5shiv/3.7.0/html5shiv.js"></script>
        <script src="https://oss.maxcdn.com/libs/respond.js/1.4.2/respond.min.js"></script>
        <![endif]-->
    </head>
    <body class="skin-default card-no-border">
        @include('components.preloader')
        <section id="wrapper">
            <div class="login-register"
                 style="background-image:url({{ asset('system/images/background/login-register.jpg') }});">
                <div class="login-box card">
                    @yield('content')

                    {{-- Seletor de idioma — dentro do card para garantir visibilidade --}}
                    @php $currentLocale = \App\Http\Middleware\SetLocale::getSupportedLocales()[app()->getLocale()] ?? null; @endphp
                    <div class="border-top px-3 py-2 d-flex justify-content-center">
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
        </section>
        <script src="{{ asset('system/plugins/jquery/dist/jquery.min.js') }}"></script>
        <script src="{{ asset('system/plugins/bootstrap/dist/js/bootstrap.bundle.min.js') }}"></script>
        <script type="text/javascript">
            $(function() {
                $(".preloader").fadeOut();
            });
            $(function() {
                $('[data-bs-toggle="tooltip"]').tooltip()
            });
        </script>
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
