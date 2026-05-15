@extends('layouts.site')

@section('title', __('auth.register.meta_title', ['app' => config('app.name', 'EasyEye')]))
@section('description', __('auth.register.meta_description', ['days' => $trialDays]))

{{-- Vite já fornece o Alpine.js via app.js — suprime o CDN do layout --}}
@section('alpinejs')@endsection

@push('styles')
<style>
    /* ─── HERO DO REGISTRO ─── */
    .reg-hero {
        background: linear-gradient(135deg, #0F2551 0%, #1B3A6B 50%, #0F2551 100%);
        padding: 120px 0 72px;
        position: relative;
        overflow: hidden;
    }

    .reg-hero::before {
        content: '';
        position: absolute;
        inset: 0;
        background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.025'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
    }

    .reg-hero-blob {
        position: absolute;
        border-radius: 50%;
        filter: blur(80px);
        opacity: .2;
    }

    .reg-hero-blob-1 { width: 400px; height: 400px; top: -120px; right: -60px; background: var(--teal); }
    .reg-hero-blob-2 { width: 280px; height: 280px; bottom: -80px; left: 8%; background: #6c63ff; }

    .reg-hero-inner {
        position: relative;
        z-index: 1;
        text-align: center;
        max-width: 680px;
        margin: 0 auto;
    }

    .reg-hero-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: rgba(0,180,216,.15);
        color: var(--teal);
        border: 1px solid rgba(0,180,216,.3);
        padding: 6px 16px;
        border-radius: 999px;
        font-size: 13px;
        font-weight: 600;
        margin-bottom: 20px;
    }

    .reg-hero-title {
        font-size: clamp(28px, 4.5vw, 48px);
        font-weight: 900;
        color: #fff;
        line-height: 1.1;
        letter-spacing: -.02em;
        margin-bottom: 16px;
    }

    .reg-hero-title em {
        font-style: normal;
        color: var(--teal);
    }

    .reg-hero-sub {
        font-size: 17px;
        color: rgba(255,255,255,.7);
        line-height: 1.7;
        margin-bottom: 36px;
    }

    /* Métricas da hero */
    .reg-hero-metrics {
        display: inline-flex;
        align-items: stretch;
        background: rgba(255,255,255,.06);
        border: 1px solid rgba(255,255,255,.12);
        border-radius: 12px;
        overflow: hidden;
    }

    .reg-hero-metric {
        padding: 14px 24px;
        text-align: center;
        border-right: 1px solid rgba(255,255,255,.1);
    }

    .reg-hero-metric:last-child { border-right: none; }

    .reg-hero-metric-val {
        display: block;
        font-size: 22px;
        font-weight: 900;
        color: var(--teal);
        line-height: 1;
        margin-bottom: 4px;
    }

    .reg-hero-metric-label {
        font-size: 11px;
        color: rgba(255,255,255,.45);
        text-transform: uppercase;
        letter-spacing: .05em;
        font-weight: 600;
    }

    /* ─── SEÇÃO DO FORMULÁRIO ─── */
    .reg-section {
        background: var(--bg);
        padding: 64px 0 80px;
    }

    .reg-layout {
        display: grid;
        grid-template-columns: 1fr 420px;
        gap: 48px;
        align-items: start;
    }

    /* ─── COLUNA DE MARKETING ─── */
    .reg-marketing { padding-top: 8px; }

    .reg-marketing-headline {
        font-size: 22px;
        font-weight: 800;
        color: var(--navy);
        margin-bottom: 8px;
        line-height: 1.25;
    }

    .reg-marketing-sub {
        font-size: 15px;
        color: var(--text-muted);
        line-height: 1.65;
        margin-bottom: 32px;
    }

    /* Benefícios */
    .reg-benefits {
        list-style: none;
        display: flex;
        flex-direction: column;
        gap: 16px;
        margin-bottom: 36px;
    }

    .reg-benefit {
        display: flex;
        align-items: flex-start;
        gap: 14px;
    }

    .reg-benefit-icon {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        background: rgba(0,180,216,.1);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        color: var(--teal);
        flex-shrink: 0;
        margin-top: 2px;
    }

    .reg-benefit-text strong {
        display: block;
        font-size: 14px;
        font-weight: 700;
        color: var(--navy);
        margin-bottom: 3px;
    }

    .reg-benefit-text span {
        font-size: 13px;
        color: var(--text-muted);
        line-height: 1.55;
    }

    /* Testemunho */
    .reg-testimonial {
        background: #fff;
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        padding: 22px 24px;
        box-shadow: var(--shadow);
    }

    .reg-testimonial-stars {
        color: #f59e0b;
        font-size: 13px;
        margin-bottom: 10px;
        letter-spacing: 2px;
    }

    .reg-testimonial-text {
        font-size: 14px;
        color: var(--text);
        line-height: 1.65;
        font-style: italic;
        margin-bottom: 14px;
    }

    .reg-testimonial-author {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .reg-testimonial-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--teal), #6c63ff);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 13px;
        font-weight: 700;
        color: #fff;
        flex-shrink: 0;
    }

    .reg-testimonial-name {
        font-size: 14px;
        font-weight: 700;
        color: var(--navy);
    }

    .reg-testimonial-role {
        font-size: 12px;
        color: var(--text-muted);
    }

    /* Trust strip abaixo do depoimento */
    .reg-trust {
        display: flex;
        align-items: center;
        gap: 20px;
        margin-top: 24px;
        flex-wrap: wrap;
    }

    .reg-trust-item {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 12px;
        color: var(--text-muted);
        font-weight: 600;
        letter-spacing: .02em;
    }

    .reg-trust-item i { color: var(--mint); font-size: 14px; }

    /* ─── CARD DO FORMULÁRIO ─── */
    .reg-card {
        background: #fff;
        border-radius: var(--radius-lg);
        border: 1px solid var(--border);
        box-shadow: var(--shadow-lg);
        padding: 36px 32px;
        position: sticky;
        top: 90px;
    }

    .reg-card-header { margin-bottom: 20px; }

    .reg-card-title {
        font-size: 20px;
        font-weight: 800;
        color: var(--navy);
        margin-bottom: 4px;
        line-height: 1.2;
    }

    .reg-card-subtitle {
        font-size: 14px;
        color: var(--text-muted);
    }

    /* ─── STEP INDICATOR ─── */
    .step-indicator {
        display: flex;
        align-items: center;
        margin-bottom: 10px;
    }

    .step-item {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
        font-weight: 600;
        color: var(--text-muted);
    }

    .step-item.active { color: var(--teal); }
    .step-item.done   { color: var(--mint); }

    .step-num {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        border: 2px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        font-weight: 700;
        flex-shrink: 0;
        transition: all .2s;
    }

    .step-item.active .step-num {
        border-color: var(--teal);
        color: var(--teal);
        background: rgba(0,180,216,.08);
    }

    .step-item.done .step-num {
        border-color: var(--mint);
        background: var(--mint);
        color: #fff;
    }

    .step-sep {
        flex: 1;
        height: 2px;
        background: var(--border);
        margin: 0 8px;
    }

    /* ─── BARRA DE PROGRESSO ─── */
    .reg-progress {
        height: 3px;
        border-radius: 4px;
        background: var(--border);
        margin-bottom: 22px;
        overflow: hidden;
    }

    .reg-progress-fill {
        height: 100%;
        background: linear-gradient(90deg, var(--teal), var(--mint));
        border-radius: 4px;
        transition: width .4s cubic-bezier(.4,0,.2,1);
    }

    /* ─── CAMPOS ─── */
    .reg-field { margin-bottom: 16px; }

    .reg-label {
        display: block;
        font-size: 13px;
        font-weight: 600;
        color: var(--navy);
        margin-bottom: 6px;
    }

    .reg-label .req { color: #ef4444; margin-left: 2px; }
    .reg-label .opt { font-weight: 400; color: var(--text-muted); font-size: 12px; margin-left: 4px; }

    .reg-input {
        width: 100%;
        border: 1.5px solid var(--border);
        border-radius: 10px;
        padding: 10px 14px;
        font-size: 15px;
        color: var(--text);
        font-family: 'Inter', system-ui, sans-serif;
        background: #fff;
        outline: none;
        transition: border-color .18s, box-shadow .18s;
        -webkit-appearance: none;
    }

    .reg-input:focus {
        border-color: var(--teal);
        box-shadow: 0 0 0 3px rgba(0,180,216,.12);
    }

    .reg-input.is-error   { border-color: #ef4444; }
    .reg-input.is-success { border-color: var(--mint); }

    .reg-error {
        display: block;
        font-size: 12px;
        color: #ef4444;
        margin-top: 5px;
    }

    .reg-input-group {
        display: flex;
        align-items: stretch;
    }

    .reg-input-group .reg-input {
        border-radius: 10px 0 0 10px;
        border-right: none;
        flex: 1;
    }

    .reg-input-group .reg-input:focus {
        position: relative;
        z-index: 1;
        border-right: 1.5px solid var(--teal);
    }

    .reg-input-addon {
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0 14px;
        border: 1.5px solid var(--border);
        border-left: none;
        border-radius: 0 10px 10px 0;
        background: var(--bg);
        color: var(--text-muted);
        font-size: 16px;
        min-width: 44px;
        transition: border-color .18s;
    }

    .reg-input-group:focus-within .reg-input-addon { border-color: var(--teal); }

    .reg-toggle-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0 14px;
        border: 1.5px solid var(--border);
        border-left: none;
        border-radius: 0 10px 10px 0;
        background: var(--bg);
        color: var(--text-muted);
        font-size: 16px;
        cursor: pointer;
        transition: all .18s;
        min-width: 44px;
    }

    .reg-toggle-btn:hover {
        background: var(--navy);
        color: #fff;
        border-color: var(--navy);
    }

    .reg-input-group:focus-within .reg-toggle-btn { border-color: var(--teal); }

    /* ─── FORÇA DA SENHA ─── */
    .pwd-strength {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-top: 8px;
    }

    .pwd-bars {
        display: flex;
        gap: 4px;
        flex: 1;
    }

    .pwd-bar {
        flex: 1;
        height: 4px;
        border-radius: 4px;
        background: var(--border);
        transition: background .25s;
    }

    .pwd-strength-label {
        font-size: 12px;
        font-weight: 600;
        white-space: nowrap;
        min-width: 72px;
        text-align: right;
        transition: color .25s;
    }

    /* ─── BOTÕES ─── */
    .reg-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        width: 100%;
        padding: 12px 24px;
        border-radius: 10px;
        font-size: 15px;
        font-weight: 700;
        cursor: pointer;
        border: none;
        font-family: 'Inter', system-ui, sans-serif;
        transition: all .2s;
    }

    .reg-btn-primary { background: var(--teal); color: #fff; }

    .reg-btn-primary:hover:not(:disabled) {
        background: #0096b5;
        transform: translateY(-1px);
        box-shadow: 0 6px 20px rgba(0,180,216,.35);
    }

    .reg-btn-primary:disabled { opacity: .65; cursor: not-allowed; }

    .reg-btn-secondary {
        background: var(--bg);
        color: var(--navy);
        border: 1.5px solid var(--border);
        flex-shrink: 0;
        width: auto;
        padding: 12px 20px;
    }

    .reg-btn-secondary:hover {
        background: var(--navy);
        color: #fff;
        border-color: var(--navy);
    }

    .reg-btn-row {
        display: flex;
        gap: 10px;
        margin-top: 8px;
    }

    .reg-btn-row .reg-btn-primary { flex: 1; }

    /* ─── DIVISOR ─── */
    .reg-divider {
        position: relative;
        text-align: center;
        margin: 12px 0 8px;
    }

    .reg-divider::before {
        content: '';
        position: absolute;
        top: 50%;
        left: 0; right: 0;
        height: 1px;
        background: var(--border);
    }

    .reg-divider span {
        position: relative;
        background: #fff;
        padding: 0 10px;
        font-size: 12px;
        color: var(--text-muted);
    }

    /* ─── FOOTER DO CARD ─── */
    .reg-card-footer {
        text-align: center;
        margin-top: 18px;
        font-size: 14px;
        color: var(--text-muted);
    }

    .reg-card-footer a {
        color: var(--teal);
        font-weight: 600;
        text-decoration: none;
    }

    .reg-card-footer a:hover { text-decoration: underline; }

    /* Trust strip no card */
    .reg-card-trust {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 16px;
        margin-top: 18px;
        padding-top: 14px;
        border-top: 1px solid var(--border);
        flex-wrap: wrap;
    }

    .reg-card-trust-item {
        display: flex;
        align-items: center;
        gap: 5px;
        font-size: 11px;
        color: var(--text-muted);
        font-weight: 600;
    }

    /* ─── PLANOS ─── */
    .plan-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
        gap: 8px;
        margin-bottom: 10px;
    }

    .plan-grid-card {
        border: 2px solid var(--border);
        border-radius: 10px;
        padding: 12px 10px;
        text-align: center;
        cursor: pointer;
        transition: all .2s;
        background: #fff;
        user-select: none;
    }

    .plan-grid-card:hover {
        border-color: var(--teal);
        box-shadow: 0 0 0 3px rgba(0,180,216,.08);
    }

    .plan-grid-card.selected {
        border-color: var(--teal);
        background: rgba(0,180,216,.03);
        box-shadow: 0 0 0 3px rgba(0,180,216,.12);
    }

    .plan-grid-badge {
        display: inline-block;
        background: rgba(0,180,216,.1);
        color: var(--teal);
        font-size: 10px;
        font-weight: 700;
        padding: 2px 7px;
        border-radius: 20px;
        margin-bottom: 5px;
        letter-spacing: .02em;
    }

    .plan-grid-name {
        font-size: 12px;
        font-weight: 800;
        color: var(--navy);
        margin-bottom: 4px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .plan-grid-price {
        font-size: 15px;
        font-weight: 900;
        color: var(--teal);
        line-height: 1;
    }

    .plan-grid-cycle {
        font-size: 10px;
        color: var(--text-muted);
        margin-top: 2px;
    }

    .plan-detail {
        border: 1.5px solid rgba(0,180,216,.3);
        border-radius: 10px;
        padding: 12px 14px;
        background: rgba(0,180,216,.02);
    }

    .plan-features {
        list-style: none;
        padding: 0;
        margin: 0;
        font-size: 13px;
        display: flex;
        flex-direction: column;
        gap: 5px;
    }

    .plan-features li { color: var(--text-muted); }
    .plan-features .feat-val { font-weight: 700; color: var(--navy); }

    /* ─── QUICK START ─── */
    .reg-quick-start {
        background: none;
        border: none;
        cursor: pointer;
        font-size: 13px;
        color: var(--text-muted);
        font-family: 'Inter', system-ui, sans-serif;
        padding: 0;
        transition: color .18s;
        display: block;
        text-align: center;
        width: 100%;
        margin-top: 8px;
    }

    .reg-quick-start:hover { color: var(--teal); }

    /* ─── SPINNER ─── */
    .ee-spin {
        display: inline-block;
        animation: eeSpin 1s linear infinite;
    }

    @keyframes eeSpin {
        from { transform: rotate(0deg); }
        to   { transform: rotate(360deg); }
    }

    /* ─── RESPONSIVO ─── */
    @media (max-width: 1023px) {
        .reg-layout {
            grid-template-columns: 1fr;
        }

        .reg-marketing {
            order: 2;
        }

        .reg-card {
            position: static;
            order: 1;
        }
    }

    @media (max-width: 640px) {
        .reg-hero { padding: 100px 0 52px; }
        .reg-hero-metrics { flex-direction: column; width: 100%; }
        .reg-hero-metric { border-right: none; border-bottom: 1px solid rgba(255,255,255,.1); }
        .reg-hero-metric:last-child { border-bottom: none; }
        .reg-section { padding: 36px 0 56px; }
        .reg-card { padding: 24px 18px; }
        .plan-grid { grid-template-columns: 1fr 1fr; }
    }
</style>
@endpush

@section('content')

{{-- ═══════ HERO ═══════ --}}
<section class="reg-hero hero">
    <div class="reg-hero-blob reg-hero-blob-1"></div>
    <div class="reg-hero-blob reg-hero-blob-2"></div>

    <div class="container">
        <div class="reg-hero-inner">
            <div class="reg-hero-badge">
                <i class="bi bi-stars"></i>
                {{ $trialDays }} {{ __('auth.register.days_free') }}
                &bull; {{ __('auth.register.no_card') }}
                &bull; {{ __('auth.register.setup_fast') }}
            </div>

            <h1 class="reg-hero-title">
                {{ __('auth.register.left_headline') }}<br>
                <em>{{ __('auth.register.left_headline_em') }}</em>
            </h1>

            <p class="reg-hero-sub">{{ __('auth.register.left_sub') }}</p>

            <div class="reg-hero-metrics">
                <div class="reg-hero-metric">
                    <span class="reg-hero-metric-val">500+</span>
                    <span class="reg-hero-metric-label">{{ __('auth.register.metric_clinics') }}</span>
                </div>
                <div class="reg-hero-metric">
                    <span class="reg-hero-metric-val">{{ $trialDays }}</span>
                    <span class="reg-hero-metric-label">{{ __('auth.register.days_free') }}</span>
                </div>
                <div class="reg-hero-metric">
                    <span class="reg-hero-metric-val">97%</span>
                    <span class="reg-hero-metric-label">NPS</span>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ═══════ SEÇÃO DO FORMULÁRIO ═══════ --}}
<section class="reg-section">
    <div class="container">
        <div class="reg-layout">

            {{-- ── COLUNA DE MARKETING ── --}}
            <div class="reg-marketing">

                <h2 class="reg-marketing-headline">
                    {{ __('auth.register.step1_title') }}
                </h2>
                <p class="reg-marketing-sub">
                    {{ __('auth.register.left_sub') }}
                </p>

                <ul class="reg-benefits">
                    <li class="reg-benefit">
                        <div class="reg-benefit-icon"><i class="bi bi-gift"></i></div>
                        <div class="reg-benefit-text">
                            <strong>{{ __('auth.register.benefit_trial_title', ['days' => $trialDays]) }}</strong>
                            <span>{{ __('auth.register.benefit_trial_text') }}</span>
                        </div>
                    </li>
                    <li class="reg-benefit">
                        <div class="reg-benefit-icon"><i class="bi bi-lightning-charge"></i></div>
                        <div class="reg-benefit-text">
                            <strong>{{ __('auth.register.benefit_setup_title') }}</strong>
                            <span>{{ __('auth.register.benefit_setup_text') }}</span>
                        </div>
                    </li>
                    <li class="reg-benefit">
                        <div class="reg-benefit-icon"><i class="bi bi-headset"></i></div>
                        <div class="reg-benefit-text">
                            <strong>{{ __('auth.register.benefit_support_title') }}</strong>
                            <span>{{ __('auth.register.benefit_support_text') }}</span>
                        </div>
                    </li>
                    <li class="reg-benefit">
                        <div class="reg-benefit-icon"><i class="bi bi-shield-check"></i></div>
                        <div class="reg-benefit-text">
                            <strong>{{ __('auth.register.benefit_lgpd_title') }}</strong>
                            <span>{{ __('auth.register.benefit_lgpd_text') }}</span>
                        </div>
                    </li>
                </ul>

                <div class="reg-testimonial">
                    <div class="reg-testimonial-stars">★★★★★</div>
                    <p class="reg-testimonial-text">"{{ __('auth.register.testimonial_text') }}"</p>
                    <div class="reg-testimonial-author">
                        <div class="reg-testimonial-avatar">DR</div>
                        <div>
                            <div class="reg-testimonial-name">{{ __('auth.register.testimonial_name') }}</div>
                            <div class="reg-testimonial-role">{{ __('auth.register.testimonial_role') }}</div>
                        </div>
                    </div>
                </div>

                <div class="reg-trust">
                    <div class="reg-trust-item">
                        <i class="bi bi-lock-fill"></i> <span>SSL 256-bit</span>
                    </div>
                    <div class="reg-trust-item">
                        <i class="bi bi-shield-check"></i> <span>LGPD</span>
                    </div>
                    <div class="reg-trust-item">
                        <i class="bi bi-award"></i> <span>CFM</span>
                    </div>
                    <div class="reg-trust-item">
                        <i class="bi bi-emoji-smile"></i> <span>97% NPS</span>
                    </div>
                </div>
            </div>

            {{-- ── CARD DO FORMULÁRIO ── --}}
            <div class="reg-card">

                @php
                    $plansData = $plans->map(function ($plan) {
                        return [
                            'id'            => $plan->id,
                            'name'          => $plan->name,
                            'price'         => number_format($plan->price, 2, ',', '.'),
                            'billing_cycle' => __('subscriptions.billing_cycle.' . $plan->billing_cycle->value),
                            'description'   => $plan->description,
                            'features'      => $plan->features->map(fn($f) => [
                                'key'           => $f->feature->value,
                                'label'         => $f->feature->label(),
                                'bool'          => $f->feature->isBoolean(),
                                'value'         => $f->value,
                                'display_value' => $f->feature->isBoolean()
                                    ? null
                                    : ($f->value == '0' ? __('subscriptions.expired_page.unlimited') : $f->value),
                            ])->values(),
                        ];
                    })->toJson(JSON_HEX_QUOT | JSON_HEX_APOS);
                @endphp

                <div x-data="registerWizard({{ $plansData }}, {{ $trialDays }})">

                    {{-- Cabeçalho --}}
                    <div class="reg-card-header">
                        <div class="reg-card-title">
                            <span x-show="step === 1">{{ __('auth.register.step1_title') }}</span>
                            <span x-show="step === 2" x-cloak>{{ __('auth.register.step2_title') }}</span>
                        </div>
                        <div class="reg-card-subtitle">
                            <span x-show="step === 1">{{ __('auth.register.step1_subtitle', ['days' => $trialDays]) }}</span>
                            <span x-show="step === 2" x-cloak>{{ __('auth.register.step2_subtitle') }}</span>
                        </div>
                    </div>

                    {{-- Indicador de etapas --}}
                    <div class="step-indicator">
                        <div class="step-item" :class="{ active: step === 1, done: step > 1 }">
                            <span class="step-num">
                                <span x-show="step <= 1">1</span>
                                <span x-show="step > 1" x-cloak><i class="bi bi-check2" style="font-size:13px;"></i></span>
                            </span>
                            <span>{{ __('auth.register.step_personal') }}</span>
                        </div>
                        <div class="step-sep"></div>
                        <div class="step-item" :class="{ active: step === 2 }">
                            <span class="step-num">2</span>
                            <span>{{ __('auth.register.step_company') }}</span>
                        </div>
                    </div>

                    {{-- Barra de progresso --}}
                    <div class="reg-progress">
                        <div class="reg-progress-fill"
                             :style="'width:' + (step === 1 ? '50' : '100') + '%'"></div>
                    </div>

                    {{-- ══════════ ETAPA 1 ══════════ --}}
                    <div x-show="step === 1"
                         x-transition:enter="transition ease-out duration-200"
                         x-transition:enter-start="opacity-0 translate-y-1"
                         x-transition:enter-end="opacity-100 translate-y-0">

                        <form @submit.prevent="nextStep" novalidate>

                            <div class="reg-field">
                                <label class="reg-label">
                                    {{ __('auth.register.name') }} <span class="req">*</span>
                                </label>
                                <input type="text"
                                       class="reg-input"
                                       :class="{ 'is-error': errors.name }"
                                       x-model="form.name"
                                       autocomplete="name"
                                       autofocus>
                                <span class="reg-error" x-show="errors.name" x-text="firstError('name')"></span>
                            </div>

                            <div class="reg-field">
                                <label class="reg-label">
                                    {{ __('auth.register.email') }} <span class="req">*</span>
                                </label>
                                <div class="reg-input-group">
                                    <input type="email"
                                           class="reg-input"
                                           :class="{
                                               'is-error':   errors.email,
                                               'is-success': emailAvailable === true
                                           }"
                                           x-model="form.email"
                                           @blur="checkEmailAvailability"
                                           autocomplete="username">
                                    <div class="reg-input-addon">
                                        <span x-show="emailChecking" class="ee-spin" style="color:var(--text-muted);display:flex;">
                                            <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
                                                <path fill-rule="evenodd" d="M8 3a5 5 0 1 0 4.546 2.914.5.5 0 0 1 .908-.417A6 6 0 1 1 8 2z"/>
                                            </svg>
                                        </span>
                                        <i class="bi bi-check-circle-fill" style="color:var(--mint)"
                                           x-show="emailAvailable === true && !emailChecking"></i>
                                        <i class="bi bi-x-circle-fill" style="color:#ef4444"
                                           x-show="emailAvailable === false && !emailChecking"></i>
                                        <i class="bi bi-envelope"
                                           x-show="emailAvailable === null && !emailChecking"></i>
                                    </div>
                                </div>
                                <span class="reg-error" x-show="errors.email" x-text="firstError('email')"></span>
                            </div>

                            <div class="reg-field" x-data="{ showPass: false }">
                                <label class="reg-label">
                                    {{ __('auth.register.password') }} <span class="req">*</span>
                                </label>
                                <div class="reg-input-group">
                                    <input :type="showPass ? 'text' : 'password'"
                                           class="reg-input"
                                           :class="{ 'is-error': errors.password }"
                                           x-model="form.password"
                                           autocomplete="new-password">
                                    <button type="button" class="reg-toggle-btn" @click="showPass = !showPass" tabindex="-1">
                                        <i class="bi" :class="showPass ? 'bi-eye-slash' : 'bi-eye'"></i>
                                    </button>
                                </div>
                                <span class="reg-error" x-show="errors.password" x-text="firstError('password')"></span>
                                <div class="pwd-strength" x-show="form.password && form.password.length > 0">
                                    <div class="pwd-bars">
                                        <template x-for="i in [1,2,3,4,5]">
                                            <div class="pwd-bar"
                                                 :style="i <= passwordStrength ? 'background:' + passwordStrengthColor : ''"></div>
                                        </template>
                                    </div>
                                    <span class="pwd-strength-label"
                                          :style="'color:' + passwordStrengthColor"
                                          x-text="passwordStrengthLabel"></span>
                                </div>
                            </div>

                            <div class="reg-field" x-data="{ showConfirm: false }">
                                <label class="reg-label">
                                    {{ __('auth.register.confirm_password') }} <span class="req">*</span>
                                </label>
                                <div class="reg-input-group">
                                    <input :type="showConfirm ? 'text' : 'password'"
                                           class="reg-input"
                                           :class="{ 'is-error': errors.password_confirmation }"
                                           x-model="form.password_confirmation"
                                           autocomplete="new-password">
                                    <button type="button" class="reg-toggle-btn" @click="showConfirm = !showConfirm" tabindex="-1">
                                        <i class="bi" :class="showConfirm ? 'bi-eye-slash' : 'bi-eye'"></i>
                                    </button>
                                </div>
                                <span class="reg-error" x-show="errors.password_confirmation" x-text="firstError('password_confirmation')"></span>
                            </div>

                            <button type="submit" class="reg-btn reg-btn-primary" style="margin-top:8px;">
                                {{ __('auth.register.next') }}
                                <i class="bi bi-arrow-right"></i>
                            </button>
                        </form>

                        <div class="reg-card-footer">
                            {{ __('auth.register.already_registered') }}
                            <a href="{{ route('go') }}">{{ __('auth.register.log_in') }}</a>
                        </div>

                    </div>
                    {{-- FIM ETAPA 1 --}}

                    {{-- ══════════ ETAPA 2 ══════════ --}}
                    <div x-show="step === 2"
                         x-cloak
                         x-transition:enter="transition ease-out duration-200"
                         x-transition:enter-start="opacity-0 translate-y-1"
                         x-transition:enter-end="opacity-100 translate-y-0">

                        <form @submit.prevent="submit" novalidate>

                            <div class="reg-field">
                                <label class="reg-label">
                                    {{ __('auth.register.company_name') }} <span class="req">*</span>
                                </label>
                                <input id="reg-company-name"
                                       type="text"
                                       class="reg-input"
                                       :class="{ 'is-error': errors.company_name }"
                                       x-model="form.company_name"
                                       autocomplete="organization">
                                <span class="reg-error" x-show="errors.company_name" x-text="firstError('company_name')"></span>
                            </div>

                            <div class="reg-field">
                                <label class="reg-label">
                                    {{ __('auth.register.phone') }}
                                    <span class="opt">({{ __('auth.register.optional') }})</span>
                                </label>
                                <input type="tel"
                                       class="reg-input"
                                       x-model="form.company_phone"
                                       autocomplete="tel"
                                       placeholder="(00) 00000-0000">
                            </div>

                            <div class="reg-field">
                                <label class="reg-label">
                                    {{ __('auth.register.cnpj') }}
                                    <span class="opt">({{ __('auth.register.optional') }})</span>
                                </label>
                                <input type="text"
                                       class="reg-input"
                                       :class="{ 'is-error': errors.company_cnpj }"
                                       x-model="form.company_cnpj"
                                       x-mask-br="'cnpj'"
                                       maxlength="18"
                                       placeholder="00.000.000/0000-00">
                                <span class="reg-error" x-show="errors.company_cnpj" x-text="firstError('company_cnpj')"></span>
                            </div>

                            @if($plans->isNotEmpty())
                                <div class="reg-field">
                                    <label class="reg-label">{{ __('auth.register.choose_plan') }}</label>

                                    <div class="plan-grid">
                                        <template x-for="plan in plans()" :key="plan.id">
                                            <div class="plan-grid-card"
                                                 :class="{ selected: selectedPlan === plan.id }"
                                                 @click="selectPlan(plan.id)">
                                                <div class="plan-grid-badge">
                                                    <span x-text="trialDays"></span> {{ __('auth.register.days_free') }}
                                                </div>
                                                <div class="plan-grid-name" x-text="plan.name"></div>
                                                <div class="plan-grid-price">R$ <span x-text="plan.price"></span></div>
                                                <div class="plan-grid-cycle">/ <span x-text="plan.billing_cycle"></span></div>
                                            </div>
                                        </template>
                                    </div>

                                    <template x-if="currentPlan && currentPlan.features.length > 0">
                                        <div class="plan-detail">
                                            <ul class="plan-features">
                                                <template x-for="feat in currentPlan.features" :key="feat.key">
                                                    <li>
                                                        <template x-if="feat.bool">
                                                            <span :style="feat.value === '1' ? 'color:var(--mint)' : 'color:#ef4444'"
                                                                  x-text="feat.value === '1' ? '✓' : '✗'"></span>
                                                        </template>
                                                        <template x-if="!feat.bool">
                                                            <span class="feat-val" x-text="feat.display_value"></span>
                                                        </template>
                                                        <span x-text="' ' + feat.label"></span>
                                                    </li>
                                                </template>
                                            </ul>
                                        </div>
                                    </template>
                                </div>
                            @endif

                            <div class="reg-btn-row">
                                <button type="button" class="reg-btn reg-btn-secondary" @click="prevStep">
                                    <i class="bi bi-arrow-left"></i> {{ __('auth.register.back') }}
                                </button>
                                <button type="submit" class="reg-btn reg-btn-primary" :disabled="loading">
                                    <span x-show="!loading">
                                        {{ __('auth.register.start_trial') }}
                                        <i class="bi bi-rocket-takeoff"></i>
                                    </span>
                                    <span x-show="loading" x-cloak>
                                        <i class="bi bi-arrow-repeat ee-spin"></i>
                                        {{ __('auth.register.processing') }}
                                    </span>
                                </button>
                            </div>

                            @if($plans->count() > 1)
                                <div class="reg-divider"><span>{{ __('auth.register.or') }}</span></div>
                                <button type="button"
                                        class="reg-quick-start"
                                        @click="quickStart"
                                        :disabled="loading">
                                    {{ __('auth.register.quick_start') }}
                                    <span x-text="plans()[0]?.name ?? ''"></span> &rarr;
                                </button>
                            @endif

                        </form>
                    </div>
                    {{-- FIM ETAPA 2 --}}

                    <div class="reg-card-trust">
                        <div class="reg-card-trust-item">
                            <i class="bi bi-lock-fill"></i> <span>SSL</span>
                        </div>
                        <div class="reg-card-trust-item">
                            <i class="bi bi-shield-check"></i> <span>LGPD</span>
                        </div>
                        <div class="reg-card-trust-item">
                            <i class="bi bi-award"></i> <span>CFM</span>
                        </div>
                        <div class="reg-card-trust-item">
                            <i class="bi bi-emoji-smile"></i> <span>97% NPS</span>
                        </div>
                    </div>

                </div>
            </div>
            {{-- FIM CARD --}}

        </div>
    </div>
</section>

@endsection

@push('scripts')
    @php
        $strengthLabels = [
            '',
            __('auth.register.strength_very_weak'),
            __('auth.register.strength_weak'),
            __('auth.register.strength_fair'),
            __('auth.register.strength_strong'),
            __('auth.register.strength_very_strong'),
        ];
    @endphp
    <script>
        window._trans = {
            email_taken:        '{{ __('auth.register.email_taken') }}',
            field_required:     '{{ __('auth.register.field_required') }}',
            passwords_mismatch: '{{ __('auth.register.passwords_mismatch') }}',
            strength_labels:    {!! json_encode($strengthLabels, JSON_UNESCAPED_UNICODE) !!},
        };
    </script>
    @vite(['resources/js/app.js'])
@endpush
