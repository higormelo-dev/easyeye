@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')

{{-- ── Subnav ──────────────────────────────────────────────────────────── --}}
<div class="row mb-3 align-items-center">
    <div class="col-12 col-md-auto">
        <div class="btn-group" role="group">
            <a href="{{ route('panel.patients.medicalrecords.index', $patient) }}" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i>{{ __('actions.medical_records.title') }}
            </a>
        </div>
    </div>
</div>

<div class="row g-3">

    {{-- ── Coluna lateral: info do paciente ─────────────────────────────── --}}
    <div class="col-12 col-md-3">
        <div class="patient-info-sticky">
            @include('system.medical_records._patient-info')
        </div>
    </div>

    {{-- ── Coluna principal: formulário ───────────────────────────────────── --}}
    <div class="col-12 col-md-9">
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h5 class="mb-0">
                    <i class="fas fa-file-medical-alt me-2 text-info"></i>
                    {{ __('actions.medical_records.create') }}
                </h5>
                <button type="submit" form="pmr-form" class="btn btn-primary btn-sm">
                    <i class="fas fa-save me-1"></i>{{ __('actions.medical_records.save') }}
                </button>
            </div>
            <div class="card-body p-3">

                <form id="pmr-form"
                      method="POST"
                      action="{{ route('panel.patients.medicalrecords.store', $patient) }}"
                      x-data="{
                          diabetic: false, diabetic_family: false,
                          hypertensive: false, hypertensive_family: false,
                          glaucomatous: false, glaucomatous_family: false
                      }">
                    @csrf

                    {{-- ── Médico e Queixa Principal ────────────────────── --}}
                    <div class="row g-2 mb-3">
                        <div class="col-12 col-md-4">
                            <label class="pmr-label">{{ __('actions.medical_records.doctor') }}</label>
                            <select name="doctor_id" class="form-select form-select-sm @error('doctor_id') is-invalid @enderror">
                                <option value="">{{ __('actions.medical_records.select') }}</option>
                                @foreach($doctors as $doctor)
                                    <option value="{{ $doctor->id }}" {{ old('doctor_id') === $doctor->id ? 'selected' : '' }}>
                                        {{ $doctor->person?->full_name ?? $doctor->code }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12 col-md-8">
                            <label class="pmr-label">{{ __('actions.medical_records.complaint') }}</label>
                            <input type="text" name="main_complaint" value="{{ old('main_complaint') }}"
                                   class="form-control form-control-sm @error('main_complaint') is-invalid @enderror">
                        </div>
                    </div>

                    <div class="row g-3">

                        {{-- ── Coluna esquerda do formulário ────────────── --}}
                        <div class="col-12 col-lg-7">

                            {{-- Vis. cromática / PPC / Cover test --}}
                            <div class="row g-2 mb-3">
                                <div class="col-4">
                                    <label class="pmr-label">{{ __('actions.medical_records.chromatic_vision') }}</label>
                                    <select name="visual_acuity_type_id" class="form-select form-select-sm">
                                        <option value="">—</option>
                                        @foreach($colorVisionTypes as $item)
                                            <option value="{{ $item->id }}" {{ old('visual_acuity_type_id') === $item->id ? 'selected' : '' }}>{{ $item->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-4">
                                    <label class="pmr-label">{{ __('actions.medical_records.near_point') }}</label>
                                    <select name="near_point_convergence_id" class="form-select form-select-sm">
                                        <option value="">—</option>
                                        @foreach($nearPointTypes as $item)
                                            <option value="{{ $item->id }}" {{ old('near_point_convergence_id') === $item->id ? 'selected' : '' }}>{{ $item->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-4">
                                    <label class="pmr-label">{{ __('actions.medical_records.cover_test') }}</label>
                                    <select name="cover_test_type_id" class="form-select form-select-sm">
                                        <option value="">—</option>
                                        @foreach($coverTestTypes as $item)
                                            <option value="{{ $item->id }}" {{ old('cover_test_type_id') === $item->id ? 'selected' : '' }}>{{ $item->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            {{-- A/V sem correção + Tonometria --}}
                            <div class="row g-2 mb-3">
                                <div class="col-7">
                                    <label class="pmr-label">{{ __('actions.medical_records.av_without') }}</label>
                                    <div class="d-flex align-items-center gap-1">
                                        <span class="pmr-eye">OD</span>
                                        <select name="visual_acuity_without_correction_right_id" class="form-select form-select-sm">
                                            <option value="">—</option>
                                            @foreach($visualAcuityTypes as $item)
                                                <option value="{{ $item->id }}" {{ old('visual_acuity_without_correction_right_id') === $item->id ? 'selected' : '' }}>{{ $item->name }}</option>
                                            @endforeach
                                        </select>
                                        <span class="pmr-eye">OE</span>
                                        <select name="visual_acuity_without_correction_left_id" class="form-select form-select-sm">
                                            <option value="">—</option>
                                            @foreach($visualAcuityTypes as $item)
                                                <option value="{{ $item->id }}" {{ old('visual_acuity_without_correction_left_id') === $item->id ? 'selected' : '' }}>{{ $item->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                <div class="col-5">
                                    <label class="pmr-label">{{ __('actions.medical_records.tonometry') }}</label>
                                    <div class="d-flex align-items-center gap-1">
                                        <span class="pmr-eye">OD</span>
                                        <input type="number" name="tonometer_right" step="0.5" min="0"
                                               class="form-control form-control-sm text-center" style="width:52px;"
                                               value="{{ old('tonometer_right') }}" placeholder="00">
                                        <span class="pmr-eye">OE</span>
                                        <input type="number" name="tonometer_left" step="0.5" min="0"
                                               class="form-control form-control-sm text-center" style="width:52px;"
                                               value="{{ old('tonometer_left') }}" placeholder="00">
                                        <input type="time" name="tonometer_time"
                                               class="form-control form-control-sm" style="width:82px;"
                                               value="{{ old('tonometer_time') }}"
                                               title="{{ __('actions.medical_records.tonometry_h') }}">
                                    </div>
                                </div>
                            </div>

                            {{-- Dinâmica --}}
                            <div class="mb-3">
                                <label class="pmr-label">{{ __('actions.medical_records.dynamic') }}</label>
                                <table class="pmr-table">
                                    <thead>
                                        <tr>
                                            <th style="width:36px;"></th>
                                            <th>{{ __('actions.medical_records.spherical') }}</th>
                                            <th>{{ __('actions.medical_records.cylindrical') }}</th>
                                            <th>{{ __('actions.medical_records.axis') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td class="pmr-od">OD</td>
                                            <td><input type="number" name="dynamic_spherical_right" step="0.25" value="{{ old('dynamic_spherical_right', '0.00') }}" placeholder="0.00"></td>
                                            <td><input type="number" name="dynamic_cylindrical_right" step="0.25" value="{{ old('dynamic_cylindrical_right', '0.00') }}" placeholder="0.00"></td>
                                            <td><input type="number" name="dynamic_axis_right" min="0" max="180" value="{{ old('dynamic_axis_right') }}" placeholder="0°"></td>
                                        </tr>
                                        <tr>
                                            <td class="pmr-od">OE</td>
                                            <td><input type="number" name="dynamic_spherical_left" step="0.25" value="{{ old('dynamic_spherical_left', '0.00') }}" placeholder="0.00"></td>
                                            <td><input type="number" name="dynamic_cylindrical_left" step="0.25" value="{{ old('dynamic_cylindrical_left', '0.00') }}" placeholder="0.00"></td>
                                            <td><input type="number" name="dynamic_axis_left" min="0" max="180" value="{{ old('dynamic_axis_left') }}" placeholder="0°"></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            {{-- Estática --}}
                            <div class="mb-0">
                                <label class="pmr-label">{{ __('actions.medical_records.static') }}</label>
                                <table class="pmr-table">
                                    <thead>
                                        <tr>
                                            <th style="width:36px;"></th>
                                            <th>{{ __('actions.medical_records.spherical') }}</th>
                                            <th>{{ __('actions.medical_records.cylindrical') }}</th>
                                            <th>{{ __('actions.medical_records.axis') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td class="pmr-od">OD</td>
                                            <td><input type="number" name="static_spherical_right" step="0.25" value="{{ old('static_spherical_right', '0.00') }}" placeholder="0.00"></td>
                                            <td><input type="number" name="static_cylindrical_right" step="0.25" value="{{ old('static_cylindrical_right', '0.00') }}" placeholder="0.00"></td>
                                            <td><input type="number" name="static_axis_right" min="0" max="180" value="{{ old('static_axis_right') }}" placeholder="0°"></td>
                                        </tr>
                                        <tr>
                                            <td class="pmr-od">OE</td>
                                            <td><input type="number" name="static_spherical_left" step="0.25" value="{{ old('static_spherical_left', '0.00') }}" placeholder="0.00"></td>
                                            <td><input type="number" name="static_cylindrical_left" step="0.25" value="{{ old('static_cylindrical_left', '0.00') }}" placeholder="0.00"></td>
                                            <td><input type="number" name="static_axis_left" min="0" max="180" value="{{ old('static_axis_left') }}" placeholder="0°"></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                        </div>{{-- /col-lg-7 --}}

                        {{-- ── Coluna direita do formulário ─────────────── --}}
                        <div class="col-12 col-lg-5 border-start">

                            {{-- Diabético / Hipertenso / Glaucomatoso --}}
                            <div class="d-flex gap-4 mb-3">

                                <div>
                                    <label class="pmr-label" style="font-style:normal;">{{ __('actions.medical_records.diabetic') }}</label>
                                    <div class="d-flex gap-1">
                                        <div class="form-check form-switch mb-0" title="{{ __('actions.medical_records.diabetic') }}">
                                            <input class="form-check-input" type="checkbox" role="switch" name="diabetic" value="1" id="diabetic" x-model="diabetic">
                                        </div>
                                        <div class="form-check form-switch mb-0" title="{{ __('actions.medical_records.diabetic_family') }}">
                                            <input class="form-check-input" type="checkbox" role="switch" name="diabetic_family" value="1" id="diabetic_family" x-model="diabetic_family">
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <label class="pmr-label" style="font-style:normal;">{{ __('actions.medical_records.hypertensive') }}</label>
                                    <div class="d-flex gap-1">
                                        <div class="form-check form-switch mb-0" title="{{ __('actions.medical_records.hypertensive') }}">
                                            <input class="form-check-input" type="checkbox" role="switch" name="hypertensive" value="1" id="hypertensive" x-model="hypertensive">
                                        </div>
                                        <div class="form-check form-switch mb-0" title="{{ __('actions.medical_records.hypertensive_family') }}">
                                            <input class="form-check-input" type="checkbox" role="switch" name="hypertensive_family" value="1" id="hypertensive_family" x-model="hypertensive_family">
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <label class="pmr-label" style="font-style:normal;">{{ __('actions.medical_records.glaucomatous') }}</label>
                                    <div class="d-flex gap-1">
                                        <div class="form-check form-switch mb-0" title="{{ __('actions.medical_records.glaucomatous') }}">
                                            <input class="form-check-input" type="checkbox" role="switch" name="glaucomatous" value="1" id="glaucomatous" x-model="glaucomatous">
                                        </div>
                                        <div class="form-check form-switch mb-0" title="{{ __('actions.medical_records.glaucomatous_family') }}">
                                            <input class="form-check-input" type="checkbox" role="switch" name="glaucomatous_family" value="1" id="glaucomatous_family" x-model="glaucomatous_family">
                                        </div>
                                    </div>
                                </div>

                            </div>

                            {{-- Adição / Longe / Perto --}}
                            <div class="row g-2 mb-3">
                                <div class="col-4">
                                    <label class="pmr-label">{{ __('actions.medical_records.addition') }}</label>
                                    <select name="addition_type_id" class="form-select form-select-sm">
                                        <option value="">—</option>
                                        @foreach($additionTypes as $item)
                                            <option value="{{ $item->id }}" {{ old('addition_type_id') === $item->id ? 'selected' : '' }}>{{ $item->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-4">
                                    <label class="pmr-label">{{ __('actions.medical_records.lens_away') }}</label>
                                    <select name="lens_away_id" class="form-select form-select-sm">
                                        <option value="">—</option>
                                        @foreach($lenses as $item)
                                            <option value="{{ $item->id }}" {{ old('lens_away_id') === $item->id ? 'selected' : '' }}>{{ $item->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-4">
                                    <label class="pmr-label">{{ __('actions.medical_records.lens_near') }}</label>
                                    <select name="lens_near_id" class="form-select form-select-sm">
                                        <option value="">—</option>
                                        @foreach($lenses as $item)
                                            <option value="{{ $item->id }}" {{ old('lens_near_id') === $item->id ? 'selected' : '' }}>{{ $item->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            {{-- A/V com correção --}}
                            <div class="mb-3">
                                <label class="pmr-label">{{ __('actions.medical_records.av_with') }}</label>
                                <div class="d-flex align-items-center gap-1">
                                    <span class="pmr-eye">OD</span>
                                    <select name="visual_acuity_with_correction_right_id" class="form-select form-select-sm">
                                        <option value="">—</option>
                                        @foreach($visualAcuityTypes as $item)
                                            <option value="{{ $item->id }}" {{ old('visual_acuity_with_correction_right_id') === $item->id ? 'selected' : '' }}>{{ $item->name }}</option>
                                        @endforeach
                                    </select>
                                    <span class="pmr-eye">OE</span>
                                    <select name="visual_acuity_with_correction_left_id" class="form-select form-select-sm">
                                        <option value="">—</option>
                                        @foreach($visualAcuityTypes as $item)
                                            <option value="{{ $item->id }}" {{ old('visual_acuity_with_correction_left_id') === $item->id ? 'selected' : '' }}>{{ $item->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            {{-- Biomicroscopia --}}
                            <div class="mb-3">
                                <label class="pmr-label">{{ __('actions.medical_records.biomicroscopy') }}</label>
                                <div class="d-flex align-items-center gap-1 mb-1">
                                    <span class="pmr-eye">OD</span>
                                    <input type="text" name="biomicroscopy_right" value="{{ old('biomicroscopy_right') }}"
                                           class="form-control form-control-sm @error('biomicroscopy_right') is-invalid @enderror" placeholder="—">
                                </div>
                                <div class="d-flex align-items-center gap-1">
                                    <span class="pmr-eye">OE</span>
                                    <input type="text" name="biomicroscopy_left" value="{{ old('biomicroscopy_left') }}"
                                           class="form-control form-control-sm @error('biomicroscopy_left') is-invalid @enderror" placeholder="—">
                                </div>
                            </div>

                            {{-- Fundoscopia --}}
                            <div class="mb-3">
                                <label class="pmr-label">{{ __('actions.medical_records.fundoscopy') }}</label>
                                <div class="d-flex align-items-center gap-1 mb-1">
                                    <span class="pmr-eye">OD</span>
                                    <input type="text" name="fundoscopy_right" value="{{ old('fundoscopy_right') }}"
                                           class="form-control form-control-sm @error('fundoscopy_right') is-invalid @enderror" placeholder="—">
                                </div>
                                <div class="d-flex align-items-center gap-1">
                                    <span class="pmr-eye">OE</span>
                                    <input type="text" name="fundoscopy_left" value="{{ old('fundoscopy_left') }}"
                                           class="form-control form-control-sm @error('fundoscopy_left') is-invalid @enderror" placeholder="—">
                                </div>
                            </div>

                            {{-- Observações --}}
                            <div class="mb-2">
                                <label class="pmr-label">{{ __('actions.medical_records.general_obs') }}</label>
                                <textarea name="observation_general" rows="2"
                                          class="form-control form-control-sm @error('observation_general') is-invalid @enderror"
                                          placeholder="—">{{ old('observation_general') }}</textarea>
                            </div>
                            <div class="mb-0">
                                <label class="pmr-label">{{ __('actions.medical_records.lenses_obs') }}</label>
                                <textarea name="observation_of_lenses" rows="2"
                                          class="form-control form-control-sm @error('observation_of_lenses') is-invalid @enderror"
                                          placeholder="—">{{ old('observation_of_lenses') }}</textarea>
                            </div>

                        </div>{{-- /col-lg-5 --}}

                    </div>{{-- /row --}}

                    {{-- Botão salvar (fundo) --}}
                    <div class="text-end mt-3 pt-3 border-top">
                        <button type="submit" class="btn btn-info">
                            <i class="fas fa-save me-1"></i>{{ __('actions.medical_records.save') }}
                        </button>
                    </div>

                </form>

            </div>
        </div>
    </div>

</div>

@endsection

@section('javascript')
<style>
.pmr-label {
    color: #26a69a;
    font-size: .78rem;
    font-style: italic;
    font-weight: 500;
    display: block;
    margin-bottom: 3px;
}
.pmr-eye {
    font-size: .78rem;
    font-weight: 600;
    color: #6c757d;
    min-width: 22px;
    flex-shrink: 0;
}
.pmr-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .85rem;
}
.pmr-table th {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    padding: 4px 8px;
    text-align: center;
    font-weight: 500;
    font-size: .78rem;
}
.pmr-table td {
    border: 1px solid #dee2e6;
    padding: 0;
}
.pmr-table td.pmr-od {
    text-align: center;
    font-weight: 600;
    color: #6c757d;
    font-size: .78rem;
    padding: 4px;
    width: 36px;
}
.pmr-table td input {
    width: 100%;
    border: none;
    outline: none;
    text-align: center;
    padding: 5px 4px;
    font-size: .85rem;
    background: transparent;
}
.pmr-table td input:focus {
    background: #f0f9f8;
}
</style>
@endsection
