@extends('layouts.app')

@section('title', __('subscriptions.expired_page.title'))

@section('content')
<div class="container py-5">

    {{-- Banner de aviso --}}
    <div class="alert alert-warning d-flex align-items-start gap-3 mb-4" role="alert">
        <i class="fas fa-exclamation-triangle fa-2x mt-1 text-warning"></i>
        <div>
            <h5 class="alert-heading mb-1">{{ __('subscriptions.expired_page.heading') }}</h5>
            <p class="mb-0">
                @if($lastSubscription?->inGracePeriod())
                    {{ __('subscriptions.expired_page.grace_period', [
                        'date' => $lastSubscription->grace_period_ends_at->format('d/m/Y H:i'),
                    ]) }}
                @else
                    {{ __('subscriptions.access_blocked') }}
                @endif
            </p>
            @if($lastSubscription)
            <small class="text-muted">
                {{ __('subscriptions.expired_page.last_plan') }}: <strong>{{ $lastSubscription->plan->name }}</strong>
                &mdash; {{ $lastSubscription->status->label() }}
            </small>
            @endif
        </div>
    </div>

    {{-- Planos disponíveis --}}
    <h4 class="mb-4 text-center">{{ __('subscriptions.expired_page.choose_plan') }}</h4>

    <div class="row row-cols-1 row-cols-md-3 g-4 mb-5">
        @foreach($plans as $item)
        @php
            /** @var \App\Models\Plan $plan */
            $plan = $item['model'];
            $features = $item['features_map'];
            $popular = $plan->sort_order === 2; // Pro = destacado
        @endphp
        <div class="col">
            <div class="card h-100 {{ $popular ? 'border-primary shadow' : '' }}">
                @if($popular)
                <div class="card-header bg-primary text-white text-center fw-bold py-1">
                    <i class="fas fa-star me-1"></i> {{ __('subscriptions.expired_page.most_popular') }}
                </div>
                @endif
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title">{{ $plan->name }}</h5>
                    <p class="text-muted small mb-3">{{ $plan->description }}</p>

                    <div class="mb-3">
                        <span class="fs-3 fw-bold">R$ {{ number_format($plan->price, 2, ',', '.') }}</span>
                        <span class="text-muted">/ {{ $plan->billing_cycle->label() }}</span>
                    </div>

                    {{-- Feature list --}}
                    <ul class="list-unstyled mb-4 flex-grow-1">
                        @foreach(\App\Enums\FeatureKey::cases() as $featureKey)
                        @php $value = $features[$featureKey->value] ?? null; @endphp
                        @if($value !== null)
                        <li class="mb-1">
                            @if($featureKey->isBoolean())
                                @if($value === '1')
                                    <i class="fas fa-check-circle text-success me-1"></i>
                                @else
                                    <i class="fas fa-times-circle text-danger me-1"></i>
                                @endif
                                {{ $featureKey->label() }}
                            @else
                                <i class="fas fa-check-circle text-success me-1"></i>
                                {{ $featureKey->label() }}:
                                <strong>{{ $value === '0' ? __('subscriptions.expired_page.unlimited') : $value }}</strong>
                            @endif
                        </li>
                        @endif
                        @endforeach
                    </ul>

                    {{--
                        Botão de upgrade — ao integrar com gateway de pagamento,
                        substitua este link pela rota de checkout do plano.
                    --}}
                    <a href="mailto:{{ config('mail.support_address', 'contato@easyeye.com.br') }}?subject=Assinatura%20{{ urlencode($plan->name) }}"
                       class="btn {{ $popular ? 'btn-primary' : 'btn-outline-primary' }} w-100">
                        {{ __('subscriptions.expired_page.upgrade_cta') }}
                    </a>
                </div>
            </div>
        </div>
        @endforeach
    </div>

    {{-- Footer --}}
    <p class="text-center text-muted small">
        {{ __('subscriptions.expired_page.contact_support') }}
        <a href="mailto:{{ config('mail.support_address', 'contato@easyeye.com.br') }}">
            {{ config('mail.support_address', 'contato@easyeye.com.br') }}
        </a>
    </p>

</div>
@endsection
