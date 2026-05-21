<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-bs-theme="light" data-app="{{ config('app.name', 'EasyEye') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Favicon: SVG escalonável (browsers modernos) + ICO fallback + PNG 192 PWA --}}
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="alternate icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="apple-touch-icon" sizes="192x192" href="{{ asset('favicon-192.png') }}">
    <script>(function(){var t=localStorage.getItem('ee-guest-theme');if(t==='dark'){document.documentElement.setAttribute('data-bs-theme','dark');}})();</script>
    @inertiaHead
    @vite(['resources/css/vendor.css', 'resources/css/system.scss', 'resources/js/site.js'])
</head>
<body>
    @inertia
</body>
</html>
