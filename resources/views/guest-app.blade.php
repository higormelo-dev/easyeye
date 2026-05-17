<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-bs-theme="light" data-app="{{ config('app.name', 'EasyEye') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="shortcut icon" href="{{ Vite::asset('resources/img/system/favicon.png') }}">
    <script>(function(){var t=localStorage.getItem('ee-guest-theme');if(t==='dark'){document.documentElement.setAttribute('data-bs-theme','dark');}})();</script>
    @inertiaHead
    @vite(['resources/css/vendor.css', 'resources/css/system.scss', 'resources/js/site.js'])
</head>
<body>
    @inertia
</body>
</html>
