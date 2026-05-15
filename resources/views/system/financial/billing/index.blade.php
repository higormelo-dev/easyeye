@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')
    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">{{ __('financial.period_from') }}</label>
                    <input type="date" name="from" value="{{ $from }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">{{ __('financial.period_to') }}</label>
                    <input type="date" name="to" value="{{ $to }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">{{ __('financial.billing.covenant_label') }}</label>
                    <select name="covenant_id" class="form-select form-select-sm">
                        <option value="">{{ __('financial.billing.all') }}</option>
                        @foreach($covenants as $covenant)
                            <option value="{{ $covenant->id }}" @selected(request('covenant_id') === $covenant->id)>
                                {{ $covenant->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">{{ __('financial.billing.claim_status_label') }}</label>
                    <select name="claim_status" class="form-select form-select-sm">
                        <option value="">{{ __('financial.billing.all') }}</option>
                        <option value="draft"     @selected(request('claim_status') === 'draft')>{{ __('financial.billing.status_draft') }}</option>
                        <option value="submitted" @selected(request('claim_status') === 'submitted')>{{ __('financial.billing.status_submitted') }}</option>
                        <option value="paid"      @selected(request('claim_status') === 'paid')>{{ __('financial.billing.status_paid') }}</option>
                        <option value="denied"    @selected(request('claim_status') === 'denied')>{{ __('financial.billing.status_denied') }}</option>
                        <option value="cancelled" @selected(request('claim_status') === 'cancelled')>{{ __('financial.billing.status_cancelled') }}</option>
                    </select>
                </div>
                <div class="col-auto ms-auto">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="ti ti-filter me-1"></i> {{ __('financial.filter') }}
                    </button>
                    <a href="{{ route('panel.financial.billing.index') }}" class="btn btn-outline-secondary btn-sm ms-1">
                        <i class="ti ti-x me-1"></i> {{ __('financial.clear') }}
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent fw-semibold">
                    {{ __('financial.billing.individual_title') }}
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('panel.financial.billing.individual.store') }}" class="row g-2">
                        @csrf
                        <div class="col-12">
                            <label class="form-label small fw-semibold">{{ __('financial.billing.attended_schedule') }}</label>
                            <select name="schedule_id" class="form-select form-select-sm" required>
                                <option value="">{{ __('financial.billing.select') }}</option>
                                @foreach($eligibleSchedules as $schedule)
                                    <option value="{{ $schedule->id }}">
                                        {{ $schedule->date_time?->format('d/m/Y H:i') }} - {{ $schedule->patient?->person?->full_name ?? $schedule->full_name }} ({{ $schedule->covenant?->name ?? __('financial.billing.no_covenant') }})
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">{{ __('financial.billing.quantity') }}</label>
                            <input type="number" name="quantity" min="1" value="1" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">{{ __('financial.billing.unit_price') }}</label>
                            <input type="number" name="unit_price" min="0" step="0.01" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">{{ __('financial.billing.due_date') }}</label>
                            <input type="date" name="due_date" class="form-control form-control-sm">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">{{ __('financial.billing.initial_status') }}</label>
                            <select name="status" class="form-select form-select-sm">
                                <option value="draft">{{ __('financial.billing.status_draft') }}</option>
                                <option value="submitted">{{ __('financial.billing.status_submitted') }}</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">{{ __('financial.billing.tuss_code') }}</label>
                            <input type="text" name="tuss_code" value="10101012" class="form-control form-control-sm">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">{{ __('financial.billing.authorization') }}</label>
                            <input type="text" name="authorization_code" class="form-control form-control-sm">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">{{ __('financial.billing.procedure_desc') }}</label>
                            <input type="text" name="procedure_description" value="CONSULTA OFTALMOLOGICA" class="form-control form-control-sm">
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary btn-sm w-100">
                                <i class="ti ti-plus me-1"></i> {{ __('financial.billing.create_individual') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <form method="POST" action="{{ route('panel.financial.billing.batch.store') }}">
                @csrf
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-transparent fw-semibold">
                        {{ __('financial.billing.batch_title') }}
                    </div>
                    <div class="card-body">
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">{{ __('financial.billing.covenant_label') }}</label>
                                <select name="covenant_id" class="form-select form-select-sm" required>
                                    <option value="">{{ __('financial.billing.select') }}</option>
                                    @foreach($covenants as $covenant)
                                        <option value="{{ $covenant->id }}">{{ $covenant->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-semibold">{{ __('financial.billing.quantity') }}</label>
                                <input type="number" name="quantity" min="1" value="1" class="form-control form-control-sm" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold">{{ __('financial.billing.unit_price') }}</label>
                                <input type="number" name="unit_price" min="0" step="0.01" class="form-control form-control-sm" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold">{{ __('financial.billing.due_date') }}</label>
                                <input type="date" name="due_date" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold">{{ __('financial.billing.period_from') }}</label>
                                <input type="date" name="date_from" value="{{ $from }}" class="form-control form-control-sm" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold">{{ __('financial.billing.period_to') }}</label>
                                <input type="date" name="date_until" value="{{ $to }}" class="form-control form-control-sm" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold">{{ __('financial.billing.tiss_version') }}</label>
                                <select name="tiss_version" class="form-select form-select-sm">
                                    @foreach($tissVersionOptions as $version)
                                        <option value="{{ $version }}" @selected(old('tiss_version', $selectedTissVersion) === $version)>{{ $version }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold">{{ __('financial.billing.tiss_layout') }}</label>
                                <select name="tiss_layout_version" class="form-select form-select-sm">
                                    @foreach($tissLayoutOptions as $layout)
                                        <option value="{{ $layout }}" @selected(old('tiss_layout_version', $selectedTissLayout) === $layout)>{{ $layout }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">{{ __('financial.billing.tuss_code') }}</label>
                                <input type="text" name="tuss_code" value="10101012" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-8">
                                <label class="form-label small fw-semibold">{{ __('financial.billing.procedure_desc') }}</label>
                                <input type="text" name="procedure_description" value="CONSULTA OFTALMOLOGICA" class="form-control form-control-sm">
                            </div>
                        </div>

                        <hr>

                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="mb-0">{{ __('financial.billing.eligible_count', ['count' => $eligibleSchedules->count()]) }}</h6>
                            <small class="text-muted">{{ __('financial.billing.eligible_hint') }}</small>
                        </div>

                        <div class="table-responsive border rounded">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:32px;">
                                            <input type="checkbox" onclick="document.querySelectorAll('.schedule-check').forEach(el => el.checked = this.checked);">
                                        </th>
                                        <th>{{ __('financial.billing.col_date') }}</th>
                                        <th>{{ __('financial.billing.col_patient') }}</th>
                                        <th>{{ __('financial.billing.col_doctor') }}</th>
                                        <th>{{ __('financial.billing.covenant_label') }}</th>
                                        <th>{{ __('financial.billing.col_schedule_code') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($eligibleSchedules as $schedule)
                                        <tr>
                                            <td>
                                                <input class="schedule-check" type="checkbox" name="schedule_ids[]" value="{{ $schedule->id }}">
                                            </td>
                                            <td>{{ $schedule->date_time?->format('d/m/Y H:i') }}</td>
                                            <td>{{ $schedule->patient?->person?->full_name ?? $schedule->full_name }}</td>
                                            <td>{{ $schedule->doctor?->user_name ?? '—' }}</td>
                                            <td>{{ $schedule->covenant?->name ?? '—' }}</td>
                                            <td><code>{{ $schedule->code }}</code></td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="6" class="text-center text-muted py-3">
                                                {{ __('financial.billing.no_eligible') }}
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer bg-transparent d-flex justify-content-end">
                        <button type="submit" class="btn btn-success btn-sm">
                            <i class="ti ti-stack-2 me-1"></i> {{ __('financial.billing.create_batch') }}
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent fw-semibold">{{ __('financial.billing.claims_title') }}</div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>{{ __('financial.billing.col_guide') }}</th>
                                <th>{{ __('financial.billing.col_date') }}</th>
                                <th>{{ __('financial.billing.col_patient') }}</th>
                                <th>{{ __('financial.billing.covenant_label') }}</th>
                                <th class="text-end">{{ __('financial.billing.col_value') }}</th>
                                <th>{{ __('financial.billing.col_status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($claims as $claim)
                                <tr>
                                    <td><code>{{ $claim->code }}</code></td>
                                    <td>{{ $claim->attendance_date?->format('d/m/Y') }}</td>
                                    <td>{{ $claim->patient?->person?->full_name ?? '—' }}</td>
                                    <td>{{ $claim->covenant?->name ?? '—' }}</td>
                                    <td class="text-end fw-semibold">R$ {{ number_format((float) $claim->amount, 2, ',', '.') }}</td>
                                    <td>
                                        <span class="badge {{ $claim->status->badgeClass() }}">
                                            {{ $claim->status->label() }}
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        @if($claim->status->value !== 'paid')
                                            <form method="POST" action="{{ route('panel.financial.billing.claims.paid', $claim) }}" class="d-inline">
                                                @csrf
                                                <button class="btn btn-outline-success btn-xs" type="submit">{{ __('financial.billing.pay_btn') }}</button>
                                            </form>
                                        @endif
                                        @if(!in_array($claim->status->value, ['paid', 'denied', 'cancelled'], true))
                                            <form method="POST" action="{{ route('panel.financial.billing.claims.denied', $claim) }}" class="d-inline">
                                                @csrf
                                                <button class="btn btn-outline-danger btn-xs" type="submit">{{ __('financial.billing.deny_btn') }}</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">{{ __('financial.billing.no_claims') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent fw-semibold">{{ __('financial.billing.batches_title') }}</div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>{{ __('financial.billing.col_batch') }}</th>
                                <th>{{ __('financial.billing.covenant_label') }}</th>
                                <th>{{ __('financial.billing.col_guides') }}</th>
                                <th class="text-end">{{ __('financial.billing.col_value') }}</th>
                                <th>{{ __('financial.billing.col_status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($batches as $batch)
                                <tr>
                                    <td><code>{{ $batch->code }}</code></td>
                                    <td>{{ $batch->covenant?->name ?? '—' }}</td>
                                    <td>{{ $batch->claims_count }}</td>
                                    <td class="text-end">R$ {{ number_format((float) $batch->total_amount, 2, ',', '.') }}</td>
                                    <td>
                                        <span class="badge {{ $batch->status->badgeClass() }}">
                                            {{ $batch->status->label() }}
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        @if($batch->status->value === 'draft')
                                            <form method="POST" action="{{ route('panel.financial.billing.batches.submit', $batch) }}" class="d-inline">
                                                @csrf
                                                <button class="btn btn-outline-primary btn-xs" type="submit">{{ __('financial.billing.submit_btn') }}</button>
                                            </form>
                                        @endif
                                        <a href="{{ route('panel.financial.billing.batches.xml', $batch) }}"
                                           class="btn btn-outline-secondary btn-xs">
                                            XML
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">{{ __('financial.billing.no_batches') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
