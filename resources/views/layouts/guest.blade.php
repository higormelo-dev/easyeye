<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ config('app.name', 'Laravel') }}</title>
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('system/images/favicon.png') }}}">
        <link href="{{ asset('system/css/pages/login-register-lock.css') }}" rel="stylesheet">
        <link href="{{ asset('system/css/style.min.css') }}" rel="stylesheet">
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
    </body>
</html>
