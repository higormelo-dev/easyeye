<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-app="{{ config('app.name', 'EasyEye') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- ───────────────────────────────────────────────────────────────────
         SEO + Social previews (WhatsApp, LinkedIn, Telegram, Twitter, etc.)

         O Inertia SSR está desligado em produção (hosting compartilhado),
         então o crawler do WhatsApp baixa este HTML estático. Por isso as
         tags precisam estar no blade — não dá pra delegar pro Vue.

         Estratégia LGPD: rootView `app` só serve páginas PÚBLICAS (landing,
         marketing). Páginas privadas usam guest-app/panel-app/portal-app
         que têm `noindex,nofollow` próprios. Aqui é seguro deixar indexável.
    ────────────────────────────────────────────────────────────────────── --}}
    @php
        $seoTitle       = __('site.meta.title');
        $seoDescription = __('site.meta.description');
        $ogTitle        = __('site.meta.og_title');
        $ogDescription  = __('site.meta.og_description');
        $ogImage        = asset('images/og-preview.jpg');
        $ogLocale       = app()->getLocale() === 'en' ? 'en_US' : 'pt_BR';
        $canonicalUrl   = url()->current();
    @endphp

    <title>{{ $seoTitle }}</title>
    <meta name="description" content="{{ $seoDescription }}">
    <link rel="canonical" href="{{ $canonicalUrl }}">
    <meta name="theme-color" content="#35a9e1">

    {{-- Open Graph (Facebook / WhatsApp / LinkedIn / Telegram / Slack) --}}
    <meta property="og:type"            content="website">
    <meta property="og:site_name"       content="{{ config('app.name', 'EasyEye') }}">
    <meta property="og:title"           content="{{ $ogTitle }}">
    <meta property="og:description"     content="{{ $ogDescription }}">
    <meta property="og:url"             content="{{ $canonicalUrl }}">
    <meta property="og:image"           content="{{ $ogImage }}">
    <meta property="og:image:width"     content="1024">
    <meta property="og:image:height"    content="1024">
    <meta property="og:image:alt"       content="{{ $ogTitle }}">
    <meta property="og:locale"          content="{{ $ogLocale }}">
    <meta property="og:locale:alternate" content="{{ $ogLocale === 'pt_BR' ? 'en_US' : 'pt_BR' }}">

    {{-- Twitter Card --}}
    <meta name="twitter:card"        content="summary_large_image">
    <meta name="twitter:title"       content="{{ $ogTitle }}">
    <meta name="twitter:description" content="{{ $ogDescription }}">
    <meta name="twitter:image"       content="{{ $ogImage }}">
    <meta name="twitter:image:alt"   content="{{ $ogTitle }}">

    {{-- Favicon: SVG escalonável (browsers modernos) + ICO fallback + PNG 192 PWA --}}
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="alternate icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="apple-touch-icon" sizes="192x192" href="{{ asset('favicon-192.png') }}">
    @routes
    @inertiaHead
    @vite(['resources/css/site.scss', 'resources/js/site.js'])
</head>
<body>
    @inertia
</body>
</html>
