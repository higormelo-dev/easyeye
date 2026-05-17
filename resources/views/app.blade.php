<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-app="{{ config('app.name', 'EasyEye') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="shortcut icon" href="{{ Vite::asset('resources/img/system/favicon.png') }}">
    @inertiaHead
    @vite(['resources/css/site.scss', 'resources/js/site.js'])
</head>
<body>
    @inertia
</body>
</html>
