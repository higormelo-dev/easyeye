@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')

@php
    $s         = $reportSetting ?? null;
    $isEdit    = (bool) $s;
    $isManager = request()->routeIs('panel.manager.*');
    $prefix    = $isManager ? 'panel.manager.report-settings' : 'panel.setting.report-settings';
    $action    = $isEdit
        ? route("{$prefix}.update", $s)
        : route("{$prefix}.store");
    $cancelUrl = route("{$prefix}.index");
@endphp

<form method="POST" action="{{ $action }}"
      x-data="{
          showHeader:        {{ $s?->show_header       ?? true  ? 'true' : 'false' }},
          showSignature:     {{ $s?->show_signature    ?? true  ? 'true' : 'false' }},
          showFooter:        {{ $s?->show_footer       ?? true  ? 'true' : 'false' }},
      }">
    @csrf
    @if($isEdit) @method('PUT') @endif

    {{-- ── Título + Ativo ──────────────────────────────────────────────────── --}}
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header fw-semibold bg-transparent">
            <i class="fas fa-file-medical me-2 text-primary"></i>{{ __('actions.report_settings.general') }}
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label class="form-label">{{ __('forms.title') }} <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="form-control @error('title') is-invalid @enderror"
                           value="{{ old('title', $s?->title) }}" maxlength="255" required>
                    @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">{{ __('forms.category') }}</label>
                    <select name="report_category_id" class="form-select @error('report_category_id') is-invalid @enderror">
                        <option value="">{{ __('forms.select') }}...</option>
                        @foreach($categories ?? [] as $cat)
                            <option value="{{ $cat->id }}" {{ old('report_category_id', $s?->report_category_id) == $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>
                        @endforeach
                    </select>
                    @error('report_category_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">{{ __('forms.paper_size') }}</label>
                    <select name="paper_size" class="form-select">
                        @foreach(['A4','A5','Letter','Legal'] as $sz)
                            <option value="{{ $sz }}" {{ old('paper_size', $s?->paper_size ?? 'A4') === $sz ? 'selected' : '' }}>{{ $sz }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">{{ __('forms.active') }}</label>
                    <select name="active" class="form-select">
                        <option value="1" {{ old('active', $s?->active ?? true) ? 'selected' : '' }}>{{ __('forms.yes') }}</option>
                        <option value="0" {{ !old('active', $s?->active ?? true) ? 'selected' : '' }}>{{ __('forms.no') }}</option>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">{{ __('forms.font_family') }}</label>
                    <select name="font_family" class="form-select">
                        @foreach(['Arial','Times New Roman','Helvetica','Courier New','Georgia'] as $f)
                            <option value="{{ $f }}" {{ old('font_family', $s?->font_family ?? 'Arial') === $f ? 'selected' : '' }}>{{ $f }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">{{ __('forms.font_size') }} (pt)</label>
                    <input type="number" name="font_size" class="form-control"
                           value="{{ old('font_size', $s?->font_size ?? 11) }}" min="8" max="24">
                </div>
                <div class="col-12">
                    <label class="form-label">{{ __('forms.description') }}</label>
                    <textarea name="description" class="form-control @error('description') is-invalid @enderror"
                              rows="2" maxlength="1000"
                              placeholder="{{ __('forms.description_placeholder') }}">{{ old('description', $s?->description) }}</textarea>
                    @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            {{-- Margens --}}
            <hr class="my-3">
            <p class="fw-semibold text-muted small mb-2 text-uppercase">{{ __('forms.margins') }} (cm)</p>
            <div class="row g-3">
                @foreach(['top' => __('forms.margin_top'), 'right' => __('forms.margin_right'), 'bottom' => __('forms.margin_bottom'), 'left' => __('forms.margin_left')] as $side => $label)
                <div class="col-6 col-md-3">
                    <label class="form-label">{{ $label }}</label>
                    <input type="number" name="margin_{{ $side }}" step="0.5" class="form-control"
                           value="{{ old('margin_' . $side, $s?->{'margin_' . $side} ?? 2.0) }}" min="0" max="10">
                </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ── Cabeçalho ────────────────────────────────────────────────────────── --}}
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-transparent d-flex align-items-center gap-3">
            <span class="fw-semibold"><i class="fas fa-heading me-2 text-primary"></i>{{ __('actions.report_settings.header') }}</span>
            <div class="form-check form-switch ms-auto mb-0">
                <input class="form-check-input" type="checkbox" name="show_header" value="1" id="show_header"
                       x-model="showHeader" {{ old('show_header', $s?->show_header ?? true) ? 'checked' : '' }}>
                <label class="form-check-label" for="show_header">{{ __('forms.show') }}</label>
            </div>
        </div>
        <div class="card-body" x-show="showHeader" x-collapse>
            <p class="text-muted small mb-3">{{ __('actions.report_settings.header_desc') }}</p>
            <div class="row g-3">
                @php
                    $headerToggles = [
                        'header_show_logo'    => ['icon' => 'image',        'label' => __('forms.clinic_logo')],
                        'header_show_name'    => ['icon' => 'hospital',     'label' => __('forms.clinic_name')],
                        'header_show_address' => ['icon' => 'map-marker-alt','label' => __('forms.address')],
                        'header_show_phone'   => ['icon' => 'phone',        'label' => __('forms.phone')],
                    ];
                @endphp
                @foreach($headerToggles as $field => $cfg)
                <div class="col-6 col-md-3">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="{{ $field }}" value="1"
                               id="{{ $field }}" {{ old($field, $s?->$field ?? ($field === 'header_show_logo' || $field === 'header_show_name')) ? 'checked' : '' }}>
                        <label class="form-check-label" for="{{ $field }}">
                            <i class="fas fa-{{ $cfg['icon'] }} me-1 text-muted"></i>{{ $cfg['label'] }}
                        </label>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ── Assinatura ───────────────────────────────────────────────────────── --}}
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-transparent d-flex align-items-center gap-3">
            <span class="fw-semibold"><i class="fas fa-signature me-2 text-primary"></i>{{ __('actions.report_settings.signature') }}</span>
            <div class="form-check form-switch ms-auto mb-0">
                <input class="form-check-input" type="checkbox" name="show_signature" value="1" id="show_signature"
                       x-model="showSignature" {{ old('show_signature', $s?->show_signature ?? true) ? 'checked' : '' }}>
                <label class="form-check-label" for="show_signature">{{ __('forms.show') }}</label>
            </div>
        </div>
        <div class="card-body" x-show="showSignature" x-collapse>
            <p class="text-muted small mb-3">{{ __('actions.report_settings.signature_desc') }}</p>
            <div class="row g-3">
                @php
                    $sigToggles = [
                        'signature_show_name' => ['icon' => 'user-md',  'label' => __('forms.doctor_name')],
                        'signature_show_crm'  => ['icon' => 'id-card',  'label' => 'CRM'],
                        'signature_show_rqe'  => ['icon' => 'star',     'label' => 'RQE'],
                    ];
                @endphp
                @foreach($sigToggles as $field => $cfg)
                <div class="col-6 col-md-3">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="{{ $field }}" value="1"
                               id="{{ $field }}" {{ old($field, $s?->$field ?? true) ? 'checked' : '' }}>
                        <label class="form-check-label" for="{{ $field }}">
                            <i class="fas fa-{{ $cfg['icon'] }} me-1 text-muted"></i>{{ $cfg['label'] }}
                        </label>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ── Rodapé ────────────────────────────────────────────────────────────── --}}
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-transparent d-flex align-items-center gap-3">
            <span class="fw-semibold"><i class="fas fa-grip-lines me-2 text-primary"></i>{{ __('actions.report_settings.footer') }}</span>
            <div class="form-check form-switch ms-auto mb-0">
                <input class="form-check-input" type="checkbox" name="show_footer" value="1" id="show_footer"
                       x-model="showFooter" {{ old('show_footer', $s?->show_footer ?? true) ? 'checked' : '' }}>
                <label class="form-check-label" for="show_footer">{{ __('forms.show') }}</label>
            </div>
        </div>
        <div class="card-body" x-show="showFooter" x-collapse>
            <p class="text-muted small mb-3">{{ __('actions.report_settings.footer_desc') }}</p>
            <div class="row g-3">
                @php
                    $footerToggles = [
                        'footer_show_address' => ['icon' => 'map-marker-alt', 'label' => __('forms.address')],
                        'footer_show_phone'   => ['icon' => 'phone',          'label' => __('forms.phone')],
                    ];
                @endphp
                @foreach($footerToggles as $field => $cfg)
                <div class="col-6 col-md-3">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="{{ $field }}" value="1"
                               id="{{ $field }}" {{ old($field, $s?->$field ?? false) ? 'checked' : '' }}>
                        <label class="form-check-label" for="{{ $field }}">
                            <i class="fas fa-{{ $cfg['icon'] }} me-1 text-muted"></i>{{ $cfg['label'] }}
                        </label>
                    </div>
                </div>
                @endforeach
                <div class="col-12">
                    <label class="form-label">{{ __('forms.footer_text') }}</label>
                    <input type="text" name="footer_text" class="form-control"
                           value="{{ old('footer_text', $s?->footer_text) }}"
                           placeholder="{{ __('forms.footer_text_ph') }}" maxlength="500">
                    <div class="form-text">{{ __('forms.footer_text_hint') }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Ações ─────────────────────────────────────────────────────────────── --}}
    <div class="d-flex gap-2 justify-content-end">
        <a href="{{ $cancelUrl }}" class="btn btn-secondary">
            {{ __('actions.cancel') }}
        </a>
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-save me-1"></i>{{ __('actions.save') }}
        </button>
    </div>

</form>

@endsection
