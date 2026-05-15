@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')

    {{-- Flash message --}}
    @if(session('message'))
        <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
            <i class="ti ti-circle-check me-2"></i>{{ session('message') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    {{-- Filters --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">{{ __('financial.period_from') }}</label>
                    <input type="date" name="from" value="{{ $from->toDateString() }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">{{ __('financial.period_to') }}</label>
                    <input type="date" name="to" value="{{ $to->toDateString() }}" class="form-control form-control-sm">
                </div>
                <div class="col-auto ms-auto d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="ti ti-filter me-1"></i> {{ __('financial.filter') }}
                    </button>
                    <a href="{{ route('panel.financial.tiss.glosas.index') }}" class="btn btn-outline-secondary btn-sm">
                        <i class="ti ti-x me-1"></i> {{ __('financial.clear') }}
                    </a>
                </div>
            </form>
        </div>
    </div>

    {{-- Summary Cards --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-4">
                    <div class="text-muted small mb-1">{{ __('financial.glosas.total_glosa') }}</div>
                    <div class="fs-4 fw-bold text-dark">R$ {{ number_format((float) $totalAmount, 2, ',', '.') }}</div>
                    <div class="text-muted small mt-1">{{ $glosas->count() }} {{ __('financial.glosas.glosa_count') }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100 border-start border-danger border-3">
                <div class="card-body text-center py-4">
                    <div class="text-muted small mb-1">{{ __('financial.glosas.open_amount') }}</div>
                    <div class="fs-4 fw-bold text-danger">R$ {{ number_format((float) $openAmount, 2, ',', '.') }}</div>
                    <div class="text-muted small mt-1">{{ $glosas->where('status', \App\Domains\Tiss\Enums\TissGlosaStatus::Open)->count() }} {{ __('financial.glosas.glosa_count') }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100 border-start border-warning border-3">
                <div class="card-body text-center py-4">
                    <div class="text-muted small mb-1">{{ __('financial.glosas.appealed') }}</div>
                    <div class="fs-4 fw-bold text-warning">R$ {{ number_format((float) $appealedAmount, 2, ',', '.') }}</div>
                    <div class="text-muted small mt-1">{{ $glosas->where('status', \App\Domains\Tiss\Enums\TissGlosaStatus::Appealed)->count() }} {{ __('financial.glosas.glosa_count') }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100 border-start border-success border-3">
                <div class="card-body text-center py-4">
                    <div class="text-muted small mb-1">{{ __('financial.glosas.recovered') }}</div>
                    <div class="fs-4 fw-bold text-success">R$ {{ number_format((float) $recoveredAmount, 2, ',', '.') }}</div>
                    <div class="text-muted small mt-1">{{ __('financial.glosas.accepted_appeals') }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Operator Breakdown --}}
    @if($byOperator->isNotEmpty())
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-transparent fw-semibold">
                <i class="ti ti-building-hospital me-2 text-muted"></i>{{ __('financial.glosas.by_covenant') }}
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>{{ __('financial.glosas.col_covenant') }}</th>
                                <th class="text-end">{{ __('financial.glosas.col_total') }}</th>
                                <th class="text-end">{{ __('financial.glosas.col_open') }}</th>
                                <th class="text-center">{{ __('financial.glosas.col_count') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($byOperator as $op)
                                <tr>
                                    <td class="fw-semibold">{{ $op['name'] }}</td>
                                    <td class="text-end">R$ {{ number_format((float) $op['total'], 2, ',', '.') }}</td>
                                    <td class="text-end">
                                        @if($op['open'] > 0)
                                            <span class="text-danger fw-semibold">R$ {{ number_format((float) $op['open'], 2, ',', '.') }}</span>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-secondary rounded-pill">{{ $op['count'] }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    {{-- Glosa Table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-transparent d-flex align-items-center justify-content-between">
            <span class="fw-semibold"><i class="ti ti-file-invoice me-2 text-muted"></i>{{ __('financial.glosas.period_glosas') }}</span>
            @if($openAmount > 0)
                <span class="badge bg-danger">
                    R$ {{ number_format((float) $openAmount, 2, ',', '.') }} {{ __('financial.glosas.open_badge') }}
                </span>
            @endif
        </div>

        @if($glosas->isEmpty())
            <div class="card-body text-center py-5 text-muted">
                <i class="ti ti-circle-check fs-1 d-block mb-2 text-success"></i>
                {{ __('financial.glosas.empty') }}
            </div>
        @else
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>{{ __('financial.glosas.col_date') }}</th>
                                <th>{{ __('financial.glosas.col_covenant') }}</th>
                                <th>{{ __('financial.glosas.col_guide') }}</th>
                                <th>{{ __('financial.glosas.col_code') }}</th>
                                <th>{{ __('financial.glosas.col_reason') }}</th>
                                <th class="text-end">{{ __('financial.glosas.col_value') }}</th>
                                <th class="text-center">{{ __('financial.glosas.col_status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($glosas as $glosa)
                                <tr>
                                    <td class="text-nowrap small">{{ $glosa->identified_at->format('d/m/Y') }}</td>
                                    <td>{{ $glosa->operator?->trade_name ?? $glosa->operator?->name ?? '—' }}</td>
                                    <td class="small text-muted">{{ $glosa->guide?->guide_number ?? '—' }}</td>
                                    <td>
                                        @if($glosa->glosa_code)
                                            <code class="small">{{ $glosa->glosa_code }}</code>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="small" title="{{ $glosa->glosa_description }}">
                                            {{ \Illuminate\Support\Str::limit($glosa->glosa_description ?? '', 60) }}
                                        </span>
                                    </td>
                                    <td class="text-end fw-semibold {{ $glosa->status->isActionable() ? 'text-danger' : '' }}">
                                        R$ {{ number_format((float) $glosa->amount, 2, ',', '.') }}
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-{{ $glosa->status->color() }}">
                                            {{ $glosa->status->label() }}
                                        </span>
                                    </td>
                                    <td>
                                        @if($glosa->status->isActionable())
                                            <button
                                                type="button"
                                                class="btn btn-danger btn-sm"
                                                data-bs-toggle="modal"
                                                data-bs-target="#appealModal-{{ $glosa->id }}"
                                            >
                                                <i class="ti ti-gavel me-1"></i> {{ __('financial.glosas.appeal_btn') }}
                                            </button>
                                        @elseif($glosa->appeals->isNotEmpty())
                                            <span class="text-muted small">
                                                <i class="ti ti-file-check me-1"></i>
                                                {{ $glosa->appeals->last()->appeal_number }}
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>

    {{-- Appeal Modals (one per open glosa) --}}
    @foreach($glosas->filter(fn ($g) => $g->status->isActionable()) as $glosa)
        <div class="modal fade" id="appealModal-{{ $glosa->id }}" tabindex="-1" aria-labelledby="appealLabel-{{ $glosa->id }}" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow">
                    <div class="modal-header border-0 pb-0">
                        <h5 class="modal-title" id="appealLabel-{{ $glosa->id }}">
                            <i class="ti ti-gavel me-2 text-danger"></i>{{ __('financial.glosas.appeal_title') }}
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST" action="{{ route('panel.financial.tiss.glosas.appeal', $glosa) }}">
                        @csrf
                        <div class="modal-body">
                            <div class="alert alert-danger mb-3 py-2 small">
                                <strong>{{ $glosa->operator?->trade_name ?? $glosa->operator?->name ?? __('financial.glosas.col_covenant') }}</strong>
                                {{ __('financial.glosas.appealed_by') }} <strong>R$ {{ number_format((float) $glosa->amount, 2, ',', '.') }}</strong>
                                em {{ $glosa->identified_at->format('d/m/Y') }}
                                @if($glosa->glosa_code) — {{ __('financial.glosas.col_code') }} <code>{{ $glosa->glosa_code }}</code>@endif
                            </div>
                            @if($glosa->glosa_description)
                                <p class="small text-muted mb-3">
                                    <strong>{{ __('financial.glosas.reason_label') }}</strong> {{ $glosa->glosa_description }}
                                </p>
                            @endif
                            <div class="mb-3">
                                <label class="form-label fw-semibold">{{ __('financial.glosas.justification_label') }} <span class="text-danger">*</span></label>
                                <textarea
                                    name="reason"
                                    class="form-control @error('reason') is-invalid @enderror"
                                    rows="4"
                                    placeholder="{{ __('financial.glosas.justification_placeholder') }}"
                                    required
                                >{{ old('reason') }}</textarea>
                                @error('reason')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <p class="small text-muted mb-0">
                                <i class="ti ti-info-circle me-1"></i>
                                {{ __('financial.glosas.appeal_number_hint') }}
                            </p>
                        </div>
                        <div class="modal-footer border-0 pt-0">
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">{{ __('financial.glosas.cancel_btn') }}</button>
                            <button type="submit" class="btn btn-danger btn-sm">
                                <i class="ti ti-send me-1"></i> {{ __('financial.glosas.submit_appeal') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endforeach

@endsection
