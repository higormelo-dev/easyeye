<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title inertia>{{ config('app.name', 'EasyEye') }}</title>
    <!-- Favicon -->
    <link rel="shortcut icon" href="{{ asset('system/images/preclinic/favicon.png') }}">
    <!-- Theme Config Js (previne flash de tema claro/escuro) -->
    <script src="{{ asset('js/preclinic-theme-script.js') }}"></script>
    @viteReactRefresh
    @vite(['resources/css/vendor.css', 'resources/css/app.css', 'resources/js/app.jsx'])
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
