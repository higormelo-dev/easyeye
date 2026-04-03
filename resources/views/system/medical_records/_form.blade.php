{{--
    Prontuário Médico — Layout duas colunas (inspirado smart_oftal)
    Variáveis esperadas: $patient, $doctors, $visualAcuityTypes,
    $colorVisionTypes, $coverTestTypes, $nearPointTypes, $additionTypes,
    $lenses, $documentationTypes, $availableTemplates,
    $medicalrecord (null em create)
--}}
@php
    $r      = $medicalrecord ?? null;
    $old    = fn (string $field, $default = '') => old($field, $r?->$field ?? $default);
    $oldId  = fn (string $field) => old($field, $r?->$field ?? '');
    $isEdit = (bool) $r;
@endphp

<form id="pmr-form"
      method="POST"
      action="{{ $isEdit
          ? route('panel.patients.medicalrecords.update', [$patient, $r])
          : route('panel.patients.medicalrecords.store', $patient) }}"
      enctype="multipart/form-data"
      x-data="medicalRecordForm({
          isEdit: {{ $isEdit ? 'true' : 'false' }},
          diabetic: {{ $old('diabetic') ? 'true' : 'false' }},
          diabeticFamily: {{ $old('diabetic_family') ? 'true' : 'false' }},
          hypertensive: {{ $old('hypertensive') ? 'true' : 'false' }},
          hypertensiveFamily: {{ $old('hypertensive_family') ? 'true' : 'false' }},
          glaucomatous: {{ $old('glaucomatous') ? 'true' : 'false' }},
          glaucomatousFamily: {{ $old('glaucomatous_family') ? 'true' : 'false' }},
          showOthersHistory: {{ $old('others_history') ? 'true' : 'false' }},
          calcPresbyopiaUrl: @js(route('panel.patients.medicalrecords.calculate-presbyopia', $patient)),
          dynamicSphericalRight: {{ $old('dynamic_spherical_right', 0) ?: 0 }},
          dynamicSphericalLeft: {{ $old('dynamic_spherical_left', 0) ?: 0 }},
          tonometryPdfUrl: @js(route('panel.patients.tonometry-pdf', $patient)),
          doctorId: @js($r?->doctor_id ?? $currentDoctor?->id ?? ''),
          savedTonometryOd: @js($r?->tonometer_right),
          savedTonometryOe: @js($r?->tonometer_left),
          savedTonometryTime: @js($r?->tonometer_time),
          @if($isEdit)
          templatesUrl: @js(route('panel.patients.medicalrecords.templates', [$patient, $r])),
          templatePreviewUrl: @js(route('panel.patients.medicalrecords.template-preview', [$patient, $r])),
          storeDocUrl: @js(route('panel.patients.medicalrecords.documentations.store', [$patient, $r])),
          storeFileUrl: @js(route('panel.patients.medicalrecords.files.store', [$patient, $r])),
          storeTonometryUrl: @js(route('panel.patients.medicalrecords.tonometry.store', [$patient, $r])),
          quickActionUrlTemplate: @js(route('panel.patients.medicalrecords.quick-actions.issue', [$patient, $r, '__ACTION__'])),
          @else
          storeTonometryUrl: '',
          @endif
      })">
    @csrf
    @if($isEdit) @method('PUT') @endif

    {{-- ═══════════════════════════════════════════════════════════════════
         MÉDICO (só quando necessário) + QUEIXA + FLAGS (linha topo)
         ═══════════════════════════════════════════════════════════════════ --}}

    {{-- Médico logado no create: apenas campo oculto --}}
    @if($currentDoctor && !$isEdit)
        <input type="hidden" name="doctor_id" value="{{ $currentDoctor->id }}">
    @else
    {{-- Sem médico logado OU no edit: select compacto em linha própria --}}
    <div class="pmr-section px-3 pt-2">
        <div class="row g-2">
            <div class="col-12 col-md-4 col-lg-3">
                <label class="pmr-label">{{ __('actions.medical_records.doctor') }}</label>
                <select name="doctor_id" class="form-select form-select-sm @error('doctor_id') is-invalid @enderror">
                    <option value="">{{ __('actions.medical_records.select') }}</option>
                    @foreach($doctors as $doctor)
                        <option value="{{ $doctor->id }}"
                            {{ $oldId('doctor_id') == $doctor->id ? 'selected'
                               : ($currentDoctor && $currentDoctor->id == $doctor->id && !old('doctor_id') ? 'selected' : '') }}>
                            {{ $doctor->person?->full_name ?? $doctor->code }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>
    @endif

    {{-- Queixa principal (col-8) + Flags (col-4) na mesma linha --}}
    <div class="pmr-section px-3 pt-2 pb-0">
        {{-- Queixa principal + Switches --}}
        <div class="row g-2 align-items-start">
            <div class="col-12 col-lg-8">
                <label class="pmr-label">{{ __('actions.medical_records.complaint') }}</label>
                <input type="text" name="main_complaint" value="{{ $old('main_complaint') }}"
                       class="form-control form-control-sm @error('main_complaint') is-invalid @enderror"
                       placeholder="{{ __('actions.medical_records.complaint_ph') }}">
            </div>
            {{-- Switches: Diabético / Hipertenso / Glaucomatoso --}}
            <div class="col-12 col-lg-4">
                <div class="row g-1">
                    @foreach(['diabetic', 'hypertensive'] as $flag)
                    <div class="col-4">
                        <label class="pmr-label text-center d-block" style="font-size:.7rem;">{{ __('actions.medical_records.' . $flag) }}</label>
                        <div class="d-flex flex-column align-items-center gap-1">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch"
                                       name="{{ $flag }}" value="1"
                                       x-model="{{ \Illuminate\Support\Str::camel($flag) }}">
                                <label class="form-check-label pmr-toggle-label">{{ __('actions.medical_records.self') }}</label>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch"
                                       name="{{ $flag }}_family" value="1"
                                       x-model="{{ \Illuminate\Support\Str::camel($flag) }}Family">
                                <label class="form-check-label pmr-toggle-label">{{ __('actions.medical_records.family') }}</label>
                            </div>
                        </div>
                    </div>
                    @endforeach

                    {{-- Glaucomatoso — com campo "Outros" expansível --}}
                    <div class="col-4">
                        <label class="pmr-label text-center d-block" style="font-size:.7rem;">{{ __('actions.medical_records.glaucomatous') }}</label>
                        <div class="d-flex flex-column align-items-center gap-1">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch"
                                       name="glaucomatous" value="1"
                                       x-model="glaucomatous">
                                <label class="form-check-label pmr-toggle-label">{{ __('actions.medical_records.self') }}</label>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch"
                                       name="glaucomatous_family" value="1"
                                       x-model="glaucomatousFamily">
                                <label class="form-check-label pmr-toggle-label">{{ __('actions.medical_records.family') }}</label>
                            </div>
                            {{-- Botão "Outros" --}}
                            <button type="button"
                                    class="btn btn-link p-0 pmr-toggle-label text-decoration-none"
                                    style="font-size:.7rem;color:var(--bs-secondary);"
                                    @click="showOthersHistory = !showOthersHistory">
                                <i class="fas fa-plus-circle fa-xs me-1" x-show="!showOthersHistory"></i>
                                <i class="fas fa-minus-circle fa-xs me-1" x-show="showOthersHistory" x-cloak></i>
                                {{ __('actions.medical_records.others') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Campo "Outros" antecedentes (expansível) --}}
        <div class="row g-2 mt-1" x-show="showOthersHistory" x-cloak>
            <div class="col-12">
                <input type="text" name="others_history"
                       value="{{ $old('others_history') }}"
                       class="form-control form-control-sm"
                       placeholder="{{ __('actions.medical_records.others_history_ph') }}"
                       x-ref="othersHistoryInput"
                       x-effect="if (showOthersHistory) $nextTick(() => $refs.othersHistoryInput.focus())">
            </div>
        </div>

        {{-- HDA / Histórico cirúrgico ocular / Medicamentos em uso --}}
        <div class="row g-2 mt-2">
            <div class="col-12">
                <label class="pmr-label">{{ __('actions.medical_records.hda') }}</label>
                <textarea name="hda" rows="2" class="form-control form-control-sm"
                          placeholder="{{ __('actions.medical_records.hda_ph') }}">{{ $old('hda') }}</textarea>
            </div>
            <div class="col-12 col-md-6">
                <label class="pmr-label">{{ __('actions.medical_records.ocular_surgical_history') }}</label>
                <textarea name="ocular_surgical_history" rows="2"
                          class="form-control form-control-sm">{{ $old('ocular_surgical_history') }}</textarea>
            </div>
            <div class="col-12 col-md-6">
                <label class="pmr-label">{{ __('actions.medical_records.medications_in_use') }}</label>
                <textarea name="medications_in_use" rows="2"
                          class="form-control form-control-sm">{{ $old('medications_in_use') }}</textarea>
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════════
         DUAS COLUNAS PRINCIPAIS
         ═══════════════════════════════════════════════════════════════════ --}}
    <div class="row g-0 px-3 pt-1 pb-1">

        {{-- ── COLUNA ESQUERDA ─────────────────────────────────────────── --}}
        <div class="col-12 col-lg-6 pe-lg-2">

            {{-- Vis. cromática / PPC / Cover test --}}
            <div class="pmr-section mb-1">
                <div class="row g-2">
                    <div class="col-4">
                        <label class="pmr-label">{{ __('actions.medical_records.chromatic_vision') }}</label>
                        <select name="color_vision_type_id" class="form-select form-select-sm">
                            <option value="">—</option>
                            @foreach($colorVisionTypes as $item)
                                <option value="{{ $item->id }}" {{ $oldId('color_vision_type_id') == $item->id ? 'selected' : '' }}>{{ $item->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-4">
                        <label class="pmr-label">{{ __('actions.medical_records.near_point') }}</label>
                        <select name="near_point_convergence_id" class="form-select form-select-sm">
                            <option value="">—</option>
                            @foreach($nearPointTypes as $item)
                                <option value="{{ $item->id }}" {{ $oldId('near_point_convergence_id') == $item->id ? 'selected' : '' }}>{{ $item->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-4">
                        <label class="pmr-label">{{ __('actions.medical_records.cover_test') }}</label>
                        <select name="cover_test_type_id" class="form-select form-select-sm">
                            <option value="">—</option>
                            @foreach($coverTestTypes as $item)
                                <option value="{{ $item->id }}" {{ $oldId('cover_test_type_id') == $item->id ? 'selected' : '' }}>{{ $item->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            {{-- A/V sem correção + Tonometria --}}
            <div class="pmr-section mb-1">
                <div class="row g-2">
                    {{-- AV sem correção --}}
                    <div class="col-6">
                        <label class="pmr-label">{{ __('actions.medical_records.av_without') }}</label>
                        <div class="d-flex gap-1">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text pmr-eye-badge">OD</span>
                                <select name="visual_acuity_without_correction_right_id" class="form-select form-select-sm">
                                    <option value="">—</option>
                                    @foreach($visualAcuityTypes as $item)
                                        <option value="{{ $item->id }}" {{ $oldId('visual_acuity_without_correction_right_id') == $item->id ? 'selected' : '' }}>{{ $item->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text pmr-eye-badge">OE</span>
                                <select name="visual_acuity_without_correction_left_id" class="form-select form-select-sm">
                                    <option value="">—</option>
                                    @foreach($visualAcuityTypes as $item)
                                        <option value="{{ $item->id }}" {{ $oldId('visual_acuity_without_correction_left_id') == $item->id ? 'selected' : '' }}>{{ $item->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                    {{-- Tonometria --}}
                    <div class="col-6">
                        <label class="pmr-label">{{ __('actions.medical_records.tonometry') }}</label>
                        <div class="d-flex gap-1 align-items-center">
                            <div class="input-group input-group-sm" style="max-width:90px;">
                                <span class="input-group-text pmr-eye-badge">OD</span>
                                <input type="number" name="tonometer_right" step="0.5" min="0"
                                       class="form-control form-control-sm text-center"
                                       value="{{ $old('tonometer_right') }}" placeholder="00">
                            </div>
                            <div class="input-group input-group-sm" style="max-width:90px;">
                                <span class="input-group-text pmr-eye-badge">OE</span>
                                <input type="number" name="tonometer_left" step="0.5" min="0"
                                       class="form-control form-control-sm text-center"
                                       value="{{ $old('tonometer_left') }}" placeholder="00">
                            </div>
                            {{-- Hora capturada automaticamente no momento da impressão --}}
                            <input type="hidden" name="tonometer_time" id="tonometer-time-hidden"
                                   value="{{ $old('tonometer_time') }}">
                            <button type="button" class="btn btn-pink btn-sm flex-shrink-0"
                                    title="{{ __('actions.medical_records.print_tonometry') }}"
                                    @click="printTonometry()">
                                <i class="fas fa-print"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Dinâmica --}}
            <div class="pmr-section mb-1">
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
                            <td><input type="number" name="dynamic_spherical_right" step="0.25"
                                       x-model.number="dynamicSphericalRight"
                                       value="{{ $old('dynamic_spherical_right', '0.00') }}" placeholder="0.00"></td>
                            <td><input type="number" name="dynamic_cylindrical_right" step="0.25" value="{{ $old('dynamic_cylindrical_right', '0.00') }}" placeholder="0.00"></td>
                            <td><input type="number" name="dynamic_axis_right" min="0" max="180" value="{{ $old('dynamic_axis_right') }}" placeholder="0°"></td>
                        </tr>
                        <tr>
                            <td class="pmr-od">OE</td>
                            <td><input type="number" name="dynamic_spherical_left" step="0.25"
                                       x-model.number="dynamicSphericalLeft"
                                       value="{{ $old('dynamic_spherical_left', '0.00') }}" placeholder="0.00"></td>
                            <td><input type="number" name="dynamic_cylindrical_left" step="0.25" value="{{ $old('dynamic_cylindrical_left', '0.00') }}" placeholder="0.00"></td>
                            <td><input type="number" name="dynamic_axis_left" min="0" max="180" value="{{ $old('dynamic_axis_left') }}" placeholder="0°"></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            {{-- Estática --}}
            <div class="pmr-section mb-1">
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
                            <td><input type="number" name="static_spherical_right" step="0.25"
                                       x-model.number="staticSphericalRight"
                                       value="{{ $old('static_spherical_right', '0.00') }}" placeholder="0.00"></td>
                            <td><input type="number" name="static_cylindrical_right" step="0.25" value="{{ $old('static_cylindrical_right', '0.00') }}" placeholder="0.00"></td>
                            <td><input type="number" name="static_axis_right" min="0" max="180" value="{{ $old('static_axis_right') }}" placeholder="0°"></td>
                        </tr>
                        <tr>
                            <td class="pmr-od">OE</td>
                            <td><input type="number" name="static_spherical_left" step="0.25"
                                       x-model.number="staticSphericalLeft"
                                       value="{{ $old('static_spherical_left', '0.00') }}" placeholder="0.00"></td>
                            <td><input type="number" name="static_cylindrical_left" step="0.25" value="{{ $old('static_cylindrical_left', '0.00') }}" placeholder="0.00"></td>
                            <td><input type="number" name="static_axis_left" min="0" max="180" value="{{ $old('static_axis_left') }}" placeholder="0°"></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            {{-- Paquimetria / Gonioscopia --}}
            <div class="pmr-section mb-1">
                <div class="row g-2">
                    <div class="col-6">
                        <label class="pmr-label">{{ __('actions.medical_records.pachymetry') }}</label>
                        <div class="d-flex gap-1">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text pmr-eye-badge">OD</span>
                                <input type="number" name="pachymetry_right" step="1" min="0"
                                       class="form-control form-control-sm text-center"
                                       value="{{ $old('pachymetry_right') }}" placeholder="μm">
                            </div>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text pmr-eye-badge">OE</span>
                                <input type="number" name="pachymetry_left" step="1" min="0"
                                       class="form-control form-control-sm text-center"
                                       value="{{ $old('pachymetry_left') }}" placeholder="μm">
                            </div>
                        </div>
                    </div>
                    <div class="col-6">
                        <label class="pmr-label">{{ __('actions.medical_records.gonioscopy') }}</label>
                        <div class="d-flex gap-1">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text pmr-eye-badge">OD</span>
                                <input type="text" name="gonioscopy_right" value="{{ $old('gonioscopy_right') }}"
                                       class="form-control form-control-sm">
                            </div>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text pmr-eye-badge">OE</span>
                                <input type="text" name="gonioscopy_left" value="{{ $old('gonioscopy_left') }}"
                                       class="form-control form-control-sm">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>{{-- /col esquerda --}}

        {{-- ── COLUNA DIREITA ──────────────────────────────────────────── --}}
        <div class="col-12 col-lg-6 ps-lg-2">

            {{-- Adição / Longe / Perto + Calc presbiopia --}}
            <div class="pmr-section mb-1">
                <div class="row g-2 align-items-end">
                    <div class="col-3">
                        <label class="pmr-label">{{ __('actions.medical_records.addition') }}</label>
                        <select name="addition_type_id" class="form-select form-select-sm">
                            <option value="">{{ __('actions.medical_records.select') }}</option>
                            @foreach($additionTypes as $item)
                                <option value="{{ $item->id }}" {{ $oldId('addition_type_id') == $item->id ? 'selected' : '' }}>{{ $item->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-3">
                        <label class="pmr-label">{{ __('actions.medical_records.lens_away') }}</label>
                        <select name="lens_away_id" class="form-select form-select-sm">
                            <option value="">—</option>
                            @foreach($lenses as $item)
                                <option value="{{ $item->id }}" {{ $oldId('lens_away_id') == $item->id ? 'selected' : '' }}>{{ $item->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-3">
                        <label class="pmr-label">{{ __('actions.medical_records.lens_near') }}</label>
                        <select name="lens_near_id" class="form-select form-select-sm">
                            <option value="">—</option>
                            @foreach($lenses as $item)
                                <option value="{{ $item->id }}" {{ $oldId('lens_near_id') == $item->id ? 'selected' : '' }}>{{ $item->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-3 d-flex gap-1">
                        <input type="number" step="0.25" x-model.number="presbyopiaAddition"
                               class="form-control form-control-sm" placeholder="Add." title="{{ __('actions.medical_records.presbyopia_addition') }}">
                        <button type="button" class="btn btn-outline-secondary btn-sm"
                                @click="calcPresbyopia()"
                                title="{{ __('actions.medical_records.calc') }}">
                            <i class="fas fa-pencil-alt"></i>
                        </button>
                        @if($isEdit)
                        <button type="button" class="btn btn-pink btn-sm"
                                @click="openNewDocByType('prescription')"
                                title="{{ __('actions.documentation.types.prescription') }}">
                            <i class="fas fa-print"></i>
                        </button>
                        @endif
                    </div>
                </div>
            </div>

            {{-- A/V com correção --}}
            <div class="pmr-section mb-1">
                <label class="pmr-label">{{ __('actions.medical_records.av_with') }}</label>
                <div class="d-flex gap-1">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text pmr-eye-badge">OD</span>
                        <select name="visual_acuity_with_correction_right_id" class="form-select form-select-sm">
                            <option value="">—</option>
                            @foreach($visualAcuityTypes as $item)
                                <option value="{{ $item->id }}" {{ $oldId('visual_acuity_with_correction_right_id') == $item->id ? 'selected' : '' }}>{{ $item->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text pmr-eye-badge">OE</span>
                        <select name="visual_acuity_with_correction_left_id" class="form-select form-select-sm">
                            <option value="">—</option>
                            @foreach($visualAcuityTypes as $item)
                                <option value="{{ $item->id }}" {{ $oldId('visual_acuity_with_correction_left_id') == $item->id ? 'selected' : '' }}>{{ $item->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            {{-- Biomicroscopia --}}
            {{-- Valor padrão pré-preenchido (confirmação clínica, não digitação do zero) --}}
            <div class="pmr-section mb-1">
                <label class="pmr-label">{{ __('actions.medical_records.biomicroscopy') }}</label>
                <div class="d-flex gap-1 mb-1">
                    <span class="pmr-eye-inline">OD</span>
                    <input type="text" name="biomicroscopy_right"
                           value="{{ $old('biomicroscopy_right', $r?->biomicroscopy_right ?? __('actions.medical_records.biomicroscopy_ph')) }}"
                           class="form-control form-control-sm">
                </div>
                <div class="d-flex gap-1">
                    <span class="pmr-eye-inline">OE</span>
                    <input type="text" name="biomicroscopy_left"
                           value="{{ $old('biomicroscopy_left', $r?->biomicroscopy_left ?? __('actions.medical_records.biomicroscopy_ph')) }}"
                           class="form-control form-control-sm">
                </div>
            </div>

            {{-- Fundoscopia --}}
            {{-- Valor padrão pré-preenchido (confirmação clínica, não digitação do zero) --}}
            <div class="pmr-section mb-1">
                <label class="pmr-label">{{ __('actions.medical_records.fundoscopy') }}</label>
                <div class="d-flex gap-1 mb-1">
                    <span class="pmr-eye-inline">OD</span>
                    <input type="text" name="fundoscopy_right"
                           value="{{ $old('fundoscopy_right', $r?->fundoscopy_right ?? __('actions.medical_records.fundoscopy_ph')) }}"
                           class="form-control form-control-sm">
                </div>
                <div class="d-flex gap-1">
                    <span class="pmr-eye-inline">OE</span>
                    <input type="text" name="fundoscopy_left"
                           value="{{ $old('fundoscopy_left', $r?->fundoscopy_left ?? __('actions.medical_records.fundoscopy_ph')) }}"
                           class="form-control form-control-sm">
                </div>
            </div>

            {{-- Observação --}}
            <div class="pmr-section mb-1">
                <label class="pmr-label">{{ __('actions.medical_records.general_obs') }}</label>
                <textarea name="observation_general" rows="2"
                          class="form-control form-control-sm">{{ $old('observation_general') }}</textarea>
            </div>


        </div>{{-- /col direita --}}

    </div>{{-- /row duas colunas --}}

    {{-- ═══════════════════════════════════════════════════════════════════
         SEÇÃO INFERIOR: HDA / Histórico / Medicamentos / Lentes / Diagnóstico
         (colapsável para manter o formulário compacto)
         ═══════════════════════════════════════════════════════════════════ --}}
    <div class="px-3 pb-2">
        <div class="pmr-collapse-toggle mb-2" data-bs-toggle="collapse" data-bs-target="#pmr-extra-fields" role="button"
             aria-expanded="{{ ($old('observation_of_lenses') || $old('diagnosis_cids') || $old('clinical_conduct') || $old('ocular_motility')) ? 'true' : 'false' }}">
            <i class="fas fa-chevron-down me-1 pmr-collapse-icon"></i>
            <span class="pmr-label mb-0 d-inline">{{ __('actions.medical_records.extra_fields') }}</span>
        </div>

        <div class="collapse {{ ($old('observation_of_lenses') || $old('diagnosis_cids') || $old('clinical_conduct') || $old('ocular_motility')) ? 'show' : '' }}"
             id="pmr-extra-fields">

            {{-- Motilidade ocular --}}
            <div class="row g-2 mb-2">
                <div class="col-12 col-md-6">
                    <label class="pmr-label">{{ __('actions.medical_records.ocular_motility') }}</label>
                    <input type="text" name="ocular_motility" value="{{ $old('ocular_motility') }}"
                           class="form-control form-control-sm">
                </div>
            </div>

            <div class="row g-2 mb-2">
                <div class="col-12 col-md-6">
                    <label class="pmr-label">{{ __('actions.medical_records.lenses_obs') }}</label>
                    <textarea name="observation_of_lenses" rows="2"
                              class="form-control form-control-sm">{{ $old('observation_of_lenses') }}</textarea>
                </div>
                <div class="col-12"
                     x-data="cid10Search(
                         @js(route('panel.cid10.search')),
                         @js($old('diagnosis_cids', isset($medicalrecord) ? $medicalrecord->diagnosis_cids : []))
                     )">
                    <label class="pmr-label">{{ __('actions.medical_records.cid10') }}</label>

                    {{-- Input hidden serializado — enviado no submit do formulário --}}
                    <input type="hidden" name="diagnosis_cids" x-bind:value="serialized">

                    {{-- Tags dos CIDs selecionados --}}
                    <div class="d-flex flex-wrap gap-1 mb-1" x-show="hasSelection" x-cloak>
                        <template x-for="item in selected" :key="item.code">
                            <span class="badge d-inline-flex align-items-center gap-1"
                                  style="background:#e8f4fd;color:#1a5c8a;font-size:.8rem;font-weight:500;border:1px solid #b8d9f0;padding:.3rem .5rem;">
                                <span x-text="item.code" class="fw-semibold"></span>
                                <span class="text-secondary fw-normal" x-text="'– ' + item.description" style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></span>
                                <button type="button"
                                        class="btn-close btn-close-sm ms-1"
                                        style="font-size:.6rem;"
                                        @click="remove(item.code)"
                                        :aria-label="'Remover ' + item.code"></button>
                            </span>
                        </template>
                    </div>

                    {{-- Campo de busca --}}
                    <div class="position-relative">
                        <div class="input-group input-group-sm">
                            <input type="text"
                                   class="form-control form-control-sm"
                                   placeholder="Buscar por código ou diagnóstico (ex: H40.1, glaucoma)…"
                                   autocomplete="off"
                                   x-model="query"
                                   @input.debounce.300ms="search()"
                                   @keydown.arrow-down.prevent="focusResult(activeIndex + 1)"
                                   @keydown.arrow-up.prevent="focusResult(activeIndex - 1)"
                                   @keydown.enter.prevent="selectActive()"
                                   @keydown.escape="close()"
                                   @focus="query.length >= 2 && search()">
                            <span class="input-group-text bg-transparent border-start-0 px-2"
                                  x-show="searching" x-cloak>
                                <span class="spinner-border spinner-border-sm text-secondary"
                                      style="width:.8rem;height:.8rem;"></span>
                            </span>
                        </div>

                        {{-- Dropdown de resultados --}}
                        <ul class="list-group shadow-sm position-absolute w-100"
                            style="z-index:1055;top:100%;max-height:260px;overflow-y:auto;"
                            x-show="open && results.length > 0" x-cloak
                            @click.outside="close()">
                            <template x-for="(item, index) in results" :key="item.id">
                                <li class="list-group-item list-group-item-action py-1 px-2"
                                    style="cursor:pointer;font-size:.82rem;"
                                    :class="{ 'active': index === activeIndex }"
                                    @mouseenter="activeIndex = index"
                                    @mousedown.prevent="select(item)">
                                    <span class="fw-semibold me-1" x-text="item.code"></span>
                                    <span x-text="'– ' + item.description"></span>
                                    <span x-show="item.category"
                                          class="badge bg-light text-secondary ms-1 fw-normal float-end"
                                          style="font-size:.7rem;"
                                          x-text="item.category"></span>
                                </li>
                            </template>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="row g-2 mb-2">
                <div class="col-12 col-md-8">
                    <label class="pmr-label">{{ __('actions.medical_records.clinical_conduct') }}</label>
                    <textarea name="clinical_conduct" rows="2" class="form-control form-control-sm"
                              placeholder="{{ __('actions.medical_records.clinical_conduct_ph') }}">{{ $old('clinical_conduct') }}</textarea>
                </div>
                <div class="col-12 col-md-4">
                    <label class="pmr-label">{{ __('actions.medical_records.follow_up_days') }}</label>
                    <div class="input-group input-group-sm">
                        <input type="number" name="follow_up_days" min="0"
                               value="{{ $old('follow_up_days') }}"
                               class="form-control form-control-sm">
                        <span class="input-group-text">{{ __('actions.medical_records.days') }}</span>
                    </div>
                </div>
            </div>

        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════════
         BARRA INFERIOR: Documentações / Arquivos / Salvar
         (estilo smart_oftal icon bar)
         ═══════════════════════════════════════════════════════════════════ --}}
    <div class="pmr-bottom-bar px-3 py-2">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div>
                <span class="pmr-label mb-0 d-inline-block">
                    {{ __('actions.medical_records.doc_bar_label') }}
                </span>
            </div>

            <div class="d-flex flex-wrap gap-1 align-items-center">
                @php
                    $docTypeImages = [
                        'prescription' => 'prescription.svg',
                        'procedure'    => 'procedure.svg',
                        'certificate'  => 'certificate.svg',
                        'referral'     => 'referral.svg',
                    ];
                    $saveFirst = __('actions.medical_records.save_first');
                @endphp

                @if($isEdit)
                    {{-- EDIT: botões interativos --}}
                    @foreach($documentationTypes ?? [] as $typeKey => $typeLabel)
                        @if($typeKey === 'report') @continue @endif
                        <button type="button" class="btn pmr-doc-img-btn" title="{{ $typeLabel }}"
                                @click="openNewDocByType('{{ $typeKey }}')">
                            <img src="{{ asset('system/images/medical_records/' . ($docTypeImages[$typeKey] ?? 'report.svg')) }}"
                                 alt="{{ $typeLabel }}">
                            <span class="pmr-doc-img-btn-label">{{ $typeLabel }}</span>
                        </button>
                    @endforeach

                    <button type="button" class="btn pmr-doc-img-btn" title="Laudos Médicos"
                            @click="openNewDocByType('report')">
                        <img src="{{ asset('system/images/medical_records/laudos_medicos.svg') }}" alt="Laudos">
                        <span class="pmr-doc-img-btn-label" style="white-space:normal;line-height:1.1;">Laudos<br>Médicos</span>
                    </button>

                    <label class="btn pmr-doc-img-btn pmr-doc-annexo" title="{{ __('actions.medical_records.upload_files') }}">
                        <img src="{{ asset('system/images/medical_records/annexo.svg') }}" alt="Anexo">
                        <span class="pmr-doc-img-btn-label">Anexo</span>
                        <input type="file" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx"
                               class="d-none" @change="uploadFiles($event.target.files)">
                    </label>

                    <div x-show="uploading" class="d-flex align-items-center gap-1" style="min-width:80px;">
                        <div class="progress flex-grow-1" style="height:4px;">
                            <div class="progress-bar bg-info" :style="`width:${uploadProgress}%`"></div>
                        </div>
                        <small class="text-muted" x-text="`${uploadProgress}%`"></small>
                    </div>
                @else
                    {{-- CREATE: preview visual (disabled) — mantém percepção de tela completa --}}
                    @foreach($documentationTypes ?? [] as $typeKey => $typeLabel)
                        @if($typeKey === 'report') @continue @endif
                        <button type="button" class="btn pmr-doc-img-btn pmr-doc-preview"
                                disabled title="{{ $saveFirst }}">
                            <img src="{{ asset('system/images/medical_records/' . ($docTypeImages[$typeKey] ?? 'report.svg')) }}"
                                 alt="{{ $typeLabel }}">
                            <span class="pmr-doc-img-btn-label">{{ $typeLabel }}</span>
                        </button>
                    @endforeach

                    <button type="button" class="btn pmr-doc-img-btn pmr-doc-preview"
                            disabled title="{{ $saveFirst }}">
                        <img src="{{ asset('system/images/medical_records/laudos_medicos.svg') }}" alt="Laudos">
                        <span class="pmr-doc-img-btn-label" style="white-space:normal;line-height:1.1;">Laudos<br>Médicos</span>
                    </button>

                    <button type="button" class="btn pmr-doc-img-btn pmr-doc-annexo pmr-doc-preview"
                            disabled title="{{ $saveFirst }}">
                        <img src="{{ asset('system/images/medical_records/annexo.svg') }}" alt="Anexo">
                        <span class="pmr-doc-img-btn-label">Anexo</span>
                    </button>
                @endif

                {{-- Salvar --}}
                <button type="submit" class="btn pmr-save-btn ms-1" title="{{ __('actions.medical_records.save') }}">
                    <i class="fas fa-check-circle"></i>
                </button>
            </div>
        </div>
    </div>

    @php($selectedEntityForQuickActions = \App\Models\Entity::find(session('selected_entity_id')))
    @if($isEdit && $selectedEntityForQuickActions && auth()->user()?->can(\App\Enums\EntityGate::IssueReport->value, $selectedEntityForQuickActions))
    <div class="px-3 pb-2">
        <div class="d-flex flex-wrap gap-1">
            <button type="button" class="btn btn-outline-secondary btn-sm"
                    :disabled="quickActionBusy"
                    @click="issueMedicationPrescription()">
                Medicamentos
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm"
                    :disabled="quickActionBusy"
                    @click="issueProcedureRequest()">
                Procedimentos
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm"
                    :disabled="quickActionBusy"
                    @click="issueQuickAction('pterygium-prescription')">
                Receituário Pterígio
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm"
                    :disabled="quickActionBusy"
                    @click="issueCataractPrescription()">
                Receituário Catarata
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm"
                    :disabled="quickActionBusy"
                    @click="issueQuickAction('test-eye')">
                Teste do Olhinho
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm"
                    :disabled="quickActionBusy"
                    @click="issueQuickAction('retinal-mapping')">
                Mapeamento de Retina
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm"
                    :disabled="quickActionBusy"
                    @click="issueQuickAction('attendance-certificate')">
                Atestado Comparecimento
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm"
                    :disabled="quickActionBusy"
                    @click="issueMedicalCertificate()">
                Atestado Médico
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm"
                    :disabled="quickActionBusy"
                    @click="issueQuickAction('ophthalmological-report')">
                Laudo Oftalmológico
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm"
                    :disabled="quickActionBusy"
                    @click="issueMedicalDeclaration()">
                Declaração Médica
            </button>
        </div>
    </div>
    @endif

    {{-- ═══════════════════════════════════════════════════════════════════
         LISTA DE ARQUIVOS (somente edit, aparece abaixo da barra)
         ═══════════════════════════════════════════════════════════════════ --}}
    @if($isEdit && ($r->files?->count() || true))
    <div class="px-3 pb-2" x-show="uploadedFiles.length || {{ ($r->files?->count() ?? 0) }}">
        <div class="row g-1" id="files-grid">
            @foreach($r->files ?? [] as $file)
            <div class="col-auto">
                <a href="{{ route('panel.patients.medicalrecords.files.show', [$patient, $r, $file]) }}"
                   target="_blank" class="pmr-file-thumb" title="{{ $file->original_name }}">
                    @if($file->isImage())
                        <img src="{{ route('panel.patients.medicalrecords.files.show', [$patient, $r, $file]) }}"
                             alt="{{ $file->original_name }}">
                    @else
                        <i class="fas fa-file-alt"></i>
                    @endif
                </a>
            </div>
            @endforeach
            {{-- Uploaded via JS --}}
            <template x-for="f in uploadedFiles" :key="f.id">
                <div class="col-auto">
                    <a :href="f.show_url" target="_blank" class="pmr-file-thumb" :title="f.original_name">
                        <template x-if="f.is_image">
                            <img :src="f.show_url" :alt="f.original_name">
                        </template>
                        <template x-if="!f.is_image">
                            <i class="fas fa-file-alt"></i>
                        </template>
                    </a>
                </div>
            </template>
        </div>
    </div>
    @endif

    {{-- ═══════════════════════════════════════════════════════════════════
         LISTA DE DOCUMENTAÇÕES EXISTENTES (somente edit)
         ═══════════════════════════════════════════════════════════════════ --}}
    @if($isEdit)
    <div class="px-3 pb-2">
        <label class="pmr-label">{{ __('actions.medical_records.documentations') }}</label>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 pmr-docs-table">
                <thead class="table-light">
                    <tr>
                        <th>{{ __('actions.medical_records.doc_type') }}</th>
                        <th>{{ __('actions.medical_records.doc_title') }}</th>
                        <th>{{ __('actions.medical_records.doc_date') }}</th>
                        <th class="text-end">{{ __('actions.medical_records.doc_actions') }}</th>
                    </tr>
                </thead>
                <tbody id="pmr-docs-tbody">
                    @forelse($r->documentations as $doc)
                    <tr>
                        <td><span class="badge bg-info-subtle text-info">{{ $doc->getTypeLabel() }}</span></td>
                        <td>{{ $doc->title }}</td>
                        <td>{{ $doc->created_at?->format('d/m/Y H:i') }}</td>
                        <td class="text-end">
                            <a href="{{ route('panel.patients.medicalrecords.documentations.pdf', [$patient, $r, $doc]) }}"
                               target="_blank" class="btn btn-outline-secondary btn-sm" title="PDF">
                                <i class="fas fa-file-pdf"></i>
                            </a>
                        </td>
                    </tr>
                    @empty
                    <tr data-empty>
                        <td colspan="4" class="text-center text-muted small py-2">
                            {{ __('actions.medical_records.no_documentations') }}
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- ═══════════════════════════════════════════════════════════════════════
         MODAL: Nova Documentação
         ═══════════════════════════════════════════════════════════════════════ --}}
    @if($isEdit)
    <div class="modal fade" id="docModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h6 class="modal-title"><i class="fas fa-file-prescription me-2"></i>{{ __('actions.medical_records.new_documentation') }}</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="pmr-label">{{ __('actions.medical_records.select_template') }}</label>
                        <select class="form-select form-select-sm" x-model="docForm.report_setting_content_id"
                                @change="previewTemplate()">
                            <option value="">{{ __('actions.medical_records.select') }}</option>
                            <template x-for="group in docTemplates" :key="group.report_setting_id">
                                <optgroup :label="group.report_setting_title">
                                    <template x-for="tpl in group.contents" :key="tpl.id">
                                        <option :value="tpl.id" x-text="tpl.label"></option>
                                    </template>
                                </optgroup>
                            </template>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="pmr-label">{{ __('actions.medical_records.doc_title') }}</label>
                        <input type="text" class="form-control form-control-sm" x-model="docForm.title"
                               placeholder="{{ __('actions.medical_records.doc_title_ph') }}">
                    </div>
                    <div class="mb-0">
                        <label class="pmr-label">{{ __('actions.medical_records.doc_content') }}</label>
                        <textarea class="form-control" rows="12" x-model="docForm.content"
                                  placeholder="{{ __('actions.medical_records.doc_content_ph') }}"></textarea>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                        {{ __('actions.medical_records.cancel') }}
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" @click="saveDoc()" :disabled="docSaving">
                        <i class="fas fa-save me-1"></i>{{ __('actions.medical_records.save_documentation') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- ═══════════════════════════════════════════════════════════════════════
         MODAL BOOTSTRAP: Laudo de Tonômetria (dentro do x-data do form)
         ═══════════════════════════════════════════════════════════════════════ --}}
    <div class="modal fade" id="tonometryModal" tabindex="-1"
         @keydown.escape.window="closeTonometry()">
        <div class="modal-dialog modal-xl modal-dialog-centered" style="height:90vh;">
            <div class="modal-content" style="height:100%;">
                <div class="modal-header py-2">
                    <h6 class="modal-title mb-0">
                        <i class="fas fa-print me-2" style="color:#e91e8c;"></i>
                        {{ __('actions.medical_records.print_tonometry') }}
                    </h6>
                    <button type="button" class="btn-close" @click="closeTonometry()"></button>
                </div>
                <div class="modal-body p-0" style="flex:1;overflow:hidden;">
                    <iframe x-bind:src="tonometryPdfSrc"
                            style="width:100%;height:100%;border:none;display:block;"
                            title="Laudo de Tonômetria"></iframe>
                </div>
            </div>
        </div>
    </div>

</form>
