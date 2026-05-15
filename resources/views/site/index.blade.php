@extends('layouts.site')

@section('title', __('site.meta.title'))
@section('description', __('site.meta.description'))
@section('og_title', __('site.meta.og_title'))
@section('og_description', __('site.meta.og_description'))

@push('styles')
<style>
    /* ─── HERO ─── */
    .hero {
        padding: 140px 0 80px;
        background: linear-gradient(135deg, #0F2551 0%, #1B3A6B 50%, #0F2551 100%);
        position: relative; overflow: hidden;
    }
    .hero::before {
        content: '';
        position: absolute; inset: 0;
        background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.025'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
    }
    .hero-blob { position: absolute; border-radius: 50%; filter: blur(80px); opacity: .25; }
    .hero-blob-1 { width: 500px; height: 500px; top: -100px; right: -100px; background: var(--teal); }
    .hero-blob-2 { width: 300px; height: 300px; bottom: -50px; left: 10%; background: #6c63ff; }
    .hero-inner {
        display: grid; grid-template-columns: 1fr 1fr;
        gap: 60px; align-items: center; position: relative; z-index: 1;
    }
    .hero-text .badge-pill { margin-bottom: 20px; }
    .hero-title {
        font-size: clamp(32px, 5vw, 58px); font-weight: 900; color: #fff;
        line-height: 1.08; letter-spacing: -.02em; margin-bottom: 20px;
    }
    .hero-title em { font-style: normal; color: var(--teal); }
    .hero-sub { font-size: 18px; color: rgba(255,255,255,.75); line-height: 1.7; margin-bottom: 36px; }
    .hero-ctas { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 40px; }
    .hero-trust { display: flex; align-items: center; gap: 12px; font-size: 14px; color: rgba(255,255,255,.6); }
    .hero-trust-avatars { display: flex; }
    .hero-trust-avatars span {
        width: 32px; height: 32px; border-radius: 50%;
        background: linear-gradient(135deg, var(--teal), #6c63ff);
        border: 2px solid rgba(255,255,255,.4);
        display: flex; align-items: center; justify-content: center;
        font-size: 11px; color: #fff; font-weight: 700; margin-right: -8px;
    }
    .hero-visual { position: relative; }
    .hero-mockup {
        background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.12);
        border-radius: var(--radius-lg); overflow: hidden; box-shadow: 0 32px 80px rgba(0,0,0,.4);
    }
    .mockup-bar {
        display: flex; align-items: center; gap: 6px; padding: 12px 16px;
        background: rgba(255,255,255,.08); border-bottom: 1px solid rgba(255,255,255,.08);
    }
    .mockup-dot { width: 10px; height: 10px; border-radius: 50%; }
    .mockup-dot-r { background: #ff5f57; }
    .mockup-dot-y { background: #ffbd2e; }
    .mockup-dot-g { background: #28c840; }
    .mockup-url { flex: 1; background: rgba(255,255,255,.08); border-radius: 4px; height: 22px; margin: 0 8px; }
    .mockup-body { padding: 16px; }
    .mockup-header-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
    .mockup-h-block { height: 14px; border-radius: 4px; background: rgba(255,255,255,.15); }
    .mockup-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 14px; }
    .mockup-stat { background: rgba(255,255,255,.07); border-radius: 8px; padding: 10px; border: 1px solid rgba(255,255,255,.08); }
    .mockup-stat-val { height: 18px; background: var(--teal); border-radius: 4px; opacity: .7; margin-bottom: 6px; }
    .mockup-stat-lbl { height: 8px; background: rgba(255,255,255,.2); border-radius: 3px; width: 70%; }
    .mockup-chart {
        background: rgba(255,255,255,.05); border-radius: 10px; height: 110px;
        display: flex; align-items: flex-end; padding: 10px; gap: 6px;
        border: 1px solid rgba(255,255,255,.06); margin-bottom: 14px;
    }
    .mockup-bar-item { flex: 1; border-radius: 3px 3px 0 0; background: linear-gradient(180deg, var(--teal) 0%, rgba(0,180,216,.3) 100%); }
    .mockup-table-row { display: flex; gap: 8px; margin-bottom: 6px; align-items: center; }
    .mockup-avatar { width: 24px; height: 24px; border-radius: 50%; background: rgba(0,180,216,.4); flex-shrink: 0; }
    .mockup-line { height: 8px; background: rgba(255,255,255,.15); border-radius: 3px; flex: 1; }
    .mockup-line-sm { width: 40px; height: 8px; background: rgba(6,214,160,.3); border-radius: 3px; flex-shrink: 0; }
    .hero-float-card {
        position: absolute; background: #fff; border-radius: 12px;
        padding: 12px 16px; box-shadow: 0 8px 30px rgba(0,0,0,.2);
        display: flex; align-items: center; gap: 10px;
        font-size: 13px; font-weight: 600; color: var(--navy);
        animation: floatCard 3s ease-in-out infinite;
    }
    .hero-float-card .icon { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 16px; }
    .card-top    { top: -20px; left: -30px; animation-delay: 0s; }
    .card-bottom { bottom: -16px; right: -20px; animation-delay: 1.5s; }
    @keyframes floatCard {
        0%,100% { transform: translateY(0); }
        50%      { transform: translateY(-8px); }
    }
    @media (max-width: 768px) {
        .hero-inner { grid-template-columns: 1fr; gap: 40px; }
        .hero-visual { display: none; }
        .hero { padding: 120px 0 60px; }
    }

    /* ─── METRICS ─── */
    .metrics { background: linear-gradient(90deg, var(--navy) 0%, var(--navy-mid) 100%); padding: 40px 0; }
    .metrics-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0; text-align: center; }
    .metric-item { padding: 20px; border-right: 1px solid rgba(255,255,255,.12); }
    .metric-item:last-child { border-right: none; }
    .metric-value { font-size: 36px; font-weight: 900; color: var(--teal); line-height: 1; margin-bottom: 6px; }
    .metric-label { font-size: 14px; color: rgba(255,255,255,.65); font-weight: 500; }
    @media (max-width: 640px) {
        .metrics-grid { grid-template-columns: repeat(2, 1fr); }
        .metric-item:nth-child(2) { border-right: none; }
    }

    /* ─── FEATURES ─── */
    .features { padding: 100px 0; background: var(--bg); }
    .features-header { text-align: center; margin-bottom: 60px; }
    .features-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; }
    .feature-card {
        background: var(--bg-white); border-radius: var(--radius-lg);
        padding: 32px; border: 1px solid var(--border); transition: all .25s;
    }
    .feature-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-lg); border-color: rgba(0,180,216,.3); }
    .feature-icon { width: 52px; height: 52px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 24px; margin-bottom: 20px; }
    .icon-blue   { background: rgba(30,94,191,.1);  color: var(--blue); }
    .icon-teal   { background: rgba(0,180,216,.1);  color: var(--teal); }
    .icon-mint   { background: rgba(6,214,160,.1);  color: var(--mint); }
    .icon-purple { background: rgba(108,99,255,.1); color: #6c63ff; }
    .icon-orange { background: rgba(251,146,60,.1); color: #f97316; }
    .icon-red    { background: rgba(239,68,68,.1);  color: #ef4444; }
    .feature-card h3 { font-size: 18px; font-weight: 700; color: var(--navy); margin-bottom: 10px; }
    .feature-card p  { font-size: 15px; color: var(--text-muted); line-height: 1.65; }
    @media (max-width: 900px) { .features-grid { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 600px) { .features-grid { grid-template-columns: 1fr; } }

    /* ─── HOW IT WORKS ─── */
    .how { padding: 100px 0; background: var(--bg-white); }
    .how-inner { display: grid; grid-template-columns: 1fr 1fr; gap: 80px; align-items: center; }
    .how-steps { display: flex; flex-direction: column; gap: 0; }
    .how-step { display: flex; gap: 20px; padding: 24px 0; border-bottom: 1px solid var(--border); cursor: pointer; }
    .how-step:last-child { border-bottom: none; }
    .how-step-num {
        width: 44px; height: 44px; border-radius: 12px;
        background: var(--bg); border: 2px solid var(--border);
        display: flex; align-items: center; justify-content: center;
        font-size: 18px; font-weight: 800; color: var(--text-muted);
        flex-shrink: 0; transition: all .2s;
    }
    .how-step.active .how-step-num { background: var(--navy); border-color: var(--navy); color: #fff; }
    .how-step-content h4 { font-size: 17px; font-weight: 700; color: var(--navy); margin-bottom: 6px; }
    .how-step-content p  { font-size: 15px; color: var(--text-muted); line-height: 1.6; }
    .how-visual {
        background: var(--bg); border-radius: var(--radius-lg);
        overflow: hidden; border: 1px solid var(--border); box-shadow: var(--shadow-lg);
        min-height: 400px; display: flex; align-items: center; justify-content: center;
    }
    .how-visual img { width: 100%; height: 100%; object-fit: cover; }
    .how-visual-placeholder { text-align: center; padding: 40px; color: var(--text-muted); }
    .how-visual-placeholder i { font-size: 64px; color: var(--border); display: block; margin-bottom: 12px; }
    @media (max-width: 900px) { .how-inner { grid-template-columns: 1fr; gap: 40px; } .how-visual { min-height: 250px; } }

    /* ─── COMPLIANCE ─── */
    .compliance { padding: 80px 0; background: var(--bg-white); }
    .compliance-inner { display: grid; grid-template-columns: 1fr 1fr; gap: 60px; align-items: center; }
    .compliance-badges { display: flex; flex-wrap: wrap; gap: 14px; }
    .comp-badge {
        display: flex; align-items: center; gap: 10px;
        background: var(--bg); border: 1px solid var(--border);
        border-radius: 10px; padding: 12px 18px;
        font-size: 14px; font-weight: 600; color: var(--navy);
    }
    .comp-badge i { font-size: 20px; color: var(--mint); }
    @media (max-width: 768px) { .compliance-inner { grid-template-columns: 1fr; gap: 32px; } }

    /* ─── TESTIMONIALS ─── */
    .testimonials { padding: 100px 0; background: var(--bg); }
    .testimonials-header { text-align: center; margin-bottom: 60px; }
    .testimonials-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; }
    .testimonial-card { background: #fff; border-radius: var(--radius-lg); padding: 28px; border: 1px solid var(--border); box-shadow: var(--shadow); }
    .testimonial-stars { color: #f59e0b; font-size: 14px; margin-bottom: 14px; }
    .testimonial-text { font-size: 15px; color: var(--text); line-height: 1.7; margin-bottom: 20px; font-style: italic; }
    .testimonial-author { display: flex; align-items: center; gap: 12px; }
    .testimonial-avatar {
        width: 44px; height: 44px; border-radius: 50%;
        background: linear-gradient(135deg, var(--navy), var(--teal));
        display: flex; align-items: center; justify-content: center;
        color: #fff; font-weight: 700; font-size: 16px; flex-shrink: 0;
    }
    .testimonial-name { font-weight: 700; font-size: 14px; color: var(--navy); }
    .testimonial-role { font-size: 13px; color: var(--text-muted); }
    @media (max-width: 900px) { .testimonials-grid { grid-template-columns: 1fr; max-width: 480px; margin: 0 auto; } }

    /* ─── PRICING ─── */
    .pricing { padding: 100px 0; background: var(--bg-white); }
    .pricing-header { text-align: center; margin-bottom: 60px; }
    .pricing-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 24px; align-items: start; }
    .pricing-card { border-radius: var(--radius-lg); padding: 36px; border: 1.5px solid var(--border); background: #fff; transition: all .25s; }
    .pricing-card:hover { box-shadow: var(--shadow-lg); }
    .pricing-card.featured { background: var(--navy); border-color: var(--navy); transform: scale(1.04); }
    .pricing-card.featured:hover { transform: scale(1.04) translateY(-4px); }
    .pricing-badge { display: inline-block; background: var(--teal); color: #fff; font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 999px; letter-spacing: .06em; text-transform: uppercase; margin-bottom: 16px; }
    .pricing-name { font-size: 20px; font-weight: 800; color: var(--navy); margin-bottom: 6px; }
    .pricing-card.featured .pricing-name { color: #fff; }
    .pricing-desc { font-size: 14px; color: var(--text-muted); margin-bottom: 24px; }
    .pricing-card.featured .pricing-desc { color: rgba(255,255,255,.6); }
    .pricing-price { display: flex; align-items: baseline; gap: 4px; margin-bottom: 28px; }
    .price-currency { font-size: 20px; font-weight: 700; color: var(--navy); }
    .price-value { font-size: 44px; font-weight: 900; color: var(--navy); line-height: 1; }
    .price-period { font-size: 14px; color: var(--text-muted); }
    .pricing-card.featured .price-currency,
    .pricing-card.featured .price-value { color: #fff; }
    .pricing-card.featured .price-period { color: rgba(255,255,255,.6); }
    .pricing-features { list-style: none; margin-bottom: 28px; display: flex; flex-direction: column; gap: 10px; }
    .pricing-features li { display: flex; align-items: center; gap: 10px; font-size: 14px; color: var(--text-muted); }
    .pricing-card.featured .pricing-features li { color: rgba(255,255,255,.8); }
    .pricing-features li i { font-size: 16px; color: var(--mint); flex-shrink: 0; }
    .pricing-features li.disabled i { color: var(--border); }
    .pricing-features li.disabled { opacity: .5; }
    .btn-featured { background: var(--teal); color: #fff; border: none; }
    .btn-featured:hover { background: #00a0c2; }
    @media (max-width: 900px) {
        .pricing-grid { grid-template-columns: 1fr; max-width: 400px; margin: 0 auto; }
        .pricing-card.featured { transform: scale(1); }
    }

    /* ─── FAQ ─── */
    .faq { padding: 100px 0; background: var(--bg); }
    .faq-header { text-align: center; margin-bottom: 60px; }
    .faq-list { max-width: 760px; margin: 0 auto; display: flex; flex-direction: column; gap: 12px; }
    .faq-item { background: #fff; border-radius: var(--radius); border: 1px solid var(--border); overflow: hidden; }
    .faq-question {
        display: flex; align-items: center; justify-content: space-between;
        padding: 20px 24px; cursor: pointer; font-weight: 600;
        font-size: 16px; color: var(--navy); gap: 16px; transition: background .18s;
    }
    .faq-question:hover { background: var(--bg); }
    .faq-question i { font-size: 18px; color: var(--text-muted); flex-shrink: 0; }
    .faq-answer { padding: 0 24px; font-size: 15px; color: var(--text-muted); line-height: 1.7; }

    /* ─── CTA FINAL ─── */
    .cta-final {
        padding: 100px 0;
        background: linear-gradient(135deg, var(--navy) 0%, var(--navy-mid) 60%, #0a1f3f 100%);
        position: relative; overflow: hidden; text-align: center;
    }
    .cta-final::before {
        content: '';
        position: absolute; inset: 0;
        background: url("data:image/svg+xml,%3Csvg width='40' height='40' viewBox='0 0 40 40' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='%23ffffff' fill-opacity='0.03'%3E%3Ccircle cx='20' cy='20' r='2'/%3E%3C/g%3E%3C/svg%3E");
    }
    .cta-final > * { position: relative; z-index: 1; }
    .cta-final h2 { font-size: clamp(30px, 4vw, 50px); font-weight: 900; color: #fff; margin-bottom: 16px; }
    .cta-final p  { font-size: 18px; color: rgba(255,255,255,.7); margin-bottom: 36px; }
    .cta-final-btns { display: flex; gap: 14px; justify-content: center; flex-wrap: wrap; }
    .cta-note { font-size: 13px; color: rgba(255,255,255,.45); margin-top: 16px; }
</style>
@endpush

@section('content')

{{-- ═══════════════════ HERO ═══════════════════ --}}
<section class="hero">
    <div class="hero-blob hero-blob-1"></div>
    <div class="hero-blob hero-blob-2"></div>
    <div class="container">
        <div class="hero-inner">
            <div class="hero-text">
                <div class="badge-pill">
                    <i class="bi bi-stars"></i>
                    {{ __('site.hero.badge') }}
                </div>
                <h1 class="hero-title">
                    {{ __('site.hero.title') }}<br>
                    <em>{{ __('site.hero.title_em') }}</em>
                </h1>
                <p class="hero-sub">{{ __('site.hero.subtitle') }}</p>
                <div class="hero-ctas">
                    <a href="{{ route('register') }}" class="btn btn-primary btn-lg">
                        {{ __('site.hero.cta_primary') }} <i class="bi bi-arrow-right"></i>
                    </a>
                    <a href="#como-funciona" class="btn btn-outline-white btn-lg">
                        <i class="bi bi-play-circle"></i> {{ __('site.hero.cta_secondary') }}
                    </a>
                </div>
                <div class="hero-trust">
                    <div class="hero-trust-avatars">
                        <span>JA</span><span>MC</span><span>RS</span><span>PL</span>
                    </div>
                    <span>{!! __('site.hero.trust', ['count' => '500']) !!}</span>
                </div>
            </div>

            <div class="hero-visual">
                <div class="hero-float-card card-top" style="border-left: 3px solid #06d6a0;">
                    <div class="icon" style="background:rgba(6,214,160,.1)">
                        <i class="bi bi-calendar-check" style="color:#06d6a0"></i>
                    </div>
                    <div>
                        <div style="font-size:11px;color:#64748b;font-weight:500;">{{ __('site.hero.card_today') }}</div>
                        <div>{{ __('site.hero.card_appointments', ['count' => 24]) }}</div>
                    </div>
                </div>

                <div class="hero-mockup" style="width:100%;">
                    <div class="mockup-bar">
                        <span class="mockup-dot mockup-dot-r"></span>
                        <span class="mockup-dot mockup-dot-y"></span>
                        <span class="mockup-dot mockup-dot-g"></span>
                        <div class="mockup-url"></div>
                    </div>
                    <div class="mockup-body">
                        <div class="mockup-header-row">
                            <div class="mockup-h-block" style="width:140px;background:rgba(255,255,255,.25);"></div>
                            <div class="mockup-h-block" style="width:80px;background:rgba(0,180,216,.4);border-radius:6px;padding:6px 0;"></div>
                        </div>
                        <div class="mockup-stats">
                            @foreach([['90px'],['75px'],['60px'],['85px']] as $s)
                            <div class="mockup-stat">
                                <div class="mockup-stat-val" style="width:{{ $s[0] }}"></div>
                                <div class="mockup-stat-lbl"></div>
                            </div>
                            @endforeach
                        </div>
                        <div class="mockup-chart">
                            @foreach([40,65,50,80,70,55,90,75,85,60,95,88] as $h)
                            <div class="mockup-bar-item" style="height:{{ $h }}%"></div>
                            @endforeach
                        </div>
                        @foreach(range(1,4) as $i)
                        <div class="mockup-table-row">
                            <div class="mockup-avatar"></div>
                            <div class="mockup-line" style="width:{{ 40+$i*10 }}%"></div>
                            <div class="mockup-line-sm"></div>
                        </div>
                        @endforeach
                    </div>
                </div>

                <div class="hero-float-card card-bottom" style="border-left: 3px solid #f97316;">
                    <div class="icon" style="background:rgba(249,115,22,.1)">
                        <i class="bi bi-shield-check" style="color:#f97316"></i>
                    </div>
                    <div>
                        <div style="font-size:11px;color:#64748b;font-weight:500;">{{ __('site.hero.card_compliance_lbl') }}</div>
                        <div>{{ __('site.hero.card_compliance_val') }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
{{-- ═══════════════════ END HERO ═══════════════════ --}}

{{-- ═══════════════════ METRICS ═══════════════════ --}}
<div class="metrics">
    <div class="container">
        <div class="metrics-grid">
            @foreach(trans('site.metrics') as $metric)
            <div class="metric-item">
                <div class="metric-value">{{ $metric['value'] }}</div>
                <div class="metric-label">{{ $metric['label'] }}</div>
            </div>
            @endforeach
        </div>
    </div>
</div>
{{-- ═══════════════════ END METRICS ═══════════════════ --}}

{{-- ═══════════════════ FEATURES ═══════════════════ --}}
<section class="features" id="funcionalidades">
    <div class="container">
        <div class="features-header">
            <span class="section-label">{{ __('site.features.label') }}</span>
            <h2 class="section-title">{{ __('site.features.title') }}</h2>
            <p class="section-sub text-center">{{ __('site.features.subtitle') }}</p>
        </div>

        <div class="features-grid">
            @foreach(trans('site.features.items') as $feature)
            <div class="feature-card">
                <div class="feature-icon {{ $feature['color'] }}">
                    <i class="bi {{ $feature['icon'] }}"></i>
                </div>
                <h3>{{ $feature['title'] }}</h3>
                <p>{{ $feature['text'] }}</p>
            </div>
            @endforeach
        </div>
    </div>
</section>
{{-- ═══════════════════ END FEATURES ═══════════════════ --}}

{{-- ═══════════════════ HOW IT WORKS ═══════════════════ --}}
<section class="how" id="como-funciona">
    <div class="container">
        <div class="how-inner">
            <div>
                <span class="section-label">{{ __('site.how.label') }}</span>
                <h2 class="section-title">{{ __('site.how.title') }}</h2>
                <p class="section-sub" style="margin-bottom:36px;">{{ __('site.how.subtitle') }}</p>

                <div class="how-steps" x-data="{ active: 0 }">
                    @foreach(trans('site.how.steps') as $i => $step)
                    <div class="how-step" :class="active==={{ $i }}?'active':''" @click="active={{ $i }}">
                        <div class="how-step-num">{{ $i + 1 }}</div>
                        <div class="how-step-content">
                            <h4>{{ $step['title'] }}</h4>
                            <p>{{ $step['text'] }}</p>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>

            <div class="how-visual">
                @if(file_exists(public_path('site/images/how-it-works.png')))
                    <img src="{{ asset('site/images/how-it-works.png') }}" alt="{{ __('site.how.screenshot_alt') }}">
                @else
                    <div class="how-visual-placeholder">
                        <i class="bi bi-display"></i>
                        <p style="font-size:15px;">{{ __('site.how.screenshot_placeholder') }}</p>
                        <p style="font-size:13px;margin-top:4px;"><code>{{ __('site.how.screenshot_hint') }}</code></p>
                    </div>
                @endif
            </div>
        </div>
    </div>
</section>
{{-- ═══════════════════ END HOW IT WORKS ═══════════════════ --}}

{{-- ═══════════════════ COMPLIANCE ═══════════════════ --}}
<section class="compliance">
    <div class="container">
        <div class="compliance-inner">
            <div>
                <span class="section-label">{{ __('site.compliance.label') }}</span>
                <h2 class="section-title">{{ __('site.compliance.title') }}</h2>
                <p class="section-sub">{{ __('site.compliance.subtitle') }}</p>
            </div>
            <div class="compliance-badges">
                @foreach(trans('site.compliance.badges') as $badge)
                <div class="comp-badge">
                    <i class="bi {{ $badge['icon'] }}"></i> {{ $badge['label'] }}
                </div>
                @endforeach
            </div>
        </div>
    </div>
</section>
{{-- ═══════════════════ END COMPLIANCE ═══════════════════ --}}

{{-- ═══════════════════ TESTIMONIALS ═══════════════════ --}}
<section class="testimonials" id="depoimentos">
    <div class="container">
        <div class="testimonials-header">
            <span class="section-label">{{ __('site.testimonials.label') }}</span>
            <h2 class="section-title">{{ __('site.testimonials.title') }}</h2>
        </div>

        <div class="testimonials-grid">
            @foreach(trans('site.testimonials.items') as $t)
            <div class="testimonial-card">
                <div class="testimonial-stars">
                    @for($s = 1; $s <= 5; $s++)
                        <i class="bi {{ $s <= $t['stars'] ? 'bi-star-fill' : ($s - 0.5 <= $t['stars'] ? 'bi-star-half' : 'bi-star') }}"></i>
                    @endfor
                </div>
                <p class="testimonial-text">{{ $t['text'] }}</p>
                <div class="testimonial-author">
                    <div class="testimonial-avatar">{{ $t['initials'] }}</div>
                    <div>
                        <div class="testimonial-name">{{ $t['name'] }}</div>
                        <div class="testimonial-role">{{ $t['role'] }}</div>
                    </div>
                </div>
            </div>
            @endforeach
        </div>
    </div>
</section>
{{-- ═══════════════════ END TESTIMONIALS ═══════════════════ --}}

{{-- ═══════════════════ PRICING ═══════════════════ --}}
<section class="pricing" id="precos">
    <div class="container">
        <div class="pricing-header">
            <span class="section-label">{{ __('site.pricing.label') }}</span>
            <h2 class="section-title">{{ __('site.pricing.title') }}</h2>
            <p class="section-sub text-center">
                {{ __('site.pricing.subtitle') }}
                @php $minTrial = $plans->filter(fn($p) => $p->trial_days)->min('trial_days'); @endphp
                @if($plans->isNotEmpty() && $minTrial)
                    {{ __('site.pricing.trial_suffix', ['days' => $minTrial]) }}
                @endif
            </p>
        </div>

        @if($plans->isEmpty())
            <div style="text-align:center;padding:60px 20px;">
                <i class="bi bi-box-seam" style="font-size:56px;color:var(--border);display:block;margin-bottom:16px;"></i>
                <p style="font-size:18px;font-weight:600;color:var(--navy);margin-bottom:8px;">{{ __('site.pricing.empty_title') }}</p>
                <p style="color:var(--text-muted);margin-bottom:24px;">{{ __('site.pricing.empty_subtitle') }}</p>
                <a href="mailto:contato@easyeye.com.br" class="btn btn-primary">
                    <i class="bi bi-chat-dots"></i> {{ __('site.pricing.contact_cta') }}
                </a>
            </div>
        @else
            <div class="pricing-grid">
                @foreach($plans as $plan)
                    @php
                        $isFeatured = $plan->is_featured;
                        $isFree     = (float) $plan->price === 0.0;
                    @endphp

                    <div class="pricing-card {{ $isFeatured ? 'featured' : '' }}">

                        @if($isFeatured)
                            <div class="pricing-badge">{{ __('site.pricing.featured_badge') }}</div>
                        @endif

                        <div class="pricing-name">{{ $plan->name }}</div>

                        @if($plan->description)
                            <div class="pricing-desc">{{ $plan->description }}</div>
                        @endif

                        <div class="pricing-price">
                            @if($isFree)
                                <span class="price-value"
                                      style="font-size:32px;color:{{ $isFeatured ? '#fff' : 'var(--navy)' }}">
                                    {{ __('site.pricing.on_request') }}
                                </span>
                            @else
                                <span class="price-currency">R$</span>
                                <span class="price-value">
                                    {{ number_format((float) $plan->price, 0, ',', '.') }}
                                </span>
                                <span class="price-period">{{ $plan->pricePeriodLabel() }}</span>
                            @endif
                        </div>

                        @if($plan->trial_days)
                            <div style="font-size:13px;margin-bottom:16px;
                                        color:{{ $isFeatured ? 'rgba(255,255,255,.65)' : 'var(--mint)' }};
                                        font-weight:600;">
                                <i class="bi bi-gift"></i>
                                {{ __('site.pricing.trial_text', ['days' => $plan->trial_days]) }}
                            </div>
                        @endif

                        @if($plan->features->isNotEmpty())
                            <ul class="pricing-features">
                                @foreach($plan->features as $feature)
                                    @php
                                        $enabled = $feature->feature->isBoolean()
                                            ? $feature->boolValue()
                                            : true;
                                    @endphp
                                    <li class="{{ ! $enabled ? 'disabled' : '' }}">
                                        <i class="bi {{ $enabled ? 'bi-check-circle-fill' : 'bi-x-circle' }}"
                                           style="{{ ! $enabled ? '' : ($isFeatured ? 'color:var(--mint)' : '') }}">
                                        </i>
                                        {{ $feature->formatForDisplay() }}
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        @if($isFree)
                            <a href="mailto:contato@easyeye.com.br"
                               class="btn btn-outline{{ $isFeatured ? '-white' : '' }}"
                               style="width:100%;justify-content:center;">
                                {{ __('site.pricing.contact_cta') }}
                            </a>
                        @elseif($isFeatured)
                            <a href="{{ route('register') }}"
                               class="btn btn-featured btn-primary"
                               style="width:100%;justify-content:center;">
                                {{ __('site.pricing.get_started') }} <i class="bi bi-arrow-right"></i>
                            </a>
                        @else
                            <a href="{{ route('register') }}"
                               class="btn btn-outline"
                               style="width:100%;justify-content:center;">
                                {{ __('site.pricing.get_started') }}
                            </a>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</section>
{{-- ═══════════════════ END PRICING ═══════════════════ --}}

{{-- ═══════════════════ FAQ ═══════════════════ --}}
<section class="faq" id="faq">
    <div class="container">
        <div class="faq-header">
            <span class="section-label">{{ __('site.faq.label') }}</span>
            <h2 class="section-title">{{ __('site.faq.title') }}</h2>
        </div>

        <div class="faq-list">
            @foreach(trans('site.faq.items') as $faq)
            <div class="faq-item" x-data="{ open: false }">
                <div class="faq-question" @click="open = !open">
                    {{ $faq['q'] }}
                    <i class="bi" :class="open ? 'bi-dash-lg' : 'bi-plus-lg'"></i>
                </div>
                <div class="faq-answer" x-show="open" x-transition.duration.250ms style="display:none">
                    <div style="padding-bottom:20px;">{{ $faq['a'] }}</div>
                </div>
            </div>
            @endforeach
        </div>
    </div>
</section>
{{-- ═══════════════════ END FAQ ═══════════════════ --}}

{{-- ═══════════════════ CTA FINAL ═══════════════════ --}}
<section class="cta-final">
    <div class="container">
        <h2>{{ __('site.cta.title') }}</h2>
        <p>{{ __('site.cta.subtitle') }}</p>
        <div class="cta-final-btns">
            <a href="{{ route('register') }}" class="btn btn-primary btn-lg">
                {{ __('site.cta.primary') }} <i class="bi bi-arrow-right"></i>
            </a>
            <a href="mailto:contato@easyeye.com.br" class="btn btn-outline-white btn-lg">
                <i class="bi bi-chat-dots"></i> {{ __('site.cta.secondary') }}
            </a>
        </div>
        <p class="cta-note">{{ __('site.cta.note') }}</p>
    </div>
</section>
{{-- ═══════════════════ END CTA FINAL ═══════════════════ --}}

@endsection
