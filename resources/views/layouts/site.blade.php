<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="@yield('description', __('site.meta.description'))">
    <meta name="robots" content="index, follow">
    <meta property="og:title"       content="@yield('og_title', __('site.meta.og_title'))">
    <meta property="og:description" content="@yield('og_description', __('site.meta.og_description'))">
    <meta property="og:type"        content="website">
    <title>@yield('title', __('site.meta.title'))</title>
    <link rel="shortcut icon" href="{{ asset('system/images/favicon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    @section('alpinejs')
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    @show
    <style>
        /* ─── RESET & VARIABLES ─── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --navy:      #0F2551;
            --navy-mid:  #1B3A6B;
            --blue:      #1E5EBF;
            --teal:      #00B4D8;
            --teal-lt:   #90E0EF;
            --mint:      #06D6A0;
            --bg:        #F4F7FB;
            --bg-white:  #FFFFFF;
            --text:      #1A202C;
            --text-muted:#64748B;
            --border:    #E2E8F0;
            --radius:    12px;
            --radius-lg: 20px;
            --shadow:    0 4px 24px rgba(15,37,81,.08);
            --shadow-lg: 0 12px 48px rgba(15,37,81,.14);
        }

        html { scroll-behavior: smooth; }

        body {
            font-family: 'Inter', system-ui, sans-serif;
            color: var(--text);
            background: var(--bg-white);
            -webkit-font-smoothing: antialiased;
        }

        /* ─── UTILITIES ─── */
        .container { max-width: 1200px; margin: 0 auto; padding: 0 24px; }
        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 12px 24px; border-radius: 8px; font-weight: 600;
            font-size: 15px; cursor: pointer; border: none;
            text-decoration: none; transition: all .2s;
        }
        .btn-primary { background: var(--teal); color: #fff; }
        .btn-primary:hover { background: #0096b5; transform: translateY(-1px); box-shadow: 0 6px 20px rgba(0,180,216,.35); }
        .btn-outline { background: transparent; color: var(--navy); border: 2px solid var(--navy-mid); }
        .btn-outline:hover { background: var(--navy); color: #fff; }
        .btn-outline-white { background: transparent; color: #fff; border: 2px solid rgba(255,255,255,.5); }
        .btn-outline-white:hover { background: rgba(255,255,255,.1); border-color: #fff; }
        .btn-lg { padding: 16px 32px; font-size: 16px; }
        .badge-pill {
            display: inline-flex; align-items: center; gap: 6px;
            background: rgba(0,180,216,.12); color: var(--teal);
            border: 1px solid rgba(0,180,216,.25);
            padding: 6px 14px; border-radius: 999px;
            font-size: 13px; font-weight: 600;
        }
        .section-label {
            display: inline-block;
            background: rgba(0,180,216,.1); color: var(--blue);
            padding: 4px 14px; border-radius: 999px;
            font-size: 12px; font-weight: 700; letter-spacing: .06em;
            text-transform: uppercase; margin-bottom: 12px;
        }
        .section-title {
            font-size: clamp(28px, 4vw, 44px);
            font-weight: 800; line-height: 1.15;
            color: var(--navy); margin-bottom: 16px;
        }
        .section-sub {
            font-size: 17px; color: var(--text-muted);
            line-height: 1.7; max-width: 600px;
        }
        .text-center { text-align: center; }
        .text-center .section-sub { margin: 0 auto; }

        /* ─── NAVBAR ─── */
        #navbar {
            position: fixed; top: 0; left: 0; right: 0; z-index: 1000;
            padding: 16px 0; transition: background .35s, box-shadow .35s, padding .35s;
        }
        #navbar.scrolled {
            background: rgba(255,255,255,.97);
            backdrop-filter: blur(12px);
            box-shadow: 0 2px 20px rgba(15,37,81,.08);
            padding: 12px 0;
        }
        .nav-inner {
            display: flex; align-items: center; justify-content: space-between; gap: 16px;
        }

        /* Logo — duas versões: escura (scrolled) e branca (topo) */
        .nav-logo {
            display: flex; align-items: center; gap: 10px;
            text-decoration: none; font-weight: 800; font-size: 22px;
            color: var(--navy); transition: color .35s;
        }
        .nav-logo img { height: 32px; }
        .nav-logo span { color: var(--teal); }
        .logo-v-dark  { display: block; }
        .logo-v-white { display: none; }

        /* Estado inicial — navbar transparente sobre hero escuro */
        #navbar:not(.scrolled) .nav-logo { color: #fff; }
        #navbar:not(.scrolled) .logo-v-dark  { display: none; }
        #navbar:not(.scrolled) .logo-v-white { display: block; }

        /* Links */
        .nav-links { display: flex; align-items: center; gap: 4px; list-style: none; }
        .nav-links a {
            padding: 8px 14px; border-radius: 6px;
            color: #3a4a6b; font-weight: 500; font-size: 15px;
            text-decoration: none; transition: color .2s, background .2s;
        }
        .nav-links a:hover { color: var(--navy); background: rgba(15,37,81,.06); }
        #navbar:not(.scrolled) .nav-links a { color: rgba(255,255,255,.85); }
        #navbar:not(.scrolled) .nav-links a:hover { color: #fff; background: rgba(255,255,255,.1); }

        .nav-right { display: flex; align-items: center; gap: 10px; }

        /* Language switcher */
        .lang-switcher { position: relative; }
        .lang-btn {
            display: flex; align-items: center; gap: 6px;
            background: none; border: none; cursor: pointer;
            font-size: 20px; padding: 6px 8px; border-radius: 6px;
            color: var(--text-muted); transition: background .2s, color .2s;
        }
        .lang-btn:hover { background: rgba(15,37,81,.06); color: var(--navy); }
        #navbar:not(.scrolled) .lang-btn { color: rgba(255,255,255,.75); }
        #navbar:not(.scrolled) .lang-btn:hover { color: #fff; background: rgba(255,255,255,.12); }

        .lang-dropdown {
            position: absolute; top: calc(100% + 8px); right: 0;
            background: #fff; border: 1px solid var(--border);
            border-radius: var(--radius); box-shadow: var(--shadow-lg);
            min-width: 160px; padding: 6px; z-index: 100;
        }
        .lang-item {
            display: flex; align-items: center; gap: 8px;
            padding: 9px 12px; border-radius: 6px;
            font-size: 14px; font-weight: 500; color: var(--text);
            text-decoration: none; transition: background .15s;
        }
        .lang-item:hover { background: var(--bg); }
        .lang-item.active { background: rgba(0,180,216,.08); color: var(--teal); font-weight: 600; }
        .lang-item .check { margin-left: auto; font-size: 14px; color: var(--teal); }

        /* Botão Entrar — estado inicial: ghost branco chamativo */
        #navbar:not(.scrolled) .nav-right .btn-outline {
            color: #fff;
            border-color: rgba(255,255,255,.5);
            background: rgba(255,255,255,.12);
        }
        #navbar:not(.scrolled) .nav-right .btn-outline:hover {
            color: #fff;
            border-color: rgba(255,255,255,.85);
            background: rgba(255,255,255,.22);
            transform: translateY(-1px);
            box-shadow: 0 4px 16px rgba(0,0,0,.18);
        }

        /* Botão mobile */
        .nav-mobile-btn {
            display: none; background: none; border: none; cursor: pointer;
            color: var(--navy); font-size: 24px; transition: color .2s;
        }
        #navbar:not(.scrolled) .nav-mobile-btn { color: rgba(255,255,255,.9); }

        .nav-mobile-menu {
            display: none; position: fixed; top: 70px; left: 0; right: 0;
            background: #fff; box-shadow: 0 20px 40px rgba(15,37,81,.12);
            padding: 16px 24px 24px; border-top: 1px solid var(--border);
            z-index: 999;
        }
        .nav-mobile-menu.open { display: block; }
        .nav-mobile-menu ul { list-style: none; }
        .nav-mobile-menu ul li a {
            display: block; padding: 12px 0; color: var(--text);
            font-weight: 500; text-decoration: none; font-size: 16px;
            border-bottom: 1px solid var(--border);
        }
        .mobile-lang {
            display: flex; gap: 8px; flex-wrap: wrap; padding: 12px 0;
            border-bottom: 1px solid var(--border);
        }
        .mobile-lang a {
            display: flex; align-items: center; gap: 6px;
            padding: 6px 12px; border-radius: 999px; font-size: 13px; font-weight: 600;
            border: 1.5px solid var(--border); color: var(--text-muted); text-decoration: none;
            transition: all .18s;
        }
        .mobile-lang a.active,
        .mobile-lang a:hover { border-color: var(--teal); color: var(--teal); background: rgba(0,180,216,.06); }
        .mobile-ctas { display: flex; flex-direction: column; gap: 10px; margin-top: 16px; }

        @media (max-width: 900px) {
            .nav-links, .nav-right { display: none; }
            .nav-mobile-btn { display: block; }
        }

        /* ─── FOOTER ─── */
        footer { background: #0a1628; padding: 64px 0 32px; }
        .footer-inner {
            display: grid; grid-template-columns: 2fr 1fr 1fr 1fr;
            gap: 40px; margin-bottom: 48px;
        }
        .footer-brand .nav-logo { color: #fff; margin-bottom: 16px; }
        .footer-brand p { font-size: 14px; color: rgba(255,255,255,.45); line-height: 1.7; max-width: 280px; }
        .footer-social { display: flex; gap: 10px; margin-top: 20px; }
        .footer-social a {
            width: 36px; height: 36px; border-radius: 8px;
            background: rgba(255,255,255,.06); color: rgba(255,255,255,.5);
            display: flex; align-items: center; justify-content: center;
            font-size: 16px; transition: all .2s; text-decoration: none;
        }
        .footer-social a:hover { background: var(--teal); color: #fff; }
        .footer-col h5 {
            font-size: 13px; font-weight: 700; color: rgba(255,255,255,.9);
            text-transform: uppercase; letter-spacing: .06em; margin-bottom: 16px;
        }
        .footer-col ul { list-style: none; display: flex; flex-direction: column; gap: 10px; }
        .footer-col ul a { font-size: 14px; color: rgba(255,255,255,.45); text-decoration: none; transition: color .18s; }
        .footer-col ul a:hover { color: var(--teal); }
        .footer-bottom {
            border-top: 1px solid rgba(255,255,255,.08); padding-top: 24px;
            display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap;
        }
        .footer-bottom p { font-size: 13px; color: rgba(255,255,255,.3); }
        .footer-legal { display: flex; gap: 20px; }
        .footer-legal a { font-size: 13px; color: rgba(255,255,255,.3); text-decoration: none; }
        .footer-legal a:hover { color: rgba(255,255,255,.6); }
        @media (max-width: 900px) {
            .footer-inner { grid-template-columns: 1fr 1fr; }
            .footer-brand { grid-column: 1 / -1; }
        }
        @media (max-width: 500px) {
            .footer-inner { grid-template-columns: 1fr; }
            .footer-bottom { flex-direction: column; text-align: center; }
        }
    </style>
    @stack('styles')
</head>
<body>

    {{-- ═══════════════════ NAVBAR ═══════════════════ --}}
    <nav id="navbar" x-data="{ open: false }">
        <div class="container">
            <div class="nav-inner">
                <a href="{{ route('site.home') }}" class="nav-logo">
                    <img src="{{ asset('system/images/logo-small.svg') }}"
                         alt="{{ config('app.name', 'EasyEye') }}"
                         class="logo-v-dark">
                    <img src="{{ asset('system/images/logo-white.svg') }}"
                         alt="{{ config('app.name', 'EasyEye') }}"
                         class="logo-v-white">
                    Easy<span>Eye</span>
                </a>

                <ul class="nav-links">
                    <li><a href="{{ route('site.home') }}#funcionalidades">{{ __('site.nav.features') }}</a></li>
                    <li><a href="{{ route('site.home') }}#como-funciona">{{ __('site.nav.how') }}</a></li>
                    <li><a href="{{ route('site.home') }}#precos">{{ __('site.nav.pricing') }}</a></li>
                    <li><a href="{{ route('site.home') }}#depoimentos">{{ __('site.nav.testimonials') }}</a></li>
                    <li><a href="{{ route('site.home') }}#faq">{{ __('site.nav.faq') }}</a></li>
                </ul>

                <div class="nav-right">
                    {{-- Seletor de idioma --}}
                    @php $locales = \App\Http\Middleware\SetLocale::getSupportedLocales(); $currentLocale = app()->getLocale(); @endphp
                    <div class="lang-switcher" x-data="{ langOpen: false }">
                        <button class="lang-btn" @click="langOpen = !langOpen" :aria-expanded="langOpen"
                                title="{{ __('site.nav.language') }}">
                            <span>{{ $locales[$currentLocale]['flag'] ?? '🌐' }}</span>
                        </button>
                        <div class="lang-dropdown" x-show="langOpen" x-cloak @click.outside="langOpen = false"
                             x-transition:enter="transition ease-out duration-150"
                             x-transition:enter-start="opacity-0 scale-95"
                             x-transition:enter-end="opacity-100 scale-100"
                             x-transition:leave="transition ease-in duration-100"
                             x-transition:leave-start="opacity-100 scale-100"
                             x-transition:leave-end="opacity-0 scale-95">
                            @foreach($locales as $code => $locale)
                                <a href="{{ route('locale.switch', $code) }}"
                                   class="lang-item {{ $currentLocale === $code ? 'active' : '' }}">
                                    <span>{{ $locale['flag'] }}</span>
                                    {{ $locale['native'] }}
                                    @if($currentLocale === $code)
                                        <i class="bi bi-check2 check"></i>
                                    @endif
                                </a>
                            @endforeach
                        </div>
                    </div>

                    <a href="{{ route('go') }}" class="btn btn-outline" style="padding:10px 20px;font-size:14px;">
                        <i class="bi bi-box-arrow-in-right"></i> {{ __('site.nav.login') }}
                    </a>
                    <a href="{{ route('register') }}" class="btn btn-primary" style="padding:10px 20px;font-size:14px;">
                        {{ __('site.nav.get_started') }} <i class="bi bi-arrow-right"></i>
                    </a>
                </div>

                <button class="nav-mobile-btn" @click="open = !open" :aria-expanded="open">
                    <i class="bi" :class="open ? 'bi-x-lg' : 'bi-list'"></i>
                </button>
            </div>
        </div>

        <div class="nav-mobile-menu" :class="open ? 'open' : ''">
            <ul>
                <li><a href="{{ route('site.home') }}#funcionalidades" @click="open=false">{{ __('site.nav.features') }}</a></li>
                <li><a href="{{ route('site.home') }}#como-funciona"   @click="open=false">{{ __('site.nav.how') }}</a></li>
                <li><a href="{{ route('site.home') }}#precos"          @click="open=false">{{ __('site.nav.pricing') }}</a></li>
                <li><a href="{{ route('site.home') }}#depoimentos"     @click="open=false">{{ __('site.nav.testimonials') }}</a></li>
                <li><a href="{{ route('site.home') }}#faq"             @click="open=false">{{ __('site.nav.faq') }}</a></li>
            </ul>

            {{-- Idiomas no mobile --}}
            <div class="mobile-lang">
                @foreach($locales as $code => $locale)
                    <a href="{{ route('locale.switch', $code) }}"
                       class="{{ $currentLocale === $code ? 'active' : '' }}">
                        {{ $locale['flag'] }} {{ $locale['native'] }}
                    </a>
                @endforeach
            </div>

            <div class="mobile-ctas">
                <a href="{{ route('go') }}" class="btn btn-outline" style="justify-content:center;">
                    <i class="bi bi-box-arrow-in-right"></i> {{ __('site.nav.login') }}
                </a>
                <a href="{{ route('register') }}" class="btn btn-primary" style="justify-content:center;">
                    {{ __('site.nav.get_started') }} <i class="bi bi-arrow-right"></i>
                </a>
            </div>
        </div>
    </nav>
    {{-- ═══════════════════ END NAVBAR ═══════════════════ --}}

    @yield('content')

    {{-- ═══════════════════ FOOTER ═══════════════════ --}}
    <footer>
        <div class="container">
            <div class="footer-inner">
                <div class="footer-brand">
                    <a href="{{ route('site.home') }}" class="nav-logo">
                        <img src="{{ asset('system/images/logo-small.svg') }}" alt="{{ config('app.name', 'EasyEye') }}">
                        Easy<span>Eye</span>
                    </a>
                    <p>{{ __('site.footer.tagline') }}</p>
                    <div class="footer-social">
                        <a href="#" aria-label="Instagram"><i class="bi bi-instagram"></i></a>
                        <a href="#" aria-label="LinkedIn"><i class="bi bi-linkedin"></i></a>
                        <a href="#" aria-label="YouTube"><i class="bi bi-youtube"></i></a>
                        <a href="#" aria-label="WhatsApp"><i class="bi bi-whatsapp"></i></a>
                    </div>
                </div>

                <div class="footer-col">
                    <h5>{{ __('site.footer.product') }}</h5>
                    <ul>
                        <li><a href="{{ route('site.home') }}#funcionalidades">{{ __('site.nav.features') }}</a></li>
                        <li><a href="{{ route('site.home') }}#precos">{{ __('site.nav.pricing') }}</a></li>
                        <li><a href="{{ route('site.home') }}#como-funciona">{{ __('site.nav.how') }}</a></li>
                        <li><a href="{{ route('site.home') }}#depoimentos">{{ __('site.nav.testimonials') }}</a></li>
                        <li><a href="{{ route('site.home') }}#faq">{{ __('site.nav.faq') }}</a></li>
                    </ul>
                </div>

                <div class="footer-col">
                    <h5>{{ __('site.footer.system') }}</h5>
                    <ul>
                        <li><a href="{{ route('go') }}">{{ __('site.footer.login') }}</a></li>
                        <li><a href="{{ route('register') }}">{{ __('site.footer.register') }}</a></li>
                        <li><a href="#">{{ __('site.footer.help') }}</a></li>
                        <li><a href="#">{{ __('site.footer.status') }}</a></li>
                        <li><a href="#">{{ __('site.footer.api') }}</a></li>
                    </ul>
                </div>

                <div class="footer-col">
                    <h5>{{ __('site.footer.company') }}</h5>
                    <ul>
                        <li><a href="#">{{ __('site.footer.about') }}</a></li>
                        <li><a href="#">{{ __('site.footer.blog') }}</a></li>
                        <li><a href="#">{{ __('site.footer.partners') }}</a></li>
                        <li><a href="mailto:contato@easyeye.com.br">{{ __('site.footer.contact') }}</a></li>
                        <li><a href="#">{{ __('site.footer.careers') }}</a></li>
                    </ul>
                </div>
            </div>

            <div class="footer-bottom">
                <p>{{ __('site.footer.copyright', ['year' => date('Y'), 'name' => config('app.name', 'EasyEye')]) }}</p>
                <div class="footer-legal">
                    <a href="#">{{ __('site.footer.privacy') }}</a>
                    <a href="#">{{ __('site.footer.terms') }}</a>
                    <a href="#">{{ __('site.footer.lgpd') }}</a>
                </div>
            </div>
        </div>
    </footer>
    {{-- ═══════════════════ END FOOTER ═══════════════════ --}}

    <script>
        (function () {
            const navbar  = document.getElementById('navbar');
            const hasHero = !!document.querySelector('.hero');

            // Páginas sem hero escuro (ex: /register) iniciam com navbar opaco
            if (!hasHero) navbar.classList.add('scrolled');

            window.addEventListener('scroll', function () {
                if (hasHero) navbar.classList.toggle('scrolled', window.scrollY > 20);
            }, { passive: true });
        })();
    </script>
    @stack('scripts')
</body>
</html>
