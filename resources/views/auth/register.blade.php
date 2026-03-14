@extends('layouts.guest')

@push('styles')
<style>
    .register-box { max-width: 520px; width: 100%; }

    /* Step indicator */
    .step-indicator { display: flex; align-items: center; gap: 0; margin-bottom: 1.5rem; }
    .step-indicator .step-item { display: flex; align-items: center; gap: .5rem; font-size: .85rem; font-weight: 500; color: #aaa; }
    .step-indicator .step-item.active { color: #009efb; }
    .step-indicator .step-item.done { color: #55ce63; }
    .step-indicator .step-num { width: 26px; height: 26px; border-radius: 50%; border: 2px solid #ccc; display: flex; align-items: center; justify-content: center; font-size: .78rem; font-weight: 700; flex-shrink: 0; }
    .step-indicator .step-item.active .step-num { border-color: #009efb; color: #009efb; background: #e8f4fd; }
    .step-indicator .step-item.done .step-num { border-color: #55ce63; background: #55ce63; color: #fff; }
    .step-indicator .step-sep { flex: 1; height: 2px; background: #e0e0e0; margin: 0 .25rem; }

    /* Plan cards */
    .plan-card { border: 2px solid #009efb; border-radius: 10px; padding: 1.25rem 1rem; text-align: center; box-shadow: 0 0 0 3px rgba(0,158,251,.12); }
    .plan-card .plan-name { font-size: 1.05rem; font-weight: 700; margin-bottom: .2rem; }
    .plan-card .plan-price { font-size: 1.5rem; font-weight: 800; color: #009efb; }
    .plan-card .plan-price small { font-size: .75rem; font-weight: 400; color: #888; }
    .plan-card .plan-features { list-style: none; padding: 0; margin: .75rem 0 0; text-align: left; font-size: .84rem; }
    .plan-card .plan-features li { padding: .22rem 0; color: #555; }
    .plan-card .plan-features li .feat-val { font-weight: 600; color: #333; }

    /* Carousel */
    .plan-carousel-wrap { position: relative; padding: 0 2.25rem; }
    .carousel-btn { position: absolute; top: 50%; transform: translateY(-50%); background: #fff; border: 1.5px solid #dee2e6; border-radius: 50%; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; cursor: pointer; color: #555; font-size: 1.2rem; line-height: 1; transition: all .15s; z-index: 1; padding: 0; }
    .carousel-btn:hover:not(:disabled) { background: #009efb; border-color: #009efb; color: #fff; }
    .carousel-btn:disabled { opacity: .25; cursor: default; pointer-events: none; }
    .carousel-btn-prev { left: 0; }
    .carousel-btn-next { right: 0; }
    .carousel-dots { display: flex; justify-content: center; gap: 6px; margin-top: .65rem; }
    .carousel-dots .dot { width: 8px; height: 8px; border-radius: 50%; background: #ddd; cursor: pointer; transition: background .2s; }
    .carousel-dots .dot.active { background: #009efb; }
    .carousel-counter { font-size: .78rem; color: #aaa; text-align: center; margin-top: .3rem; }

    /* Trial badge on plan card */
    .plan-trial-badge { display: inline-block; background: #28a745; color: #fff; font-size: .72rem; font-weight: 700; padding: .18rem .6rem; border-radius: 20px; margin-bottom: .45rem; letter-spacing: .02em; }
    .plan-card .plan-description { font-size: .78rem; color: #888; margin: .25rem 0 0; line-height: 1.35; }

    /* "ou" separator before quick-start */
    .quick-start-sep { position: relative; text-align: center; margin: .8rem 0 .4rem; }
    .quick-start-sep::before { content: ''; position: absolute; top: 50%; left: 0; right: 0; height: 1px; background: #eee; }
    .quick-start-sep span { position: relative; background: #fff; padding: 0 .75rem; font-size: .78rem; color: #bbb; }

    /* Error text */
    .field-error { display: block; font-size: .8rem; color: #dc3545; margin-top: .25rem; }

    /* Input valid/invalid indicators */
    .email-ok  { border-color: #55ce63 !important; }
    .email-bad { border-color: #dc3545 !important; }
</style>
@endpush

@section('content')
{{-- Pass plans to Alpine as JSON --}}
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
                'display_value' => $f->feature->isBoolean() ? null : ($f->value == '0' ? __('subscriptions.expired_page.unlimited') : $f->value),
            ])->values(),
        ];
    })->toJson(JSON_HEX_QUOT | JSON_HEX_APOS);
@endphp

<div class="card-body register-box"
     x-data="registerWizard({{ $plansData }}, {{ $trialDays }})"
     style="margin: 0 auto;">

    {{-- Header --}}
    <div class="text-center mb-3">
        <a href="{{ route('login') }}">
            <img src="{{ asset('system/images/logo-icon.png') }}" alt="{{ config('app.name') }}" width="48" class="mb-2">
        </a>
        <h4 class="font-medium mb-0">{{ config('app.name') }}</h4>
        <p class="text-muted mb-0" style="font-size:.85rem">{{ __('auth.register.title') }}</p>
    </div>

    {{-- Step indicator --}}
    <div class="step-indicator">
        <div class="step-item" :class="{ active: step === 1, done: step > 1 }">
            <span class="step-num">
                <span x-show="step <= 1">1</span>
                <span x-show="step > 1">✓</span>
            </span>
            <span>{{ __('auth.register.step_personal') }}</span>
        </div>
        <div class="step-sep"></div>
        <div class="step-item" :class="{ active: step === 2 }">
            <span class="step-num">2</span>
            <span>{{ __('auth.register.step_company') }}</span>
        </div>
    </div>

    {{-- ── STEP 1 — Personal data ─────────────────────────────────── --}}
    <div x-show="step === 1" x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100">

        <form @submit.prevent="nextStep">

            {{-- Name --}}
            <div class="form-group mb-3">
                <label class="form-label">{{ __('auth.register.name') }} <span class="text-danger">*</span></label>
                <input type="text" class="form-control" :class="{ 'is-invalid': errors.name }"
                       x-model="form.name" autocomplete="name" autofocus required>
                <span class="field-error" x-show="errors.name" x-text="firstError('name')"></span>
            </div>

            {{-- Email --}}
            <div class="form-group mb-3">
                <label class="form-label">{{ __('auth.register.email') }} <span class="text-danger">*</span></label>
                <div class="input-group">
                    <input type="email" class="form-control"
                           :class="{
                               'is-invalid': errors.email,
                               'email-ok':  emailAvailable === true,
                               'email-bad': emailAvailable === false
                           }"
                           x-model="form.email"
                           @blur="checkEmailAvailability"
                           autocomplete="username" required>
                    <span class="input-group-text bg-white">
                        <span x-show="emailChecking"><i class="fa fa-spinner fa-spin text-muted"></i></span>
                        <span x-show="emailAvailable === true && !emailChecking"><i class="fa fa-check text-success"></i></span>
                        <span x-show="emailAvailable === false && !emailChecking"><i class="fa fa-times text-danger"></i></span>
                        <span x-show="emailAvailable === null && !emailChecking"><i class="mdi mdi-email-outline text-muted"></i></span>
                    </span>
                </div>
                <span class="field-error" x-show="errors.email" x-text="firstError('email')"></span>
            </div>

            {{-- Password --}}
            <div class="form-group mb-3">
                <label class="form-label">{{ __('auth.register.password') }} <span class="text-danger">*</span></label>
                <div class="input-group">
                    <input type="password" id="reg-password" class="form-control" :class="{ 'is-invalid': errors.password }"
                           x-model="form.password" autocomplete="new-password" required>
                    <button type="button" class="btn btn-outline-secondary" data-toggle-password="#reg-password" tabindex="-1">
                        <i class="mdi mdi-eye-off"></i>
                    </button>
                </div>
                <span class="field-error" x-show="errors.password" x-text="firstError('password')"></span>
            </div>

            {{-- Confirm password --}}
            <div class="form-group mb-4">
                <label class="form-label">{{ __('auth.register.confirm_password') }} <span class="text-danger">*</span></label>
                <div class="input-group">
                    <input type="password" id="reg-password-confirmation" class="form-control" :class="{ 'is-invalid': errors.password_confirmation }"
                           x-model="form.password_confirmation" autocomplete="new-password" required>
                    <button type="button" class="btn btn-outline-secondary" data-toggle-password="#reg-password-confirmation" tabindex="-1">
                        <i class="mdi mdi-eye-off"></i>
                    </button>
                </div>
                <span class="field-error" x-show="errors.password_confirmation" x-text="firstError('password_confirmation')"></span>
            </div>

            <div class="d-grid mb-3">
                <button type="submit" class="btn btn-info btn-block waves-effect waves-light">
                    {{ __('auth.register.next') }} &rarr;
                </button>
            </div>

        </form>

        <p class="text-center text-muted" style="font-size:.85rem">
            {{ __('auth.register.already_registered') }}
            <a href="{{ route('login') }}">{{ __('auth.register.log_in') }}</a>
        </p>
    </div>

    {{-- ── STEP 2 — Company + Plan ────────────────────────────────── --}}
    <div x-show="step === 2" x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100">

        <form @submit.prevent="submit">

            {{-- Company name --}}
            <div class="form-group mb-3">
                <label class="form-label">{{ __('auth.register.company_name') }} <span class="text-danger">*</span></label>
                <input type="text" class="form-control" :class="{ 'is-invalid': errors.company_name }"
                       x-model="form.company_name" autocomplete="organization" required>
                <span class="field-error" x-show="errors.company_name" x-text="firstError('company_name')"></span>
            </div>

            {{-- CNPJ (optional) --}}
            <div class="form-group mb-4">
                <label class="form-label">
                    {{ __('auth.register.cnpj') }}
                    <span class="text-muted" style="font-size:.8rem">({{ __('auth.register.optional') }})</span>
                </label>
                <input type="text" class="form-control" :class="{ 'is-invalid': errors.company_cnpj }"
                       x-model="form.company_cnpj"
                       x-mask-br="'cnpj'"
                       maxlength="18"
                       placeholder="00.000.000/0000-00">
                <span class="field-error" x-show="errors.company_cnpj" x-text="firstError('company_cnpj')"></span>
            </div>

            {{-- Plan selection — carousel --}}
            @if($plans->isNotEmpty())
            <div class="mb-4">
                <label class="form-label fw-semibold">{{ __('auth.register.choose_plan') }}</label>

                <div class="plan-carousel-wrap">

                    {{-- Prev button --}}
                    <button type="button" class="carousel-btn carousel-btn-prev"
                            @click="prevSlide"
                            :disabled="carouselIndex === 0"
                            x-show="plans().length > 1">&#8249;</button>

                    {{-- Plan cards (one visible at a time) --}}
                    <template x-for="(plan, index) in plans()" :key="plan.id">
                        <div class="plan-card"
                             x-show="carouselIndex === index"
                             x-transition:enter="transition ease-out duration-150"
                             x-transition:enter-start="opacity-0"
                             x-transition:enter-end="opacity-100">

                            <div>
                                <span class="plan-trial-badge">
                                    <span x-text="trialDays"></span> dias grátis
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
                                            <span :class="feat.value === '1' ? 'text-success' : 'text-danger'"
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

                    {{-- Next button --}}
                    <button type="button" class="carousel-btn carousel-btn-next"
                            @click="nextSlide"
                            :disabled="carouselIndex === plans().length - 1"
                            x-show="plans().length > 1">&#8250;</button>

                </div>

                {{-- Dots --}}
                <div class="carousel-dots" x-show="plans().length > 1">
                    <template x-for="(plan, index) in plans()" :key="plan.id">
                        <span class="dot" :class="{ active: carouselIndex === index }"
                              @click="goToSlide(index)"></span>
                    </template>
                </div>

                {{-- Counter e.g. "2 / 3" --}}
                <div class="carousel-counter" x-show="plans().length > 1">
                    <span x-text="carouselIndex + 1"></span> / <span x-text="plans().length"></span>
                </div>

            </div>
            @endif

            {{-- Actions --}}
            <div class="d-flex gap-2 mb-2 mt-3">
                <button type="button" class="btn btn-light flex-shrink-0" @click="prevStep">
                    &larr; {{ __('auth.register.back') }}
                </button>
                <button type="submit" class="btn btn-info btn-block w-100 waves-effect waves-light"
                        :disabled="loading">
                    <span x-show="!loading">
                        Iniciar <span x-text="trialDays"></span> dias grátis
                        <template x-if="currentPlan">
                            &mdash; Plano <span x-text="currentPlan.name"></span>
                        </template>
                    </span>
                    <span x-show="loading"><i class="fa fa-spinner fa-spin me-1"></i> {{ __('auth.register.processing') }}</span>
                </button>
            </div>

            {{-- Quick start (sem escolher plano) --}}
            @if($plans->count() > 1)
            <div class="quick-start-sep"><span>ou</span></div>
            <div class="text-center mb-3">
                <button type="button"
                        class="btn btn-link text-muted p-0"
                        style="font-size:.82rem"
                        @click="quickStart"
                        :disabled="loading">
                    Começar gratuitamente com o plano
                    <span x-text="plans()[0]?.name ?? 'Básico'"></span> &rarr;
                </button>
            </div>
            @endif

        </form>
    </div>

</div>
@endsection

@push('scripts')
    @vite(['resources/js/app.js'])
@endpush

@section('javascript')
    @vite(['resources/js/auth/register.js'])
@endsection

