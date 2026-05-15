@extends('layouts.site')

@section('title', __('auth.register.meta_title', ['app' => config('app.name', 'EasyEye')]))
@section('description', __('auth.register.meta_description'))

{{-- Vite já fornece o Alpine.js via app.js — suprime o CDN do layout --}}
@section('alpinejs')@endsection

@push('styles')
<style>
    /* ─── ESTRUTURA GERAL ─── */
    .reg-page {
        padding-top: 70px; /* clearance do navbar fixo */
        min-height: 100vh;
        display: flex;
        flex-direction: column;
    }

    .reg-body {
        flex: 1;
        display: grid;
        grid-template-columns: 400px 1fr;
    }

    /* ─── PAINEL ESQUERDO — MARKETING ─── */
    .reg-left {
        background: linear-gradient(160deg, var(--navy) 0%, #0d1f47 100%);
        padding: 52px 44px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        position: sticky;
        top: 70px;
        height: calc(100vh - 70px);
        overflow-y: auto;
    }

    .reg-left-headline {
        font-size: clamp(24px, 2.8vw, 32px);
        font-weight: 800;
        color: #fff;
        line-height: 1.2;
        margin-bottom: 8px;
        letter-spacing: -.02em;
    }

    .reg-left-headline em {
        font-style: normal;
        color: var(--teal);
    }

    .reg-left-sub {
        font-size: 15px;
        color: rgba(255,255,255,.6);
        line-height: 1.6;
        margin-bottom: 36px;
    }

    .reg-benefits {
        list-style: none;
        display: flex;
        flex-direction: column;
        gap: 16px;
        margin-bottom: 40px;
    }

    .reg-benefit {
        display: flex;
        align-items: flex-start;
        gap: 12px;
    }

    .reg-benefit-icon {
        width: 32px;
        height: 32px;
        border-radius: 8px;
        background: rgba(0,180,216,.15);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 15px;
        color: var(--teal);
        flex-shrink: 0;
        margin-top: 1px;
    }

    .reg-benefit-text strong {
        display: block;
        font-size: 14px;
        font-weight: 700;
        color: #fff;
        margin-bottom: 2px;
    }

    .reg-benefit-text span {
        font-size: 13px;
        color: rgba(255,255,255,.5);
        line-height: 1.5;
    }

    .reg-left-divider {
        height: 1px;
        background: rgba(255,255,255,.1);
        margin-bottom: 28px;
    }

    .reg-testimonial {
        background: rgba(255,255,255,.05);
        border: 1px solid rgba(255,255,255,.1);
        border-radius: 12px;
        padding: 20px;
    }

    .reg-testimonial-stars {
        color: #f59e0b;
        font-size: 12px;
        margin-bottom: 10px;
        letter-spacing: 2px;
    }

    .reg-testimonial-text {
        font-size: 13px;
        color: rgba(255,255,255,.7);
        line-height: 1.6;
        font-style: italic;
        margin-bottom: 14px;
    }

    .reg-testimonial-author {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .reg-testimonial-avatar {
        width: 34px;
        height: 34px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--teal), #6c63ff);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        font-weight: 700;
        color: #fff;
        flex-shrink: 0;
    }

    .reg-testimonial-name {
        font-size: 13px;
        font-weight: 700;
        color: #fff;
    }

    .reg-testimonial-role {
        font-size: 12px;
        color: rgba(255,255,255,.45);
    }

    /* ─── PAINEL DIREITO — FORMULÁRIO ─── */
    .reg-right {
        background: var(--bg);
        display: flex;
        align-items: flex-start;
        justify-content: center;
        padding: 48px 24px 64px;
    }

    .reg-card {
        background: #fff;
        border-radius: var(--radius-lg);
        border: 1px solid var(--border);
        box-shadow: var(--shadow);
        padding: 40px 36px;
        width: 100%;
        max-width: 460px;
    }

    .reg-card-header {
        margin-bottom: 28px;
    }

    .reg-card-title {
        font-size: 22px;
        font-weight: 800;
        color: var(--navy);
        margin-bottom: 4px;
    }

    .reg-card-subtitle {
        font-size: 14px;
        color: var(--text-muted);
    }

    /* Faixa mobile — aparece só em telas pequenas */
    .reg-mobile-banner {
        display: none;
        background: var(--navy);
        padding: 14px 20px;
        text-align: center;
    }

    .reg-mobile-banner span {
        font-size: 13px;
        color: rgba(255,255,255,.8);
    }

    .reg-mobile-banner strong { color: var(--teal); }

    /* ─── STEP INDICATOR ─── */
    .step-indicator {
        display: flex;
        align-items: center;
        gap: 0;
        margin-bottom: 28px;
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

    /* ─── CAMPOS DO FORMULÁRIO ─── */
    .reg-field { margin-bottom: 18px; }

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

    /* Input com ícone/botão à direita */
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

    .reg-input-group:focus-within .reg-input-addon {
        border-color: var(--teal);
    }

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

    .reg-input-group:focus-within .reg-toggle-btn {
        border-color: var(--teal);
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

    .reg-btn-primary {
        background: var(--teal);
        color: #fff;
    }

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
        left: 0;
        right: 0;
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
        margin-top: 20px;
        font-size: 14px;
        color: var(--text-muted);
    }

    .reg-card-footer a {
        color: var(--teal);
        font-weight: 600;
        text-decoration: none;
    }

    .reg-card-footer a:hover { text-decoration: underline; }

    /* ─── PLANOS ─── */
    .plan-card {
        border: 2px solid var(--teal);
        border-radius: 12px;
        padding: 20px 16px;
        text-align: center;
        box-shadow: 0 0 0 3px rgba(0,180,216,.1);
        background: #fff;
    }

    .plan-trial-badge {
        display: inline-block;
        background: var(--mint);
        color: #fff;
        font-size: 11px;
        font-weight: 700;
        padding: 3px 10px;
        border-radius: 20px;
        margin-bottom: 8px;
        letter-spacing: .02em;
    }

    .plan-name {
        font-size: 17px;
        font-weight: 800;
        color: var(--navy);
        margin-bottom: 2px;
    }

    .plan-price {
        font-size: 26px;
        font-weight: 900;
        color: var(--teal);
        margin-bottom: 2px;
    }

    .plan-price small {
        font-size: 13px;
        font-weight: 400;
        color: var(--text-muted);
    }

    .plan-description {
        font-size: 13px;
        color: var(--text-muted);
        margin: 4px 0 0;
        line-height: 1.4;
    }

    .plan-features {
        list-style: none;
        padding: 0;
        margin: 12px 0 0;
        text-align: left;
        font-size: 13px;
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .plan-features li { color: var(--text-muted); }
    .plan-features .feat-val { font-weight: 700; color: var(--navy); }

    .plan-carousel-wrap {
        position: relative;
        padding: 0 36px;
    }

    .carousel-btn {
        position: absolute;
        top: 50%;
        transform: translateY(-50%);
        background: #fff;
        border: 1.5px solid var(--border);
        border-radius: 50%;
        width: 30px;
        height: 30px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        color: var(--text-muted);
        font-size: 18px;
        line-height: 1;
        transition: all .15s;
        z-index: 1;
        padding: 0;
    }

    .carousel-btn:hover:not(:disabled) {
        background: var(--navy);
        border-color: var(--navy);
        color: #fff;
    }

    .carousel-btn:disabled { opacity: .25; cursor: default; pointer-events: none; }
    .carousel-btn-prev { left: 0; }
    .carousel-btn-next { right: 0; }

    .carousel-dots {
        display: flex;
        justify-content: center;
        gap: 6px;
        margin-top: 10px;
    }

    .carousel-dots .dot {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: var(--border);
        cursor: pointer;
        transition: background .2s;
    }

    .carousel-dots .dot.active { background: var(--teal); }

    .carousel-counter {
        font-size: 12px;
        color: var(--text-muted);
        text-align: center;
        margin-top: 4px;
    }

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
        .reg-body { grid-template-columns: 1fr; }
        .reg-left { display: none; }
        .reg-mobile-banner { display: block; }
        .reg-right { padding: 24px 16px 48px; }
        .reg-card { padding: 28px 20px; }
    }
</style>
@endpush

@section('content')

<div class="reg-page">

    {{-- Faixa mobile (substitui painel esquerdo) --}}
    <div class="reg-mobile-banner">
        <span>
            <strong>14 {{ __('auth.register.days_free') }}</strong>
            &bull; {{ __('auth.register.no_card') }}
            &bull; {{ __('auth.register.setup_fast') }}
        </span>
    </div>

    <div class="reg-body">

        {{-- ═══════ PAINEL ESQUERDO — MARKETING ═══════ --}}
        <div class="reg-left">
            <p class="reg-left-headline">
                {{ __('auth.register.left_headline') }}<br>
                <em>{{ __('auth.register.left_headline_em') }}</em>
            </p>
            <p class="reg-left-sub">{{ __('auth.register.left_sub') }}</p>

            <ul class="reg-benefits">
                <li class="reg-benefit">
                    <div class="reg-benefit-icon"><i class="bi bi-gift"></i></div>
                    <div class="reg-benefit-text">
                        <strong>{{ __('auth.register.benefit_trial_title') }}</strong>
                        <span>{{ __('auth.register.benefit_trial_text', ['days' => $trialDays]) }}</span>
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

            <div class="reg-left-divider"></div>

            <div class="reg-testimonial">
                <div class="reg-testimonial-stars">★★★★★</div>
                <p class="reg-testimonial-text">
                    "{{ __('auth.register.testimonial_text') }}"
                </p>
                <div class="reg-testimonial-author">
                    <div class="reg-testimonial-avatar">DR</div>
                    <div>
                        <div class="reg-testimonial-name">{{ __('auth.register.testimonial_name') }}</div>
                        <div class="reg-testimonial-role">{{ __('auth.register.testimonial_role') }}</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ═══════ PAINEL DIREITO — FORMULÁRIO ═══════ --}}
        <div class="reg-right">
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

                    {{-- ── Cabeçalho ── --}}
                    <div class="reg-card-header">
                        <div class="reg-card-title">
                            <span x-show="step === 1">{{ __('auth.register.step1_title') }}</span>
                            <span x-show="step === 2">{{ __('auth.register.step2_title') }}</span>
                        </div>
                        <div class="reg-card-subtitle">
                            <span x-show="step === 1">{{ __('auth.register.step1_subtitle', ['days' => $trialDays]) }}</span>
                            <span x-show="step === 2">{{ __('auth.register.step2_subtitle') }}</span>
                        </div>
                    </div>

                    {{-- ── Indicador de etapas ── --}}
                    <div class="step-indicator">
                        <div class="step-item" :class="{ active: step === 1, done: step > 1 }">
                            <span class="step-num">
                                <span x-show="step <= 1">1</span>
                                <span x-show="step > 1"><i class="bi bi-check2" style="font-size:13px;"></i></span>
                            </span>
                            <span>{{ __('auth.register.step_personal') }}</span>
                        </div>
                        <div class="step-sep"></div>
                        <div class="step-item" :class="{ active: step === 2 }">
                            <span class="step-num">2</span>
                            <span>{{ __('auth.register.step_company') }}</span>
                        </div>
                    </div>

                    {{-- ══════════════════════════ ETAPA 1 ══════════════════════════ --}}
                    <div x-show="step === 1"
                         x-transition:enter="transition ease-out duration-200"
                         x-transition:enter-start="opacity-0 translate-y-1"
                         x-transition:enter-end="opacity-100 translate-y-0">

                        <form @submit.prevent="nextStep" novalidate>

                            <div class="reg-field">
                                <label class="reg-label">
                                    {{ __('auth.register.name') }}
                                    <span class="req">*</span>
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
                                    {{ __('auth.register.email') }}
                                    <span class="req">*</span>
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
                                        <i class="bi ee-spin"
                                           style="color:var(--text-muted)"
                                           x-show="emailChecking"
                                           x-cloak>
                                            <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
                                                <path fill-rule="evenodd" d="M8 3a5 5 0 1 0 4.546 2.914.5.5 0 0 1 .908-.417A6 6 0 1 1 8 2z"/>
                                            </svg>
                                        </i>
                                        <i class="bi bi-check-circle-fill"
                                           style="color:var(--mint)"
                                           x-show="emailAvailable === true && !emailChecking"></i>
                                        <i class="bi bi-x-circle-fill"
                                           style="color:#ef4444"
                                           x-show="emailAvailable === false && !emailChecking"></i>
                                        <i class="bi bi-envelope"
                                           x-show="emailAvailable === null && !emailChecking"></i>
                                    </div>
                                </div>
                                <span class="reg-error" x-show="errors.email" x-text="firstError('email')"></span>
                            </div>

                            <div class="reg-field" x-data="{ showPass: false }">
                                <label class="reg-label">
                                    {{ __('auth.register.password') }}
                                    <span class="req">*</span>
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
                            </div>

                            <div class="reg-field" x-data="{ showConfirm: false }">
                                <label class="reg-label">
                                    {{ __('auth.register.confirm_password') }}
                                    <span class="req">*</span>
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

                    {{-- ══════════════════════════ ETAPA 2 ══════════════════════════ --}}
                    <div x-show="step === 2"
                         x-transition:enter="transition ease-out duration-200"
                         x-transition:enter-start="opacity-0 translate-y-1"
                         x-transition:enter-end="opacity-100 translate-y-0">

                        <form @submit.prevent="submit" novalidate>

                            <div class="reg-field">
                                <label class="reg-label">
                                    {{ __('auth.register.company_name') }}
                                    <span class="req">*</span>
                                </label>
                                <input type="text"
                                       class="reg-input"
                                       :class="{ 'is-error': errors.company_name }"
                                       x-model="form.company_name"
                                       autocomplete="organization">
                                <span class="reg-error" x-show="errors.company_name" x-text="firstError('company_name')"></span>
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

                                    <div class="plan-carousel-wrap">
                                        <button type="button"
                                                class="carousel-btn carousel-btn-prev"
                                                @click="prevSlide"
                                                :disabled="carouselIndex === 0"
                                                x-show="plans().length > 1">&#8249;</button>

                                        <template x-for="(plan, index) in plans()" :key="plan.id">
                                            <div class="plan-card"
                                                 x-show="carouselIndex === index"
                                                 x-transition:enter="transition ease-out duration-150"
                                                 x-transition:enter-start="opacity-0"
                                                 x-transition:enter-end="opacity-100">

                                                <div>
                                                    <span class="plan-trial-badge">
                                                        <span x-text="trialDays"></span> {{ __('auth.register.days_free') }}
                                                    </span>
                                                </div>
                                                <div class="plan-name" x-text="plan.name"></div>
                                                <div class="plan-price">
                                                    R$ <span x-text="plan.price"></span>
                                                    <small>/ <span x-text="plan.billing_cycle"></span></small>
                                                </div>
                                                <p class="plan-description" x-text="plan.description" x-show="plan.description"></p>

                                                <ul class="plan-features" x-show="plan.features.length > 0">
                                                    <template x-for="feat in plan.features" :key="feat.key">
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

                                        <button type="button"
                                                class="carousel-btn carousel-btn-next"
                                                @click="nextSlide"
                                                :disabled="carouselIndex === plans().length - 1"
                                                x-show="plans().length > 1">&#8250;</button>
                                    </div>

                                    <div class="carousel-dots" x-show="plans().length > 1">
                                        <template x-for="(plan, index) in plans()" :key="plan.id">
                                            <span class="dot"
                                                  :class="{ active: carouselIndex === index }"
                                                  @click="goToSlide(index)"></span>
                                        </template>
                                    </div>

                                    <div class="carousel-counter" x-show="plans().length > 1">
                                        <span x-text="carouselIndex + 1"></span> / <span x-text="plans().length"></span>
                                    </div>
                                </div>
                            @endif

                            <div class="reg-btn-row">
                                <button type="button" class="reg-btn reg-btn-secondary" @click="prevStep">
                                    <i class="bi bi-arrow-left"></i> {{ __('auth.register.back') }}
                                </button>
                                <button type="submit"
                                        class="reg-btn reg-btn-primary"
                                        :disabled="loading">
                                    <span x-show="!loading">
                                        {{ __('auth.register.start_trial') }}
                                        <template x-if="currentPlan">
                                            — <span x-text="currentPlan.name"></span>
                                        </template>
                                        <i class="bi bi-rocket-takeoff"></i>
                                    </span>
                                    <span x-show="loading">
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

                </div>
            </div>
        </div>
        {{-- FIM PAINEL DIREITO --}}

    </div>
</div>

@endsection

@push('scripts')
    <script>
        window._trans = {
            email_taken:        '{{ __('auth.register.email_taken') }}',
            field_required:     '{{ __('auth.register.field_required') }}',
            passwords_mismatch: '{{ __('auth.register.passwords_mismatch') }}',
        };
    </script>
    @vite(['resources/js/app.js'])
@endpush
