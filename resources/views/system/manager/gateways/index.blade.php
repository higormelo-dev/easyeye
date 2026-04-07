@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')

<p class="text-muted small mb-4">
    {!! __('gateways.subtitle') !!}
</p>

{{-- ══ Painel: Gateway Padrão ══════════════════════════════════════════════ --}}
@if($defaultGateway)
<div class="alert d-flex align-items-center gap-3 py-3 mb-4"
     style="background:linear-gradient(135deg,#fff8e1 0%,#fffde7 100%);border:1.5px solid #fdd835;border-radius:10px;">
    <div class="flex-shrink-0 d-flex align-items-center justify-content-center rounded-circle"
         style="width:42px;height:42px;background:#fdd835;">
        <i class="ti ti-star-filled text-dark fs-20"></i>
    </div>
    <div class="flex-grow-1">
        <div class="fw-bold mb-0" style="color:#5d4037;">
            {{ __('gateways.default_banner_title') }}
        </div>
        <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
            <span class="fw-semibold" style="color:#333;">{{ $defaultGateway->name }}</span>
            <span class="badge text-uppercase" style="background:#fdd835;color:#5d4037;font-size:.7rem;">{{ $defaultGateway->code }}</span>
            <span class="text-muted small">{{ __('gateways.default_banner_subtitle') }}</span>
        </div>
    </div>
    <div class="flex-shrink-0">
        <button type="button"
                class="btn btn-sm btn-outline-secondary"
                data-bs-toggle="modal" data-bs-target="#changeDefaultModal">
            <i class="ti ti-switch-horizontal me-1"></i>{{ __('gateways.default_banner_change') }}
        </button>
    </div>
</div>
@else
<div class="alert alert-danger d-flex align-items-center gap-3 py-3 mb-4" style="border-radius:10px;">
    <i class="ti ti-alert-octagon fs-22 flex-shrink-0"></i>
    <div class="flex-grow-1">
        <strong>{{ __('gateways.no_default_title') }}</strong>
        <span class="ms-1 small">{{ __('gateways.no_default_subtitle') }}</span>
    </div>
    <button type="button"
            class="btn btn-sm btn-danger flex-shrink-0"
            data-bs-toggle="modal" data-bs-target="#changeDefaultModal">
        <i class="ti ti-star me-1"></i>{{ __('gateways.no_default_action') }}
    </button>
</div>
@endif
{{-- ══ /Painel Gateway Padrão ════════════════════════════════════════════════ --}}

{{-- ══ Contextos ═══════════════════════════════════════════════════════════ --}}
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card border-primary border-opacity-50 h-100">
            <div class="card-body py-3">
                <div class="d-flex gap-3 align-items-start">
                    <div class="d-flex align-items-center justify-content-center rounded-2 flex-shrink-0 bg-primary bg-opacity-10"
                         style="width:38px;height:38px;">
                        <i class="ti ti-building-store text-primary fs-18"></i>
                    </div>
                    <div>
                        <p class="fw-semibold mb-1 small">{{ __('gateways.ctx_saas_title') }}</p>
                        <p class="text-muted mb-1" style="font-size:.8rem;">
                            {!! __('gateways.ctx_saas_desc') !!}
                        </p>
                        <span class="badge badge-soft-primary" style="font-size:.72rem;">{{ __('gateways.ctx_saas_badge') }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-success border-opacity-50 h-100">
            <div class="card-body py-3">
                <div class="d-flex gap-3 align-items-start">
                    <div class="d-flex align-items-center justify-content-center rounded-2 flex-shrink-0 bg-success bg-opacity-10"
                         style="width:38px;height:38px;">
                        <i class="ti ti-users text-success fs-18"></i>
                    </div>
                    <div>
                        <p class="fw-semibold mb-1 small">{{ __('gateways.ctx_tenant_title') }}</p>
                        <p class="text-muted mb-1" style="font-size:.8rem;">
                            {!! __('gateways.ctx_tenant_desc') !!}
                        </p>
                        <span class="badge badge-soft-success" style="font-size:.72rem;">{{ __('gateways.ctx_tenant_badge') }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
{{-- ══ /Contextos ════════════════════════════════════════════════════════════ --}}

{{-- ══ Cards dos Gateways ═══════════════════════════════════════════════════ --}}
<div class="row g-3">
    @foreach($gateways as $gateway)
    @php $isDefault = $defaultGateway?->id === $gateway->id; @endphp
    <div class="col-12 col-md-6 col-xl-4">
        <div class="card h-100 {{ $gateway->active ? '' : 'opacity-75' }}"
             style="{{ $isDefault ? 'border:2px solid #fdd835 !important;' : '' }}">

            {{-- Header --}}
            <div class="card-header d-flex align-items-center justify-content-between py-2 px-3"
                 style="{{ $isDefault ? 'background:linear-gradient(135deg,#fff8e1,#fffde7);' : '' }}">
                <div class="d-flex align-items-center gap-2">
                    @if($isDefault)
                        <i class="ti ti-star-filled" style="color:#f9a825;" title="{{ __('gateways.default_badge') }}"></i>
                    @endif
                    <span class="fw-bold">{{ $gateway->name }}</span>
                    <span class="badge badge-soft-secondary text-uppercase"
                          style="font-size:.7rem;letter-spacing:.04em;">{{ $gateway->code }}</span>
                    @if($isDefault)
                        <span class="badge" style="background:#fdd835;color:#5d4037;font-size:.7rem;">{{ __('gateways.default_badge') }}</span>
                    @endif
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge badge-soft-{{ $gateway->active ? 'success' : 'secondary' }}">
                        {{ $gateway->active ? __('gateways.status_active') : __('gateways.status_inactive') }}
                    </span>
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input gateway-toggle"
                               type="checkbox"
                               role="switch"
                               data-url="{{ route('panel.manager.gateways.toggle-active', $gateway) }}"
                               {{ $gateway->active ? 'checked' : '' }}
                               title="{{ $gateway->active ? __('gateways.toggle_deactivate') : __('gateways.toggle_activate') }}">
                    </div>
                </div>
            </div>

            {{-- Body --}}
            <div class="card-body py-3 px-3">

                {{-- Prioridade --}}
                <div class="d-flex align-items-center gap-2 mb-3">
                    <span class="text-muted small">{{ __('gateways.priority_label') }}</span>
                    <span class="badge badge-soft-info">
                        <i class="ti ti-sort-ascending me-1"></i>{{ $gateway->priority }}
                    </span>
                    <button type="button"
                            class="btn btn-link btn-sm p-0 text-muted btn-priority"
                            data-gateway-name="{{ $gateway->name }}"
                            data-priority="{{ $gateway->priority }}"
                            data-url="{{ route('panel.manager.gateways.priority', $gateway) }}"
                            style="font-size:.78rem;">
                        {{ __('gateways.priority_change') }}
                    </button>
                </div>

                {{-- Credenciais de Billing --}}
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="d-flex align-items-center gap-2">
                        <i class="ti ti-key text-muted" style="font-size:.95rem;"></i>
                        <span class="small text-muted">{{ __('gateways.billing_credentials') }}</span>
                    </div>
                    @if($gateway->active_credentials_count > 0)
                        <span class="badge badge-soft-success" style="font-size:.72rem;">
                            <i class="ti ti-check me-1"></i>{{ trans_choice('gateways.credentials_active', $gateway->active_credentials_count, ['count' => $gateway->active_credentials_count]) }}
                        </span>
                    @else
                        <span class="badge badge-soft-warning" style="font-size:.72rem;">
                            <i class="ti ti-alert-triangle me-1"></i>{{ __('gateways.credentials_none') }}
                        </span>
                    @endif
                </div>

                {{-- Acesso de Clínicas --}}
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <div class="d-flex align-items-center gap-2">
                        <i class="ti ti-building-hospital text-muted" style="font-size:.95rem;"></i>
                        <span class="small text-muted">{{ __('gateways.clinics_with_access') }}</span>
                    </div>
                    @if($gateway->entities_with_access_count > 0)
                        <span class="badge badge-soft-primary" style="font-size:.72rem;">
                            {{ trans_choice('gateways.clinics_count', $gateway->entities_with_access_count, ['count' => $gateway->entities_with_access_count]) }}
                        </span>
                    @else
                        <span class="badge badge-soft-secondary" style="font-size:.72rem;">{{ __('gateways.clinics_none') }}</span>
                    @endif
                </div>

                {{-- Capacidades --}}
                <div class="d-flex flex-wrap gap-1">
                    @if($gateway->supports_subscriptions)
                        <span class="badge badge-soft-success" style="font-size:.7rem;"><i class="ti ti-refresh me-1"></i>{{ __('gateways.cap_subscriptions') }}</span>
                    @endif
                    @if($gateway->supports_one_time_charges)
                        <span class="badge badge-soft-success" style="font-size:.7rem;"><i class="ti ti-bolt me-1"></i>{{ __('gateways.cap_one_time') }}</span>
                    @endif
                    @if($gateway->supports_refunds)
                        <span class="badge badge-soft-success" style="font-size:.7rem;"><i class="ti ti-arrow-back me-1"></i>{{ __('gateways.cap_refunds') }}</span>
                    @endif
                    @if($gateway->supports_webhooks)
                        <span class="badge badge-soft-success" style="font-size:.7rem;"><i class="ti ti-webhook me-1"></i>{{ __('gateways.cap_webhooks') }}</span>
                    @endif
                </div>
            </div>

            {{-- Footer --}}
            <div class="card-footer bg-transparent py-2 px-3">
                <div class="d-flex gap-2 mb-2">
                    <button type="button"
                            class="btn btn-sm btn-outline-primary flex-grow-1 btn-credentials"
                            data-gateway-id="{{ $gateway->id }}"
                            data-gateway-name="{{ $gateway->name }}"
                            data-gateway-code="{{ $gateway->code }}"
                            data-url="{{ route('panel.manager.gateways.credentials', $gateway) }}"
                            data-store-url="{{ route('panel.manager.gateways.credentials.store', $gateway) }}">
                        <i class="ti ti-key me-1"></i>{{ __('gateways.btn_credentials') }}
                    </button>
                    <button type="button"
                            class="btn btn-sm btn-outline-success flex-grow-1 btn-entity-access"
                            data-gateway-name="{{ $gateway->name }}"
                            data-url="{{ route('panel.manager.gateways.entity-access', $gateway) }}">
                        <i class="ti ti-building-hospital me-1"></i>{{ __('gateways.btn_entity_access') }}
                    </button>
                </div>
                @if(!$isDefault)
                <button type="button"
                        class="btn btn-sm w-100 btn-set-default {{ $gateway->active_credentials_count > 0 && $gateway->active ? 'btn-outline-warning' : 'btn-outline-secondary disabled' }}"
                        data-gateway-id="{{ $gateway->id }}"
                        data-gateway-name="{{ $gateway->name }}"
                        data-url="{{ route('panel.manager.gateways.set-default', $gateway) }}"
                        {{ !($gateway->active_credentials_count > 0 && $gateway->active) ? 'disabled' : '' }}
                        title="{{ !$gateway->active ? __('gateways.btn_activate_first') : (!($gateway->active_credentials_count > 0) ? __('gateways.btn_add_credential') : __('gateways.btn_set_default_title')) }}">
                    <i class="ti ti-star me-1"></i>{{ __('gateways.btn_set_default') }}
                </button>
                @else
                <button type="button" class="btn btn-sm w-100 btn-outline-secondary" disabled
                        style="border-color:#fdd835;color:#f9a825;">
                    <i class="ti ti-star-filled me-1"></i>{{ __('gateways.btn_current_default') }}
                </button>
                @endif
            </div>
        </div>
    </div>
    @endforeach
</div>
{{-- ══ /Cards ════════════════════════════════════════════════════════════════ --}}

{{-- ══ Modal: Trocar Gateway Padrão ════════════════════════════════════════ --}}
<div class="modal fade" id="changeDefaultModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="ti ti-star me-2 text-warning"></i>{{ __('gateways.modal_default_title') }}
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning d-flex gap-2 align-items-start py-2 mb-4">
                    <i class="ti ti-alert-triangle flex-shrink-0 mt-1"></i>
                    <div class="small">
                        {!! __('gateways.modal_default_alert') !!}
                    </div>
                </div>

                <div class="list-group list-group-flush" id="default-gateway-list">
                    @foreach($gateways as $gateway)
                    @php
                        $canBeDefault = $gateway->active && $gateway->active_credentials_count > 0;
                        $isDefault    = $defaultGateway?->id === $gateway->id;
                    @endphp
                    <div class="list-group-item list-group-item-action d-flex align-items-center gap-3 px-0 py-3
                                {{ !$canBeDefault ? 'opacity-50' : '' }}"
                         style="border-left:0;border-right:0;">
                        <div class="flex-grow-1">
                            <div class="d-flex align-items-center gap-2">
                                @if($isDefault)
                                    <i class="ti ti-star-filled" style="color:#f9a825;"></i>
                                @endif
                                <span class="fw-semibold small">{{ $gateway->name }}</span>
                                <span class="badge badge-soft-secondary text-uppercase" style="font-size:.68rem;">{{ $gateway->code }}</span>
                                @if($isDefault)
                                    <span class="badge" style="background:#fdd835;color:#5d4037;font-size:.68rem;">{{ __('gateways.modal_default_current') }}</span>
                                @endif
                            </div>
                            <div class="d-flex gap-1 mt-1">
                                @if(!$gateway->active)
                                    <span class="badge badge-soft-secondary" style="font-size:.68rem;">{{ __('gateways.status_inactive') }}</span>
                                @endif
                                @if($gateway->active_credentials_count === 0)
                                    <span class="badge badge-soft-warning" style="font-size:.68rem;">{{ __('gateways.credentials_none') }}</span>
                                @else
                                    <span class="badge badge-soft-success" style="font-size:.68rem;">{{ trans_choice('gateways.credentials_active', $gateway->active_credentials_count, ['count' => $gateway->active_credentials_count]) }}</span>
                                @endif
                            </div>
                        </div>
                        <div class="flex-shrink-0">
                            @if($isDefault)
                                <button class="btn btn-sm" style="background:#fdd835;color:#5d4037;border:none;" disabled>
                                    <i class="ti ti-check me-1"></i>{{ __('gateways.default_badge') }}
                                </button>
                            @elseif($canBeDefault)
                                <button type="button"
                                        class="btn btn-sm btn-outline-warning btn-modal-set-default"
                                        data-url="{{ route('panel.manager.gateways.set-default', $gateway) }}"
                                        data-name="{{ $gateway->name }}">
                                    <i class="ti ti-star me-1"></i>{{ __('gateways.modal_default_btn') }}
                                </button>
                            @else
                                <button class="btn btn-sm btn-outline-secondary" disabled>
                                    {{ __('gateways.modal_default_unavailable') }}
                                </button>
                            @endif
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">{{ __('gateways.modal_default_close') }}</button>
            </div>
        </div>
    </div>
</div>
{{-- ══ /Modal Gateway Padrão ════════════════════════════════════════════════ --}}

{{-- ══ Modal: Credenciais de Billing ═══════════════════════════════════════ --}}
<div class="modal fade" id="credentialsModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="ti ti-key me-2"></i>{{ __('gateways.modal_cred_title') }} — <span id="cred-gateway-name"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info d-flex gap-2 align-items-start py-2 mb-3">
                    <i class="ti ti-info-circle flex-shrink-0 mt-1"></i>
                    <div class="small">
                        {!! __('gateways.modal_cred_alert') !!}
                    </div>
                </div>

                <h6 class="fw-semibold mb-2 small text-uppercase text-muted">{{ __('gateways.modal_cred_history') }}</h6>
                <div id="cred-list-loading" class="text-center py-3 d-none">
                    <div class="spinner-border spinner-border-sm text-primary"></div>
                </div>
                <div id="cred-list-empty" class="text-center py-3 text-muted small d-none">
                    {{ __('gateways.modal_cred_empty') }}
                </div>
                <div id="cred-list-container" class="mb-4"></div>

                <hr class="my-3">
                <h6 class="fw-semibold mb-3 small text-uppercase text-muted">
                    <i class="ti ti-plus me-1"></i>{{ __('gateways.modal_cred_new') }}
                </h6>
                <form id="cred-store-form" autocomplete="off">
                    <input type="hidden" id="cred-store-url">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label small fw-semibold">{{ __('gateways.modal_cred_label') }}</label>
                            <input type="text" class="form-control form-control-sm" name="label"
                                   placeholder="{{ __('gateways.modal_cred_label_ph') }}">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold" id="cred-secret-label">
                                {{ __('gateways.modal_cred_api_key') }} <span class="text-danger">*</span>
                            </label>
                            <div class="input-group input-group-sm">
                                <input type="password" class="form-control font-monospace" name="secret"
                                       id="cred-secret-input" autocomplete="new-password" required
                                       placeholder="{{ __('gateways.modal_cred_api_key_ph') }}">
                                <button class="btn btn-outline-secondary" type="button" id="cred-toggle-secret">
                                    <i class="ti ti-eye"></i>
                                </button>
                            </div>
                            <div class="form-text" id="cred-secret-hint"></div>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">
                                {{ __('gateways.modal_cred_webhook') }} <span class="text-muted fw-normal">{{ __('gateways.modal_cred_webhook_opt') }}</span>
                            </label>
                            <div class="input-group input-group-sm">
                                <input type="password" class="form-control font-monospace" name="webhook_secret"
                                       id="cred-webhook-input" autocomplete="new-password">
                                <button class="btn btn-outline-secondary" type="button" id="cred-toggle-webhook">
                                    <i class="ti ti-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">{{ __('gateways.modal_cred_valid_from') }}</label>
                            <input type="date" class="form-control form-control-sm" name="valid_from">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">{{ __('gateways.modal_cred_valid_to') }}</label>
                            <input type="date" class="form-control form-control-sm" name="valid_to">
                        </div>
                    </div>
                    <div id="cred-store-error" class="alert alert-danger mt-3 py-2 small d-none"></div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">{{ __('gateways.modal_cred_close') }}</button>
                <button type="button" class="btn btn-primary btn-sm" id="cred-store-btn">
                    <span id="cred-store-spinner" class="spinner-border spinner-border-sm me-1 d-none"></span>
                    <i class="ti ti-device-floppy me-1"></i>{{ __('gateways.modal_cred_save') }}
                </button>
            </div>
        </div>
    </div>
</div>
{{-- ══ /Modal Credenciais ════════════════════════════════════════════════════ --}}

{{-- ══ Modal: Acesso por Clínica ═══════════════════════════════════════════ --}}
<div class="modal fade" id="entityAccessModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="ti ti-building-hospital me-2"></i>{{ __('gateways.modal_ea_title') }} — <span id="ea-gateway-name"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info d-flex gap-2 align-items-start py-2 mb-3">
                    <i class="ti ti-info-circle flex-shrink-0 mt-1"></i>
                    <div class="small">
                        {{ __('gateways.modal_ea_alert') }}
                    </div>
                </div>
                <div class="mb-3">
                    <input type="text" class="form-control form-control-sm" id="ea-search"
                           placeholder="{{ __('gateways.modal_ea_search_ph') }}">
                </div>
                <div id="ea-list-loading" class="text-center py-4 d-none">
                    <div class="spinner-border spinner-border-sm text-primary"></div>
                    <p class="text-muted small mt-2 mb-0">{{ __('gateways.modal_ea_loading') }}</p>
                </div>
                <div id="ea-list-empty" class="text-center py-4 text-muted small d-none">
                    {{ __('gateways.modal_ea_empty') }}
                </div>
                <div id="ea-list-container"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">{{ __('gateways.modal_ea_close') }}</button>
            </div>
        </div>
    </div>
</div>
{{-- ══ /Modal Acesso por Clínica ════════════════════════════════════════════ --}}

{{-- ══ Modal: Prioridade ═══════════════════════════════════════════════════ --}}
<div class="modal fade" id="priorityModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="ti ti-sort-ascending me-2"></i>{{ __('gateways.modal_priority_title') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="priority-form">
                <div class="modal-body">
                    <input type="hidden" id="priority-url">
                    <p class="fw-semibold mb-1 small" id="priority-gateway-name"></p>
                    <p class="text-muted small mb-3">
                        {{ __('gateways.modal_priority_desc') }}
                    </p>
                    <input type="number" class="form-control form-control-sm" id="priority-input"
                           min="1" max="999" required>
                    <div id="priority-error" class="alert alert-danger mt-2 py-2 small d-none"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">{{ __('gateways.modal_priority_cancel') }}</button>
                    <button type="submit" class="btn btn-primary btn-sm">{{ __('gateways.modal_priority_save') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
{{-- ══ /Modal Prioridade ════════════════════════════════════════════════════ --}}

@endsection

@section('javascript')
<script>
$(function () {
    const csrf = $('meta[name="csrf-token"]').attr('content');

    // Translations passed from Blade to JS
    const _t = {
        confirmSetDefault:      @json(__('gateways.js_confirm_set_default')),
        confirmSetDefaultModal: @json(__('gateways.js_confirm_set_default_modal')),
        confirmRevoke:          @json(__('gateways.js_confirm_revoke')),
        errorSetDefault:        @json(__('gateways.js_error_set_default')),
        errorGeneric:           @json(__('gateways.js_error_generic')),
        errorSave:              @json(__('gateways.js_error_save')),
        errorLoad:              @json(__('gateways.js_error_load')),
        errorLoadClinics:       @json(__('gateways.js_error_load_clinics')),
        noLabel:                @json(__('gateways.js_no_label')),
        credActive:             @json(__('gateways.modal_cred_active')),
        credInactive:           @json(__('gateways.modal_cred_inactive')),
        credHidden:             @json(__('gateways.modal_cred_hidden')),
        credRevoke:             @json(__('gateways.modal_cred_revoke')),
        eaEnable:               @json(__('gateways.modal_ea_enable')),
        eaDisable:              @json(__('gateways.modal_ea_disable')),
    };

    const gatewaySecretLabels = @json(__('gateways.secret_label'));

    // ── Definir Gateway Padrão (cards) ────────────────────────────────────
    $(document).on('click', '.btn-set-default', function () {
        const url  = $(this).data('url');
        const name = $(this).data('gateway-name');
        if (!confirm(_t.confirmSetDefault.replace(':name', name))) return;
        callSetDefault(url);
    });

    // ── Definir Gateway Padrão (modal de troca) ───────────────────────────
    $(document).on('click', '.btn-modal-set-default', function () {
        const url  = $(this).data('url');
        const name = $(this).data('name');
        if (!confirm(_t.confirmSetDefaultModal.replace(':name', name))) return;
        callSetDefault(url);
    });

    function callSetDefault(url) {
        $.ajax({
            method: 'PATCH', url, dataType: 'json',
            headers: { 'X-CSRF-TOKEN': csrf },
            success: res => {
                if (window.showSuccessToast) showSuccessToast(res.message);
                setTimeout(() => location.reload(), 700);
            },
            error: res => {
                const msg = res.responseJSON?.message ?? _t.errorSetDefault;
                if (window.showErrorToast) showErrorToast(msg);
            }
        });
    }

    // ── Toggle active ──────────────────────────────────────────────────────
    $(document).on('change', '.gateway-toggle', function () {
        const url = $(this).data('url');
        const el  = this;
        $.ajax({
            method: 'PATCH', url, dataType: 'json',
            headers: { 'X-CSRF-TOKEN': csrf },
            success: res => {
                if (window.showSuccessToast) showSuccessToast(res.message);
                setTimeout(() => location.reload(), 700);
            },
            error: res => {
                el.checked = !el.checked;
                if (window.showErrorToast) showErrorToast(res.responseJSON?.message ?? _t.errorGeneric);
            }
        });
    });

    // ── Modal Credenciais ─────────────────────────────────────────────────
    $(document).on('click', '.btn-credentials', function () {
        const code = $(this).data('gateway-code');
        const info = gatewaySecretLabels[code] ?? { label: _t.credApiKey, hint: '' };
        $('#cred-gateway-name').text($(this).data('gateway-name'));
        $('#cred-secret-label').html(`${info.label} <span class="text-danger">*</span>`);
        $('#cred-secret-hint').text(info.hint);
        $('#cred-store-url').val($(this).data('store-url'));
        $('#cred-store-form')[0].reset();
        $('#cred-store-error').addClass('d-none');
        $('#credentialsModal').modal('show');
        loadCredentials($(this).data('url'));
    });

    function loadCredentials(url) {
        $('#cred-list-loading').removeClass('d-none');
        $('#cred-list-empty, #cred-list-container').addClass('d-none').html('');

        $.getJSON(url, res => {
            $('#cred-list-loading').addClass('d-none');
            const items = res.data ?? [];
            if (!items.length) { $('#cred-list-empty').removeClass('d-none'); return; }

            const html = items.map(c => `
                <div class="d-flex align-items-center justify-content-between py-2 border-bottom gap-3">
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold small text-truncate">${c.label ?? _t.noLabel}</div>
                        <div class="d-flex flex-wrap gap-1 mt-1">
                            ${c.active
                                ? `<span class="badge badge-soft-success" style="font-size:.7rem;">${_t.credActive}</span>`
                                : `<span class="badge badge-soft-secondary" style="font-size:.7rem;">${_t.credInactive}</span>`}
                            ${c.valid_from
                                ? `<span class="badge badge-soft-info" style="font-size:.7rem;">${c.valid_from} → ${c.valid_to ?? '∞'}</span>`
                                : ''}
                            <span class="text-muted" style="font-size:.75rem;">${c.created_at}</span>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-shrink-0">
                        <span class="text-muted fst-italic" style="font-size:.75rem;">${_t.credHidden}</span>
                        ${c.active
                            ? `<button type="button"
                                       class="btn btn-sm btn-outline-danger py-0 px-2 btn-revoke-cred"
                                       data-revoke-url="${c.revoke_url}"
                                       style="font-size:.75rem;">
                                   ${_t.credRevoke}
                               </button>`
                            : ''}
                    </div>
                </div>`).join('');

            $('#cred-list-container').removeClass('d-none').html(html);
        }).fail(() => {
            $('#cred-list-loading').addClass('d-none');
            $('#cred-list-container').removeClass('d-none')
                .html(`<div class="alert alert-danger small py-2">${_t.errorLoad}</div>`);
        });
    }

    $(document).on('click', '.btn-revoke-cred', function () {
        if (!confirm(_t.confirmRevoke)) return;
        const url = $(this).data('revoke-url');
        const row = $(this).closest('.d-flex');
        $.ajax({
            method: 'PATCH', url, dataType: 'json',
            headers: { 'X-CSRF-TOKEN': csrf },
            success: res => {
                if (window.showSuccessToast) showSuccessToast(res.message);
                row.find('.badge-soft-success').removeClass('badge-soft-success').addClass('badge-soft-secondary').text(_t.credInactive);
                row.find('.btn-revoke-cred').remove();
                setTimeout(() => location.reload(), 700);
            },
            error: res => { if (window.showErrorToast) showErrorToast(res.responseJSON?.message ?? _t.errorGeneric); }
        });
    });

    $('#cred-store-btn').on('click', function () {
        const url = $('#cred-store-url').val();
        const btn = $(this);
        btn.prop('disabled', true);
        $('#cred-store-spinner').removeClass('d-none');
        $('#cred-store-error').addClass('d-none');

        const data = {};
        $('#cred-store-form').serializeArray().forEach(f => { if (f.value) data[f.name] = f.value; });

        $.ajax({
            method: 'POST', url, dataType: 'json',
            headers: { 'X-CSRF-TOKEN': csrf }, data,
            success: res => {
                if (window.showSuccessToast) showSuccessToast(res.message);
                $('#credentialsModal').modal('hide');
                setTimeout(() => location.reload(), 800);
            },
            error: res => {
                const msg = res.responseJSON?.errors
                    ? Object.values(res.responseJSON.errors).flat().join(' ')
                    : (res.responseJSON?.message ?? _t.errorSave);
                $('#cred-store-error').removeClass('d-none').text(msg);
            },
            complete: () => { btn.prop('disabled', false); $('#cred-store-spinner').addClass('d-none'); }
        });
    });

    function togglePassword(inputId, btn) {
        const input  = $(inputId);
        const isPass = input.attr('type') === 'password';
        input.attr('type', isPass ? 'text' : 'password');
        btn.find('i').toggleClass('ti-eye', !isPass).toggleClass('ti-eye-off', isPass);
    }
    $('#cred-toggle-secret').on('click', function () { togglePassword('#cred-secret-input', $(this)); });
    $('#cred-toggle-webhook').on('click', function () { togglePassword('#cred-webhook-input', $(this)); });

    // ── Modal Acesso por Clínica ──────────────────────────────────────────
    $(document).on('click', '.btn-entity-access', function () {
        $('#ea-gateway-name').text($(this).data('gateway-name'));
        $('#ea-search').val('');
        $('#ea-list-container').empty();
        $('#entityAccessModal').modal('show');
        loadEntityAccess($(this).data('url'));
    });

    function loadEntityAccess(url) {
        $('#ea-list-loading').removeClass('d-none');
        $('#ea-list-empty').addClass('d-none');
        $('#ea-list-container').empty();

        $.getJSON(url, res => {
            $('#ea-list-loading').addClass('d-none');
            const items = res.data ?? [];
            if (!items.length) { $('#ea-list-empty').removeClass('d-none'); return; }

            const html = items.map(e => `
                <div class="d-flex align-items-center justify-content-between py-2 border-bottom ea-row"
                     data-name="${e.name.toLowerCase()}" data-code="${e.code.toLowerCase()}">
                    <div>
                        <span class="fw-semibold small">${e.name}</span>
                        <span class="badge badge-soft-secondary ms-2" style="font-size:.7rem;">${e.code}</span>
                    </div>
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input ea-toggle"
                               type="checkbox" role="switch"
                               data-toggle-url="${e.toggle_url}"
                               ${e.enabled ? 'checked' : ''}
                               title="${e.enabled ? _t.eaDisable : _t.eaEnable}">
                    </div>
                </div>`).join('');

            $('#ea-list-container').html(html);
        }).fail(() => {
            $('#ea-list-loading').addClass('d-none');
            $('#ea-list-container').html(`<div class="alert alert-danger small py-2">${_t.errorLoadClinics}</div>`);
        });
    }

    $(document).on('change', '.ea-toggle', function () {
        const url = $(this).data('toggle-url');
        const el  = this;
        $.ajax({
            method: 'PATCH', url, dataType: 'json',
            headers: { 'X-CSRF-TOKEN': csrf },
            success: res => { if (window.showSuccessToast) showSuccessToast(res.message); },
            error: res => {
                el.checked = !el.checked;
                if (window.showErrorToast) showErrorToast(res.responseJSON?.message ?? _t.errorGeneric);
            }
        });
    });

    $('#entityAccessModal').on('hidden.bs.modal', () => location.reload());

    $('#ea-search').on('input', function () {
        const q = $(this).val().toLowerCase().trim();
        $('#ea-list-container .ea-row').each(function () {
            $(this).toggle(!q || $(this).data('name').includes(q) || $(this).data('code').includes(q));
        });
        $('#ea-list-empty').toggleClass('d-none', $('#ea-list-container .ea-row:visible').length > 0);
    });

    // ── Modal Prioridade ──────────────────────────────────────────────────
    $(document).on('click', '.btn-priority', function () {
        $('#priority-url').val($(this).data('url'));
        $('#priority-gateway-name').text($(this).data('gateway-name'));
        $('#priority-input').val($(this).data('priority'));
        $('#priority-error').addClass('d-none');
        $('#priorityModal').modal('show');
    });

    $('#priority-form').on('submit', function (e) {
        e.preventDefault();
        $.ajax({
            method: 'PATCH', url: $('#priority-url').val(), dataType: 'json',
            headers: { 'X-CSRF-TOKEN': csrf },
            data: { priority: $('#priority-input').val() },
            success: res => {
                if (window.showSuccessToast) showSuccessToast(res.message);
                $('#priorityModal').modal('hide');
                setTimeout(() => location.reload(), 700);
            },
            error: res => { $('#priority-error').removeClass('d-none').text(res.responseJSON?.message ?? _t.errorGeneric); }
        });
    });
});
</script>
@endsection
