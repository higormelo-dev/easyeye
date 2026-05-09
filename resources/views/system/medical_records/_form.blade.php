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
      class="pmr-form"
      @submit="validateBeforeSubmit($event)"
      x-data="medicalRecordForm({
          validationRulesUrl: @js(route('panel.medical-records.validation-rules', ['mode' => $isEdit ? 'update' : 'store'])),
          isEdit: {{ $isEdit ? 'true' : 'false' }},
          diabetic: {{ $old('diabetic') ? 'true' : 'false' }},
          diabeticFamily: {{ $old('diabetic_family') ? 'true' : 'false' }},
          hypertensive: {{ $old('hypertensive') ? 'true' : 'false' }},
          hypertensiveFamily: {{ $old('hypertensive_family') ? 'true' : 'false' }},
          glaucomatous: {{ $old('glaucomatous') ? 'true' : 'false' }},
          glaucomatousFamily: {{ $old('glaucomatous_family') ? 'true' : 'false' }},
          showOthersHistory: {{ $old('others_history') ? 'true' : 'false' }},
          calcPresbyopiaUrl: @js(route('panel.patients.medicalrecords.calculate-presbyopia', $patient)),
          lensFormatUrl: @js(route('panel.medicalrecords.lens-format')),
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
          examTemplateUrlTemplate: @js(route('panel.patients.medicalrecords.exam-template', [$patient, $r, '__EXAM__'])),
          medicineSearchUrl: @js(route('panel.medicines.search')),
          medicationFormatLineUrl: @js(route('panel.medication-prescription.format-line')),
          procedureSearchUrl: @js(route('panel.procedures.search')),
          indicationSearchUrl: @js(route('panel.indications.search')),
          procedureFormatLineUrl: @js(route('panel.procedure-solicitation.format-line')),
          dayExtensionPreviewUrl: @js(route('panel.medical-records.day-extension-preview')),
          @else
          storeTonometryUrl: '',
          @endif
          i18n: @js([
              'complaint_required_title'  => __('actions.medical_records.complaint_required_title'),
              'complaint_required_text'   => __('actions.medical_records.complaint_required_text'),
              'doctor_required_title'     => __('actions.medical_records.doctor_required_title'),
              'doctor_required_for_print' => __('actions.medical_records.doctor_required_for_print'),
              'doctor_required_for_issue' => __('actions.medical_records.doctor_required_for_issue'),
              'field_doctor'              => __('actions.medical_records.field_doctor'),
              'field_complaint'           => __('actions.medical_records.field_complaint'),
              'field_pachymetry_od'       => __('actions.medical_records.field_pachymetry_od'),
              'field_pachymetry_oe'       => __('actions.medical_records.field_pachymetry_oe'),
              'field_follow_up'           => __('actions.medical_records.field_follow_up'),
          ]),
      })">
    @csrf
    @if($isEdit) @method('PUT') @endif

    {{-- F9 — alerta de erros client-side. Substitui @include('errors.message-error')
         do legado quando o validador detecta problema antes de bater no servidor.
         Após reload, ainda renderiza erros server-side via $errors padrão. --}}
    <div class="alert alert-danger m-3 mb-0" role="alert"
         x-show="hasClientErrors" x-cloak>
        <h6 class="alert-heading mb-1">
            <i class="fas fa-exclamation-triangle me-1"></i>
            {{ __('actions.medical_records.client_errors_title') }}
        </h6>
        <ul class="mb-0 small ps-3">
            <template x-for="(msgs, field) in clientErrors" :key="field">
                <template x-for="(m, i) in msgs" :key="field + '-' + i">
                    <li x-text="m"></li>
                </template>
            </template>
        </ul>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger m-3 mb-0" role="alert">
            <h6 class="alert-heading mb-1">
                <i class="fas fa-exclamation-triangle me-1"></i>
                {{ __('actions.medical_records.server_errors_title') }}
            </h6>
            <ul class="mb-0 small ps-3">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Banner informativo: visível apenas no CREATE, some após o primeiro save.
         Placeholders :complaint e :save_action são escapados individualmente
         antes de receber o <strong> — nunca HTML em string de tradução. --}}
    @if(! $isEdit)
    @php
        $createInfoHtml = __('messages.medical_records.create_info', [
            'complaint'   => '<strong>' . e(__('actions.medical_records.complaint'))         . '</strong>',
            'save_action' => '<strong>' . e(__('messages.medical_records.save_action')) . '</strong>',
        ]);
    @endphp
    <div class="alert alert-info d-flex align-items-start gap-2 m-3 mb-0" role="alert">
        <i class="fas fa-info-circle mt-1 flex-shrink-0"></i>
        <span class="small">{!! $createInfoHtml !!}</span>
    </div>
    @endif

    {{-- schedule_id: preserva vínculo com a agenda quando o prontuário é
         iniciado a partir de um agendamento. Controller usa para redirecionar
         de volta ao calendário após salvar. --}}
    <input type="hidden" name="schedule_id"
           value="{{ old('schedule_id', request()->get('schedule_id') ?? $r?->schedule_id) }}">

    {{-- ═══════════════════════════════════════════════════════════════════
         MÉDICO (só quando necessário) + QUEIXA + FLAGS (linha topo)
         ═══════════════════════════════════════════════════════════════════ --}}

    {{-- Médico vinculado: campo oculto (exceto admin, que pode escolher) --}}
    @if(!$canChooseDoctor && $currentDoctor)
        <input type="hidden"
               name="doctor_id"
               value="{{ $oldId('doctor_id') ?: $currentDoctor->id }}">
    @else
    {{-- Sem médico vinculado: select compacto em linha própria --}}
    <div class="pmr-section px-3 pt-2">
        <div class="row g-2">
            <div class="col-12 col-md-4 col-lg-3">
                <label class="pmr-label">{{ __('actions.medical_records.doctor') }}</label>
                <select name="doctor_id"
                        class="form-select form-select-sm @error('doctor_id') is-invalid @enderror"
                        @change="doctorId = $event.target.value">
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
    <div class="pmr-section pmr-top-strip px-3 pt-2 pb-0 bg-white">
        {{-- Queixa principal + Switches --}}
        <div class="row g-2 align-items-start">
            <div class="col-12 col-lg-8">
                <label class="pmr-label">{{ __('actions.medical_records.complaint') }}</label>
                <input type="text" name="main_complaint" value="{{ $old('main_complaint') }}"
                       class="form-control form-control-sm @error('main_complaint') is-invalid @enderror"
                       placeholder="{{ __('actions.medical_records.complaint_ph') }}">
            </div>
            {{-- Switches: Diabético / Hipertenso / Glaucomatoso --}}
            <div class="col-12 col-lg-4 pmr-risk-wrap">
                <label class="pmr-label">{{ __('actions.medical_records.clinical_history') }}</label>
                <div class="row g-1 pmr-risk-grid">
                    @foreach(['diabetic', 'hypertensive'] as $flag)
                    <div class="col-4 pmr-risk-item">
                        <label class="pmr-label text-center d-block" style="font-size:.7rem;">
                            {{ __('actions.medical_records.' . $flag) }}
                            {{-- Feedback visual do alerta clínico (paridade smart_oftal #diabeticAlert / #hypertensiveAlert) --}}
                            <span x-show="alertVisible.{{ $flag }} === 'self'" x-cloak
                                  x-transition.opacity
                                  class="ms-1 fw-bold" style="color:#f62d51;">{{ __('actions.medical_records.alert_self') }}</span>
                            <span x-show="alertVisible.{{ $flag }} === 'family'" x-cloak
                                  x-transition.opacity
                                  class="ms-1 fw-bold" style="color:#ffbc34;">{{ __('actions.medical_records.alert_family') }}</span>
                        </label>
                        <div class="pmr-risk-switches">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch"
                                       name="{{ $flag }}" value="1"
                                       x-model="{{ \Illuminate\Support\Str::camel($flag) }}"
                                       @change="if($event.target.checked) flashAlert('{{ $flag }}', 'self')">
                                <label class="form-check-label pmr-toggle-label">{{ __('actions.medical_records.self') }}</label>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch"
                                       name="{{ $flag }}_family" value="1"
                                       x-model="{{ \Illuminate\Support\Str::camel($flag) }}Family"
                                       @change="if($event.target.checked) flashAlert('{{ $flag }}', 'family')">
                                <label class="form-check-label pmr-toggle-label">{{ __('actions.medical_records.family') }}</label>
                            </div>
                        </div>
                    </div>
                    @endforeach

                    {{-- Glaucomatoso — com campo "Outros" expansível --}}
                    <div class="col-4 pmr-risk-item">
                        <label class="pmr-label text-center d-block" style="font-size:.7rem;">
                            {{ __('actions.medical_records.glaucomatous') }}
                            <span x-show="alertVisible.glaucomatous === 'self'" x-cloak
                                  x-transition.opacity
                                  class="ms-1 fw-bold" style="color:#f62d51;">{{ __('actions.medical_records.alert_self') }}</span>
                            <span x-show="alertVisible.glaucomatous === 'family'" x-cloak
                                  x-transition.opacity
                                  class="ms-1 fw-bold" style="color:#ffbc34;">{{ __('actions.medical_records.alert_family') }}</span>
                        </label>
                        <div class="pmr-risk-switches">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch"
                                       name="glaucomatous" value="1"
                                       x-model="glaucomatous"
                                       @change="if($event.target.checked) flashAlert('glaucomatous', 'self')">
                                <label class="form-check-label pmr-toggle-label">{{ __('actions.medical_records.self') }}</label>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch"
                                       name="glaucomatous_family" value="1"
                                       x-model="glaucomatousFamily"
                                       @change="if($event.target.checked) flashAlert('glaucomatous', 'family')">
                                <label class="form-check-label pmr-toggle-label">{{ __('actions.medical_records.family') }}</label>
                            </div>
                        </div>
                        {{-- Botão "Outros" --}}
                        <div class="text-center mt-1">
                            <button type="button"
                                    class="btn btn-link p-0 pmr-toggle-label text-decoration-none"
                                    style="font-size:.68rem;color:var(--bs-secondary);"
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
    </div>

    {{-- ═══════════════════════════════════════════════════════════════════
         DUAS COLUNAS PRINCIPAIS
         ═══════════════════════════════════════════════════════════════════ --}}
    <div class="row g-2 px-3 pt-1 pb-1 pmr-main-columns">

        {{-- ── COLUNA ESQUERDA ─────────────────────────────────────────── --}}
        <div class="col-12 col-lg-6 pe-lg-2">
            <div class="pmr-main-panel">

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
                    {{-- Tonometria
                         Auto-stamp do horário ao terminar OE (paridade smart_oftal):
                         o tempo é capturado no momento clínico da medição, garantindo
                         coerência temporal mesmo quando a impressão acontece minutos depois. --}}
                    <div class="col-6">
                        <label class="pmr-label">
                            {{ __('actions.medical_records.tonometry') }}
                            {{-- Carimbo do horário clínico: se medição já capturada,
                                 mostra esse horário (azul); caso contrário, mostra
                                 relógio ao vivo (cinza) p/ referência do operador. --}}
                            <span x-show="tonometryStampedTime" x-cloak
                                  class="ms-1 fw-bold" style="color:#03a9f3; font-size:.7rem;"
                                  x-text="tonometryStampedTime"></span>
                            <span x-show="!tonometryStampedTime" x-cloak
                                  class="ms-1 text-muted" style="font-size:.7rem;"
                                  x-text="liveTime"></span>
                        </label>
                        <div class="d-flex gap-1 align-items-center">
                            <div class="input-group input-group-sm" style="max-width:90px;">
                                <span class="input-group-text pmr-eye-badge">OD</span>
                                <input type="number" name="tonometer_right" step="0.5" min="0"
                                       class="form-control form-control-sm text-center"
                                       value="{{ $old('tonometer_right') }}" placeholder="00"
                                       @click="$event.target.select()">
                            </div>
                            <div class="input-group input-group-sm" style="max-width:90px;">
                                <span class="input-group-text pmr-eye-badge">OE</span>
                                <input type="number" name="tonometer_left" step="0.5" min="0"
                                       class="form-control form-control-sm text-center"
                                       value="{{ $old('tonometer_left') }}" placeholder="00"
                                       @click="$event.target.select()"
                                       @blur="stampTonometryTime()">
                            </div>
                            <input type="hidden" name="tonometer_time" id="tonometer-time-hidden"
                                   x-bind:value="tonometryStampedTime"
                                   value="{{ $old('tonometer_time') }}">
                            {{-- Print: stampTonometryTime(true) é chamado dentro de
                                 printTonometry quando ainda não houver carimbo,
                                 garantindo timestamp automático no momento da impressão. --}}
                            <button type="button" class="btn btn-pink btn-sm flex-shrink-0"
                                    title="{{ __('actions.medical_records.print_tonometry') }}"
                                    @click="printTonometry()">
                                <i class="fas fa-print"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Dinâmica
                 type="text" + auto-format via lensFormatUrl: o backend normaliza
                 sinal (+/-), arredonda ao step 0.25 e padroniza o eixo (0..180º).
                 Enter avança o foco para o próximo campo na sequência clínica padrão. --}}
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
                            <td><input type="text" inputmode="decimal" name="dynamic_spherical_right"
                                       value="{{ $old('dynamic_spherical_right', '0.00') }}" placeholder="0.00"
                                       @click="$event.target.select()"
                                       @blur="formatLens('spherical', 'dynamic_spherical_right'); dynamicSphericalRight = parseFloat($event.target.value) || 0"
                                       @keydown.enter.prevent="formatLens('spherical', 'dynamic_spherical_right').then(() => focusNextLensField('dynamic_spherical_right'))"></td>
                            <td><input type="text" inputmode="decimal" name="dynamic_cylindrical_right"
                                       value="{{ $old('dynamic_cylindrical_right', '0.00') }}" placeholder="0.00"
                                       @click="$event.target.select()"
                                       @blur="formatLens('cylindrical', 'dynamic_cylindrical_right')"
                                       @keydown.enter.prevent="formatLens('cylindrical', 'dynamic_cylindrical_right').then(() => focusNextLensField('dynamic_cylindrical_right'))"></td>
                            <td><input type="text" inputmode="numeric" name="dynamic_axis_right"
                                       value="{{ $old('dynamic_axis_right') }}" placeholder="0º"
                                       @click="$event.target.select()"
                                       @blur="formatLens('axis', 'dynamic_axis_right')"
                                       @keydown.enter.prevent="formatLens('axis', 'dynamic_axis_right').then(() => focusNextLensField('dynamic_axis_right'))"></td>
                        </tr>
                        <tr>
                            <td class="pmr-od">OE</td>
                            <td><input type="text" inputmode="decimal" name="dynamic_spherical_left"
                                       value="{{ $old('dynamic_spherical_left', '0.00') }}" placeholder="0.00"
                                       @click="$event.target.select()"
                                       @blur="formatLens('spherical', 'dynamic_spherical_left'); dynamicSphericalLeft = parseFloat($event.target.value) || 0"
                                       @keydown.enter.prevent="formatLens('spherical', 'dynamic_spherical_left').then(() => focusNextLensField('dynamic_spherical_left'))"></td>
                            <td><input type="text" inputmode="decimal" name="dynamic_cylindrical_left"
                                       value="{{ $old('dynamic_cylindrical_left', '0.00') }}" placeholder="0.00"
                                       @click="$event.target.select()"
                                       @blur="formatLens('cylindrical', 'dynamic_cylindrical_left')"
                                       @keydown.enter.prevent="formatLens('cylindrical', 'dynamic_cylindrical_left').then(() => focusNextLensField('dynamic_cylindrical_left'))"></td>
                            <td><input type="text" inputmode="numeric" name="dynamic_axis_left"
                                       value="{{ $old('dynamic_axis_left') }}" placeholder="0º"
                                       @click="$event.target.select()"
                                       @blur="formatLens('axis', 'dynamic_axis_left')"
                                       @keydown.enter.prevent="formatLens('axis', 'dynamic_axis_left').then(() => focusNextLensField('dynamic_axis_left'))"></td>
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
                            <td><input type="text" inputmode="decimal" name="static_spherical_right"
                                       value="{{ $old('static_spherical_right', '0.00') }}" placeholder="0.00"
                                       @click="$event.target.select()"
                                       @blur="formatLens('spherical', 'static_spherical_right'); staticSphericalRight = parseFloat($event.target.value) || 0"
                                       @keydown.enter.prevent="formatLens('spherical', 'static_spherical_right').then(() => focusNextLensField('static_spherical_right'))"></td>
                            <td><input type="text" inputmode="decimal" name="static_cylindrical_right"
                                       value="{{ $old('static_cylindrical_right', '0.00') }}" placeholder="0.00"
                                       @click="$event.target.select()"
                                       @blur="formatLens('cylindrical', 'static_cylindrical_right')"
                                       @keydown.enter.prevent="formatLens('cylindrical', 'static_cylindrical_right').then(() => focusNextLensField('static_cylindrical_right'))"></td>
                            <td><input type="text" inputmode="numeric" name="static_axis_right"
                                       value="{{ $old('static_axis_right') }}" placeholder="0º"
                                       @click="$event.target.select()"
                                       @blur="formatLens('axis', 'static_axis_right')"
                                       @keydown.enter.prevent="formatLens('axis', 'static_axis_right').then(() => focusNextLensField('static_axis_right'))"></td>
                        </tr>
                        <tr>
                            <td class="pmr-od">OE</td>
                            <td><input type="text" inputmode="decimal" name="static_spherical_left"
                                       value="{{ $old('static_spherical_left', '0.00') }}" placeholder="0.00"
                                       @click="$event.target.select()"
                                       @blur="formatLens('spherical', 'static_spherical_left'); staticSphericalLeft = parseFloat($event.target.value) || 0"
                                       @keydown.enter.prevent="formatLens('spherical', 'static_spherical_left').then(() => focusNextLensField('static_spherical_left'))"></td>
                            <td><input type="text" inputmode="decimal" name="static_cylindrical_left"
                                       value="{{ $old('static_cylindrical_left', '0.00') }}" placeholder="0.00"
                                       @click="$event.target.select()"
                                       @blur="formatLens('cylindrical', 'static_cylindrical_left')"
                                       @keydown.enter.prevent="formatLens('cylindrical', 'static_cylindrical_left').then(() => focusNextLensField('static_cylindrical_left'))"></td>
                            <td><input type="text" inputmode="numeric" name="static_axis_left"
                                       value="{{ $old('static_axis_left') }}" placeholder="0º"
                                       @click="$event.target.select()"
                                       @blur="formatLens('axis', 'static_axis_left')"
                                       @keydown.enter.prevent="formatLens('axis', 'static_axis_left')"></td>
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

            </div>
        </div>{{-- /col esquerda --}}

        {{-- ── COLUNA DIREITA ──────────────────────────────────────────── --}}
        <div class="col-12 col-lg-6 ps-lg-2">
            <div class="pmr-main-panel">

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
                                @click="openPresbyopiaCalc()"
                                title="{{ __('actions.medical_records.calc') }}">
                            <i class="fas fa-pencil-alt"></i>
                        </button>
                        @if($isEdit)
                        {{-- Receituário de óculos — dropdown 4 modos
                             (paridade smart_oftal templates 1..4) --}}
                        <div class="btn-group" role="group">
                            <button type="button" class="btn btn-pink btn-sm dropdown-toggle"
                                    data-bs-toggle="dropdown" aria-expanded="false"
                                    :disabled="quickActionBusy"
                                    title="{{ __('actions.medical_records.lens_prescription') }}">
                                <i class="fas fa-print"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><button type="button" class="dropdown-item"
                                            @click="issueLensPrescription('dynamic')">
                                    {{ __('actions.medical_records.lens_prescription_dynamic') }}
                                </button></li>
                                <li><button type="button" class="dropdown-item"
                                            @click="issueLensPrescription('static')">
                                    {{ __('actions.medical_records.lens_prescription_static') }}
                                </button></li>
                                <li><button type="button" class="dropdown-item"
                                            @click="issueLensPrescription('presbyopia_dynamic')">
                                    {{ __('actions.medical_records.lens_prescription_presdyn') }}
                                </button></li>
                                <li><button type="button" class="dropdown-item"
                                            @click="issueLensPrescription('presbyopia')">
                                    {{ __('actions.medical_records.lens_prescription_presbyo') }}
                                </button></li>
                            </ul>
                        </div>
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
                           class="form-control form-control-sm"
                           @click="$event.target.select()">
                </div>
                <div class="d-flex gap-1">
                    <span class="pmr-eye-inline">OE</span>
                    <input type="text" name="biomicroscopy_left"
                           value="{{ $old('biomicroscopy_left', $r?->biomicroscopy_left ?? __('actions.medical_records.biomicroscopy_ph')) }}"
                           class="form-control form-control-sm"
                           @click="$event.target.select()">
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
                           class="form-control form-control-sm"
                           @click="$event.target.select()">
                </div>
                <div class="d-flex gap-1">
                    <span class="pmr-eye-inline">OE</span>
                    <input type="text" name="fundoscopy_left"
                           value="{{ $old('fundoscopy_left', $r?->fundoscopy_left ?? __('actions.medical_records.fundoscopy_ph')) }}"
                           class="form-control form-control-sm"
                           @click="$event.target.select()">
                </div>
            </div>

            {{-- Observação --}}
            <div class="pmr-section mb-1">
                <label class="pmr-label">{{ __('actions.medical_records.general_obs') }}</label>
                <textarea name="observation_general" rows="2"
                          class="form-control form-control-sm">{{ $old('observation_general') }}</textarea>
            </div>


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

            <div class="row g-2 mb-2">
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
         BARRA INFERIOR: Quick-actions + Documentações + Arquivos + Salvar.
         Mescla as duas barras antigas em uma — ergonomia clínica do
         smart_oftal preservada, sem duplicação visual.
         ═══════════════════════════════════════════════════════════════════ --}}
    @php
        // Separa "pode ver" de "pode usar":
        // - $canSeeQuickActions: usuário tem permissão IssueReport — botões visíveis
        // - $isEdit: prontuário já existe — botões ativos (clicáveis)
        // No CREATE todos os botões aparecem desativados para sinalizar o que está disponível.
        $selectedEntityForQuickActions = \App\Models\Entity::find(session('selected_entity_id'));
        $canSeeQuickActions = $selectedEntityForQuickActions
            && auth()->user()?->can(\App\Enums\EntityGate::IssueReport->value, $selectedEntityForQuickActions);
        $saveFirstTitle = __('actions.medical_records.save_first');
    @endphp

    <div class="pmr-bottom-bar px-3 py-2">
        <div class="d-flex flex-wrap gap-1 align-items-center">
            {{-- ── Quick-actions (Medicamentos, Procedimentos, Pterígio…) ──────
                 Visíveis a quem tem IssueReport; desativados em CREATE porque
                 a FK medical_record_id ainda não existe. ──────────────────── --}}
            @if($canSeeQuickActions)
                <button type="button" class="btn pmr-doc-img-btn"
                        title="{{ $isEdit ? 'Receituário de Medicamentos' : $saveFirstTitle }}"
                        :disabled="!isEdit || quickActionBusy"
                        @click="openMedicationPrescription()">
                    <i class="fas fa-pills" style="font-size:1.6rem;color:#9c27b0;"></i>
                    <span class="pmr-doc-img-btn-label">Medicamentos</span>
                </button>
                <button type="button" class="btn pmr-doc-img-btn"
                        title="{{ $isEdit ? 'Solicitação de Procedimentos' : $saveFirstTitle }}"
                        :disabled="!isEdit || quickActionBusy"
                        @click="openProcedureSolicitation()">
                    <i class="fas fa-clipboard-list" style="font-size:1.6rem;color:#3f51b5;"></i>
                    <span class="pmr-doc-img-btn-label">Procedimentos</span>
                </button>
                <button type="button" class="btn pmr-doc-img-btn"
                        title="{{ $isEdit ? 'Receituário de Pterígio' : $saveFirstTitle }}"
                        :disabled="!isEdit || quickActionBusy"
                        @click="issueQuickAction('pterygium-prescription')">
                    <i class="fas fa-eye-low-vision" style="font-size:1.6rem;color:#ff5722;"></i>
                    <span class="pmr-doc-img-btn-label" style="white-space:normal;line-height:1.1;">Receituário<br>Pterígio</span>
                </button>
                <button type="button" class="btn pmr-doc-img-btn"
                        title="{{ $isEdit ? 'Receituário de Catarata' : $saveFirstTitle }}"
                        :disabled="!isEdit || quickActionBusy"
                        @click="openCataractPrescription()">
                    <i class="fas fa-eye" style="font-size:1.6rem;color:#00bcd4;"></i>
                    <span class="pmr-doc-img-btn-label" style="white-space:normal;line-height:1.1;">Receituário<br>Catarata</span>
                </button>
                <button type="button" class="btn pmr-doc-img-btn"
                        title="{{ $isEdit ? 'Teste do Olhinho' : $saveFirstTitle }}"
                        :disabled="!isEdit || quickActionBusy"
                        @click="issueQuickAction('test-eye')">
                    <i class="fas fa-baby" style="font-size:1.6rem;color:#e91e63;"></i>
                    <span class="pmr-doc-img-btn-label" style="white-space:normal;line-height:1.1;">Teste do<br>Olhinho</span>
                </button>
                <button type="button" class="btn pmr-doc-img-btn"
                        title="{{ $isEdit ? 'Mapeamento de Retina' : $saveFirstTitle }}"
                        :disabled="!isEdit || quickActionBusy"
                        @click="issueQuickAction('retinal-mapping')">
                    <i class="fas fa-bullseye" style="font-size:1.6rem;color:#673ab7;"></i>
                    <span class="pmr-doc-img-btn-label" style="white-space:normal;line-height:1.1;">Mapeamento<br>de Retina</span>
                </button>
                <button type="button" class="btn pmr-doc-img-btn"
                        title="{{ $isEdit ? 'Atestado de Comparecimento' : $saveFirstTitle }}"
                        :disabled="!isEdit || quickActionBusy"
                        @click="openAttendanceCertificate()">
                    <i class="fas fa-user-check" style="font-size:1.6rem;color:#4caf50;"></i>
                    <span class="pmr-doc-img-btn-label" style="white-space:normal;line-height:1.1;">Atestado<br>Comparecim.</span>
                </button>
                <button type="button" class="btn pmr-doc-img-btn"
                        title="{{ $isEdit ? 'Atestado Médico' : $saveFirstTitle }}"
                        :disabled="!isEdit || quickActionBusy"
                        @click="openMedicalCertificate()">
                    <i class="fas fa-stethoscope" style="font-size:1.6rem;color:#2196f3;"></i>
                    <span class="pmr-doc-img-btn-label" style="white-space:normal;line-height:1.1;">Atestado<br>Médico</span>
                </button>
                <button type="button" class="btn pmr-doc-img-btn"
                        title="{{ $isEdit ? 'Laudo Oftalmológico' : $saveFirstTitle }}"
                        :disabled="!isEdit || quickActionBusy"
                        @click="issueQuickAction('ophthalmological-report')">
                    <i class="fas fa-vial-circle-check" style="font-size:1.6rem;color:#795548;"></i>
                    <span class="pmr-doc-img-btn-label" style="white-space:normal;line-height:1.1;">Laudo<br>Oftalmológ.</span>
                </button>
            @endif

            {{-- Hub de Laudos, Documentações e Anexo: sempre visíveis.
                 Desativados em CREATE — prontuário não existe ainda. --}}
            @if(! empty($examReports))
            <button type="button" class="btn pmr-doc-img-btn"
                    title="{{ $isEdit ? __('actions.medical_records.exam_hub_title') : $saveFirstTitle }}"
                    :disabled="!isEdit"
                    data-bs-toggle="modal" data-bs-target="#examHubModal">
                <i class="fas fa-microscope" style="font-size:1.6rem;color:#03a9f3;"></i>
                <span class="pmr-doc-img-btn-label" style="white-space:normal;line-height:1.1;">Laudos<br>de Exame</span>
            </button>
            @endif

            <button type="button" class="btn pmr-doc-img-btn pmr-doc-img-btn-wide"
                    title="{{ $isEdit ? __('actions.medical_records.documentations') : $saveFirstTitle }}"
                    :disabled="!isEdit"
                    data-bs-toggle="modal" data-bs-target="#documentationsModal">
                <i class="fas fa-folder-open" style="font-size:1.6rem;color:#0288d1;"></i>
                <span class="pmr-doc-img-btn-label">{{ __('actions.medical_records.documentations') }}</span>
            </button>

            {{-- Anexo: <label> funcional em EDIT; visual desativado em CREATE
                 (<label disabled> não existe em HTML — usa classe Bootstrap). --}}
            @if($isEdit)
            <label class="btn pmr-doc-img-btn pmr-doc-annexo"
                   title="{{ __('actions.medical_records.upload_files') }}">
                <img src="{{ asset('system/images/medical_records/annexo.svg') }}" alt="Anexo">
                <span class="pmr-doc-img-btn-label">Anexo</span>
                <input type="file" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx"
                       class="d-none" @change="uploadFiles($event.target.files)">
            </label>
            <template x-if="uploading">
                <div class="d-flex align-items-center gap-1" style="min-width:80px;">
                    <div class="progress flex-grow-1" style="height:4px;">
                        <div class="progress-bar bg-info" :style="`width:${uploadProgress}%`"></div>
                    </div>
                    <small class="text-muted" x-text="`${uploadProgress}%`"></small>
                </div>
            </template>
            @else
            <span class="btn pmr-doc-img-btn disabled" aria-disabled="true"
                  title="{{ $saveFirstTitle }}">
                <img src="{{ asset('system/images/medical_records/annexo.svg') }}" alt="Anexo">
                <span class="pmr-doc-img-btn-label">Anexo</span>
            </span>
            @endif

            {{-- Salvar — empurrado para a direita via ms-auto. --}}
            <button type="submit" class="btn pmr-save-btn ms-auto" title="{{ __('actions.medical_records.save') }}">
                <i class="fas fa-check-circle"></i>
            </button>
        </div>
    </div>

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
         MODAL: Lista de Documentações Existentes (somente edit)
         Abre via botão "Documentações" na bottom-bar (`#documentationsModal`).
         Tabela renderizada server-side; novas docs criadas via quick-action
         atualizam o `<tbody id="pmr-docs-tbody">` via JS (mantém ID p/ compat).
         ═══════════════════════════════════════════════════════════════════ --}}
    @if($isEdit)
    <div class="modal fade" id="documentationsModal" tabindex="-1" aria-labelledby="documentationsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h6 class="modal-title" id="documentationsModalLabel">
                        <i class="fas fa-folder-open me-2" style="color:#0288d1;"></i>
                        {{ __('actions.medical_records.documentations') }}
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body p-2">
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
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                        {{ __('actions.medical_records.close') }}
                    </button>
                </div>
            </div>
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
                    {{-- Linha topo: template OU badge de exame + título editável.
                         No fluxo F4 (exam-report) o título já vem auto-populado
                         pelo ExamReportRegistry (`label — subtype`); ocultamos
                         o input para reduzir ruído visual e usamos col-12 no
                         badge. Em fluxo livre (sem exam_type) layout col-6/col-6. --}}
                    <div class="row g-2 mb-3">
                        {{-- Fluxo F4: badge ocupa linha inteira; título escondido (auto). --}}
                        <div class="col-12" x-show="docForm.exam_type" x-cloak>
                            <span class="badge bg-info-subtle text-info">
                                <i class="fas fa-microscope me-1"></i>
                                <span x-text="docForm.exam_label || docForm.exam_type"></span>
                            </span>
                            {{-- input hidden mantém title no payload mesmo sem UI editável --}}
                            <input type="hidden" x-model="docForm.title">
                        </div>

                        {{-- Fluxo livre: select de template + input de título lado a lado. --}}
                        <template x-if="!docForm.exam_type">
                            <div class="row g-2 m-0 p-0 w-100">
                                <div class="col-12 col-md-6">
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
                                <div class="col-12 col-md-6">
                                    <label class="pmr-label">{{ __('actions.medical_records.doc_title') }}</label>
                                    <input type="text" class="form-control form-control-sm" x-model="docForm.title"
                                           placeholder="{{ __('actions.medical_records.doc_title_ph') }}">
                                </div>
                            </div>
                        </template>
                    </div>
                    <div class="mb-0">
                        <label class="pmr-label">{{ __('actions.medical_records.doc_content') }}</label>
                        <textarea id="doc-modal-content" class="form-control" rows="12"
                                  x-model="docForm.content"
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
         MODAL UNIVERSAL: Pré-visualização de qualquer documento PDF emitido.
         Usado por receituário de óculos (4 modos), atestados, laudos, etc.
         ═══════════════════════════════════════════════════════════════════════ --}}
    @include('system.medical_records._pdf-preview-modal')

    {{-- ═══════════════════════════════════════════════════════════════════════
         F4b — HUB DE LAUDOS DE EXAME
         Lista 15 cases do ExamReportRegistry. Cada card chama openExam(value)
         que carrega template (auto-popula via TemplateVariableResolver) e abre
         docModal já no fluxo exam-report. Templates ausentes geram toast.
         ═══════════════════════════════════════════════════════════════════════ --}}
    {{-- ═══════════════════════════════════════════════════════════════════════
         F5 — BUILDER de Receituário de Medicamentos
         Search async (medicines.search) → adiciona à lista (limit 5, dedupe)
         → backend formata cada linha (medication-prescription.format-line)
         → textarea editável → salvar via quick-action 'medication-prescription'.
         ═══════════════════════════════════════════════════════════════════════ --}}
    @if($isEdit)
    <div class="modal fade" id="medicationPrescriptionModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h6 class="modal-title">
                        <i class="fas fa-prescription me-2" style="color:#e91e8c;"></i>
                        Receituário de Medicamentos
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    {{-- Buscador --}}
                    <label class="pmr-label mb-1">Adicionar medicamento</label>
                    <div class="position-relative mb-2">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text"><i class="fas fa-search"></i></span>
                            <input type="text"
                                   class="form-control form-control-sm"
                                   placeholder="Digite ao menos 2 letras (ex: dipirona, colírio…)"
                                   x-model="medSearchQuery"
                                   @input.debounce.300ms="searchMedicines()"
                                   @keydown.escape="medSearchOpen = false"
                                   :disabled="prescription.length >= maxMedicines">
                            <span class="input-group-text bg-transparent" x-show="medSearchLoading" x-cloak>
                                <span class="spinner-border spinner-border-sm" style="width:.8rem;height:.8rem;"></span>
                            </span>
                        </div>
                        <ul class="list-group shadow-sm position-absolute w-100"
                            style="z-index:1080;top:100%;max-height:280px;overflow-y:auto;"
                            x-show="medSearchOpen && medSearchResults.length > 0"
                            x-cloak
                            @click.outside="medSearchOpen = false">
                            <template x-for="item in medSearchResults" :key="item.id">
                                <li class="list-group-item list-group-item-action py-1 px-2"
                                    style="cursor:pointer;font-size:.82rem;"
                                    @mousedown.prevent="addMedicine(item)">
                                    <span class="fw-semibold" x-text="item.name"></span>
                                    <span class="text-muted ms-1" x-show="item.presentation" x-cloak
                                          x-text="'(' + item.presentation + ')'"></span>
                                    <span class="text-secondary ms-2 small" x-show="item.dosage" x-cloak
                                          x-text="item.dosage + ' ' + (item.frequency || '')"></span>
                                </li>
                            </template>
                        </ul>
                    </div>

                    {{-- Limit warning --}}
                    <div class="alert alert-warning py-1 px-2 small mb-2"
                         x-show="prescription.length >= maxMedicines" x-cloak>
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        Limite de <span x-text="maxMedicines"></span> medicamentos atingido. Imprima esta receita antes de adicionar mais.
                    </div>

                    {{-- Lista de medicamentos selecionados --}}
                    <div class="mb-2" x-show="prescription.length > 0" x-cloak>
                        <label class="pmr-label mb-1">Medicamentos da receita (<span x-text="prescription.length"></span>/<span x-text="maxMedicines"></span>)</label>
                        <ul class="list-group">
                            <template x-for="(item, idx) in prescription" :key="item.id + '-' + idx">
                                <li class="list-group-item d-flex justify-content-between align-items-center py-1 px-2"
                                    style="font-size:.82rem;">
                                    <span>
                                        <span class="badge bg-secondary me-1" x-text="idx + 1"></span>
                                        <span class="fw-semibold" x-text="item.name"></span>
                                        <span class="text-muted ms-1" x-show="item.presentation" x-cloak
                                              x-text="'(' + item.presentation + ')'"></span>
                                    </span>
                                    <button type="button" class="btn btn-sm btn-link text-danger p-0"
                                            @click="removeMedicine(idx)"
                                            title="Remover">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </li>
                            </template>
                        </ul>
                    </div>

                    {{-- Textarea editável --}}
                    <label class="pmr-label mb-1">Conteúdo da receita</label>
                    <textarea class="form-control form-control-sm"
                              rows="10"
                              x-model="medicineLists"
                              placeholder="Linhas formatadas aparecem aqui. Edição livre permitida antes da impressão."></textarea>
                </div>
                <div class="modal-footer py-2 d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-secondary btn-sm"
                            @click="clearMedicines()"
                            :disabled="prescription.length === 0 && !medicineLists">
                        <i class="fas fa-eraser me-1"></i>Limpar
                    </button>
                    <div>
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                            {{ __('actions.medical_records.cancel') }}
                        </button>
                        <button type="button" class="btn btn-primary btn-sm"
                                @click="submitMedicationPrescription()"
                                :disabled="quickActionBusy || (!medicineLists.trim())">
                            <i class="fas fa-print me-1"></i>Emitir Receita
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- ═══════════════════════════════════════════════════════════════════════
         F6 — MODAL: Solicitação de Procedimentos
         Médico busca procedimento + tipo (rotina/urgência/controle/comparativo)
         e indicações em listas separadas; ambas concatenadas na textarea
         editável. Emissão chama quick-action 'procedure-request'.
         ═══════════════════════════════════════════════════════════════════════ --}}
    @if($isEdit)
    <div class="modal fade" id="procedureSolicitationModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h6 class="modal-title">
                        <i class="fas fa-stethoscope me-2" style="color:#03a9f3;"></i>
                        {{ __('actions.medical_records.procedure_title') }}
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2 mb-2">
                        {{-- Procedure search + type --}}
                        <div class="col-12 col-md-7">
                            <label class="pmr-label mb-1">{{ __('actions.medical_records.procedure_search_label') }}</label>
                            <div class="position-relative">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                                    <input type="text"
                                           class="form-control form-control-sm"
                                           placeholder="{{ __('actions.medical_records.procedure_search_ph') }}"
                                           x-model="procSearchQuery"
                                           @input.debounce.300ms="searchProcedures()"
                                           @keydown.escape="procSearchOpen = false"
                                           :disabled="procSelected.length + indSelected.length >= maxProcSolicitations">
                                    <span class="input-group-text bg-transparent" x-show="procSearchLoading" x-cloak>
                                        <span class="spinner-border spinner-border-sm" style="width:.8rem;height:.8rem;"></span>
                                    </span>
                                </div>
                                <ul class="list-group shadow-sm position-absolute w-100"
                                    style="z-index:1080;top:100%;max-height:240px;overflow-y:auto;"
                                    x-show="procSearchOpen && procSearchResults.length > 0"
                                    x-cloak
                                    @click.outside="procSearchOpen = false">
                                    <template x-for="item in procSearchResults" :key="item.id">
                                        <li class="list-group-item list-group-item-action py-1 px-2"
                                            style="cursor:pointer;font-size:.82rem;"
                                            @mousedown.prevent="addProcedure(item)">
                                            <span class="fw-semibold" x-text="item.name"></span>
                                            <span class="text-muted ms-1 small" x-show="item.code" x-cloak
                                                  x-text="'(' + item.code + ')'"></span>
                                        </li>
                                    </template>
                                </ul>
                            </div>
                        </div>
                        <div class="col-12 col-md-5">
                            <label class="pmr-label mb-1">{{ __('actions.medical_records.procedure_type_label') }}</label>
                            <select class="form-select form-select-sm" x-model="procTypeSelected">
                                <option value="">—</option>
                                <option value="rotina">{{ __('actions.medical_records.procedure_type_rotina') }}</option>
                                <option value="urgencia">{{ __('actions.medical_records.procedure_type_urgencia') }}</option>
                                <option value="controle">{{ __('actions.medical_records.procedure_type_controle') }}</option>
                                <option value="comparativo">{{ __('actions.medical_records.procedure_type_comparativo') }}</option>
                            </select>
                        </div>
                    </div>

                    {{-- Indication search --}}
                    <label class="pmr-label mb-1">{{ __('actions.medical_records.procedure_indication_label') }}</label>
                    <div class="position-relative mb-2">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text"><i class="fas fa-search"></i></span>
                            <input type="text"
                                   class="form-control form-control-sm"
                                   placeholder="{{ __('actions.medical_records.procedure_indication_ph') }}"
                                   x-model="indSearchQuery"
                                   @input.debounce.300ms="searchIndications()"
                                   @keydown.escape="indSearchOpen = false"
                                   :disabled="procSelected.length + indSelected.length >= maxProcSolicitations">
                            <span class="input-group-text bg-transparent" x-show="indSearchLoading" x-cloak>
                                <span class="spinner-border spinner-border-sm" style="width:.8rem;height:.8rem;"></span>
                            </span>
                        </div>
                        <ul class="list-group shadow-sm position-absolute w-100"
                            style="z-index:1080;top:100%;max-height:240px;overflow-y:auto;"
                            x-show="indSearchOpen && indSearchResults.length > 0"
                            x-cloak
                            @click.outside="indSearchOpen = false">
                            <template x-for="item in indSearchResults" :key="item.id">
                                <li class="list-group-item list-group-item-action py-1 px-2"
                                    style="cursor:pointer;font-size:.82rem;"
                                    @mousedown.prevent="addIndication(item)">
                                    <span x-text="item.description"></span>
                                </li>
                            </template>
                        </ul>
                    </div>

                    {{-- Limit warning --}}
                    <div class="alert alert-warning py-1 px-2 small mb-2"
                         x-show="(procSelected.length + indSelected.length) >= maxProcSolicitations" x-cloak>
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        {{ __('actions.medical_records.procedure_max_reached') }}
                    </div>

                    {{-- Selected list --}}
                    <div class="mb-2"
                         x-show="procSelected.length + indSelected.length > 0" x-cloak>
                        <label class="pmr-label mb-1">
                            {{ __('actions.medical_records.procedure_selected') }}
                            (<span x-text="procSelected.length + indSelected.length"></span>/<span x-text="maxProcSolicitations"></span>)
                        </label>
                        <ul class="list-group">
                            <template x-for="(item, idx) in procSelected" :key="'p-' + item.id + '-' + idx">
                                <li class="list-group-item d-flex justify-content-between align-items-center py-1 px-2"
                                    style="font-size:.82rem;">
                                    <span>
                                        <span class="badge bg-info text-dark me-1">P</span>
                                        <span class="fw-semibold" x-text="item.name"></span>
                                        <span class="text-muted ms-1 small" x-show="item.type" x-cloak
                                              x-text="'— ' + item.type_label"></span>
                                    </span>
                                    <button type="button" class="btn btn-sm btn-link text-danger p-0"
                                            @click="removeProcedure(idx)" title="Remover">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </li>
                            </template>
                            <template x-for="(item, idx) in indSelected" :key="'i-' + item.id + '-' + idx">
                                <li class="list-group-item d-flex justify-content-between align-items-center py-1 px-2"
                                    style="font-size:.82rem;">
                                    <span>
                                        <span class="badge bg-secondary me-1">I</span>
                                        <span x-text="item.description"></span>
                                    </span>
                                    <button type="button" class="btn btn-sm btn-link text-danger p-0"
                                            @click="removeIndication(idx)" title="Remover">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </li>
                            </template>
                        </ul>
                    </div>

                    {{-- Editable textarea --}}
                    <label class="pmr-label mb-1">{{ __('actions.medical_records.procedure_content') }}</label>
                    <textarea class="form-control form-control-sm"
                              rows="8"
                              x-model="procedureLists"
                              placeholder="Linhas formatadas aparecem aqui. Edição livre permitida antes da impressão."></textarea>
                </div>
                <div class="modal-footer py-2 d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-secondary btn-sm"
                            @click="clearProcedureSolicitation()"
                            :disabled="procSelected.length === 0 && indSelected.length === 0 && !procedureLists">
                        <i class="fas fa-eraser me-1"></i>Limpar
                    </button>
                    <div>
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                            {{ __('actions.medical_records.cancel') }}
                        </button>
                        <button type="button" class="btn btn-primary btn-sm"
                                @click="submitProcedureSolicitation()"
                                :disabled="quickActionBusy || !procedureLists.trim()">
                            <i class="fas fa-print me-1"></i>{{ __('actions.medical_records.procedure_emit') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- ═══════════════════════════════════════════════════════════════════════
         F8 — MODAL: Receituário de Catarata
         Coleta olho operado, modelo do receituário e (opcional) data/hora
         da cirurgia, evitando série de window.prompt do legado.
         ═══════════════════════════════════════════════════════════════════════ --}}
    @if($isEdit)
    <div class="modal fade" id="cataractPrescriptionModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h6 class="modal-title">
                        <i class="fas fa-eye me-2" style="color:#e91e8c;"></i>
                        {{ __('actions.medical_records.cataract_title') }}
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    {{-- Olho operado --}}
                    <label class="pmr-label mb-1">{{ __('actions.medical_records.cataract_eye_label') }}</label>
                    <div class="btn-group w-100 mb-3" role="group" aria-label="Olho operado">
                        <input type="radio" class="btn-check" id="cataractEyeRight"
                               value="right" x-model="cataractForm.eye">
                        <label class="btn btn-outline-primary btn-sm" for="cataractEyeRight">
                            {{ __('actions.medical_records.cataract_eye_right') }}
                        </label>

                        <input type="radio" class="btn-check" id="cataractEyeLeft"
                               value="left" x-model="cataractForm.eye">
                        <label class="btn btn-outline-primary btn-sm" for="cataractEyeLeft">
                            {{ __('actions.medical_records.cataract_eye_left') }}
                        </label>

                        <input type="radio" class="btn-check" id="cataractEyeBoth"
                               value="both" x-model="cataractForm.eye">
                        <label class="btn btn-outline-primary btn-sm" for="cataractEyeBoth">
                            {{ __('actions.medical_records.cataract_eye_both') }}
                        </label>
                    </div>

                    {{-- Modelo --}}
                    <label class="pmr-label mb-1">{{ __('actions.medical_records.cataract_template') }}</label>
                    <select class="form-select form-select-sm mb-3" x-model="cataractForm.template">
                        <option value="pre_operatorio">{{ __('actions.medical_records.cataract_template_pre') }}</option>
                        <option value="pos_operatorio">{{ __('actions.medical_records.cataract_template_pos') }}</option>
                        <option value="instrucoes_cirurgicas">{{ __('actions.medical_records.cataract_template_inst') }}</option>
                    </select>

                    {{-- Data + hora (relevantes p/ instrucoes_cirurgicas) --}}
                    <div class="row g-2 mb-1"
                         :class="{ 'opacity-75': cataractForm.template !== 'instrucoes_cirurgicas' }">
                        <div class="col-7">
                            <label class="pmr-label mb-1">{{ __('actions.medical_records.cataract_date') }}</label>
                            <input type="text" class="form-control form-control-sm"
                                   placeholder="dd/mm/aaaa"
                                   maxlength="10"
                                   x-model="cataractForm.date_surgery"
                                   @input="formatCataractDate($event)">
                        </div>
                        <div class="col-5">
                            <label class="pmr-label mb-1">{{ __('actions.medical_records.cataract_hour') }}</label>
                            <input type="time" class="form-control form-control-sm"
                                   x-model="cataractForm.hour_surgery">
                        </div>
                    </div>
                    <small class="text-muted d-block"
                           x-show="cataractForm.template === 'instrucoes_cirurgicas'" x-cloak>
                        <i class="fas fa-info-circle me-1"></i>
                        {{ __('actions.medical_records.cataract_help_inst') }}
                    </small>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                        {{ __('actions.medical_records.cancel') }}
                    </button>
                    <button type="button" class="btn btn-primary btn-sm"
                            @click="submitCataractPrescription()"
                            :disabled="quickActionBusy || !cataractForm.eye">
                        <i class="fas fa-print me-1"></i>{{ __('actions.medical_records.cataract_emit') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- ═══════════════════════════════════════════════════════════════════════
         F7 — MODAL: Atestado de Comparecimento
         Template renderizado server-side. Médico edita só "observações" livres
         (anexadas ao final do documento), sem reescrever cabeçalho/data.
         ═══════════════════════════════════════════════════════════════════════ --}}
    @if($isEdit)
    <div class="modal fade" id="attendanceCertificateModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h6 class="modal-title">
                        <i class="fas fa-file-signature me-2" style="color:#03a9f3;"></i>
                        {{ __('actions.medical_records.attendance_certificate_title') }}
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <label class="pmr-label mb-1">{{ __('actions.medical_records.certificate_obs_label') }}</label>
                    <textarea id="attendance-cert-content"
                              class="form-control form-control-sm"
                              rows="6"
                              maxlength="5000"
                              placeholder="{{ __('actions.medical_records.certificate_obs_ph') }}"
                              x-model="attendanceForm.content"></textarea>
                    <small class="text-muted d-block mt-1">
                        <span x-text="(attendanceForm.content || '').length"></span>/5000
                    </small>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                        {{ __('actions.medical_records.cancel') }}
                    </button>
                    <button type="button" class="btn btn-primary btn-sm"
                            @click="submitAttendanceCertificate()"
                            :disabled="quickActionBusy">
                        <i class="fas fa-print me-1"></i>{{ __('actions.medical_records.certificate_emit') }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════════════
         F7 — MODAL: Atestado Médico (afastamento)
         Dias + dia por extenso (preview backend) + observações livres.
         ═══════════════════════════════════════════════════════════════════════ --}}
    <div class="modal fade" id="medicalCertificateModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h6 class="modal-title">
                        <i class="fas fa-notes-medical me-2" style="color:#e91e8c;"></i>
                        {{ __('actions.medical_records.medical_certificate_title') }}
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2 mb-2">
                        <div class="col-7">
                            <label class="pmr-label mb-1">{{ __('actions.medical_records.medical_cert_days_label') }}</label>
                            <input type="number" min="1" max="365" step="1"
                                   class="form-control form-control-sm"
                                   x-model.number="medicalForm.days"
                                   @input.debounce.350ms="refreshDayExtension()">
                            <small class="text-muted d-block mt-1">
                                {{ __('actions.medical_records.medical_cert_days_help') }}
                            </small>
                        </div>
                        <div class="col-5">
                            <label class="pmr-label mb-1">{{ __('actions.medical_records.medical_cert_date_label') }}</label>
                            <input type="text" class="form-control form-control-sm"
                                   placeholder="dd/mm/aaaa"
                                   maxlength="10"
                                   x-model="medicalForm.date"
                                   @input="formatMedicalCertDate($event)">
                            <button type="button" class="btn btn-link btn-sm p-0 mt-1"
                                    @click="medicalForm.date = ''">
                                <small>{{ __('actions.medical_records.medical_cert_date_today') }}</small>
                            </button>
                        </div>
                    </div>

                    {{-- Preview real-time do dia por extenso --}}
                    <div class="alert alert-info py-2 px-2 small mb-2"
                         x-show="medicalForm.daysPreview" x-cloak>
                        <i class="fas fa-eye me-1"></i>
                        <strong>{{ __('actions.medical_records.medical_cert_days_preview') }}:</strong>
                        <span x-text="medicalForm.daysPreview"></span>
                    </div>

                    <label class="pmr-label mb-1">{{ __('actions.medical_records.certificate_obs_label') }}</label>
                    <textarea id="medical-cert-content"
                              class="form-control form-control-sm"
                              rows="5"
                              maxlength="5000"
                              placeholder="{{ __('actions.medical_records.certificate_obs_ph') }}"
                              x-model="medicalForm.content"></textarea>
                    <small class="text-muted d-block mt-1">
                        <span x-text="(medicalForm.content || '').length"></span>/5000
                    </small>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                        {{ __('actions.medical_records.cancel') }}
                    </button>
                    <button type="button" class="btn btn-primary btn-sm"
                            @click="submitMedicalCertificate()"
                            :disabled="quickActionBusy || !medicalForm.days || medicalForm.days < 1 || medicalForm.days > 365">
                        <i class="fas fa-print me-1"></i>{{ __('actions.medical_records.certificate_emit') }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="examHubModal" tabindex="-1" aria-labelledby="examHubModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h6 class="modal-title" id="examHubModalLabel">
                        <i class="fas fa-microscope me-2" style="color:#03a9f3;"></i>
                        {{ __('actions.medical_records.exam_hub_title') }}
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3">
                        {{ __('actions.medical_records.exam_hub_help') }}
                    </p>
                    <div class="row g-2 pmr-exam-hub">
                        @foreach($examReports as $exam)
                        <div class="col-6 col-md-4 col-lg-3">
                            @if(empty($exam['subtypes']))
                                {{-- Exame com template único: card abre direto --}}
                                <button type="button"
                                        class="btn pmr-exam-card w-100 h-100"
                                        @click="openExamFromHub(@js($exam['value']))"
                                        title="{{ $exam['label'] }}">
                                    <i class="fas {{ $exam['icon'] }} pmr-exam-card-icon"></i>
                                    <span class="pmr-exam-card-label">{{ $exam['label'] }}</span>
                                </button>
                            @else
                                {{-- F4e — Exame multi-variante (Pentacam): dropdown com subtipos --}}
                                <div class="dropdown w-100 h-100">
                                    <button type="button"
                                            class="btn pmr-exam-card pmr-exam-card-multi w-100 h-100 dropdown-toggle"
                                            data-bs-toggle="dropdown"
                                            aria-expanded="false"
                                            title="{{ $exam['label'] }}">
                                        <i class="fas {{ $exam['icon'] }} pmr-exam-card-icon"></i>
                                        <span class="pmr-exam-card-label">{{ $exam['label'] }}</span>
                                    </button>
                                    <ul class="dropdown-menu pmr-exam-subtypes-menu">
                                        @foreach($exam['subtypes'] as $sub)
                                            <li>
                                                <button type="button"
                                                        class="dropdown-item"
                                                        @click="openExamFromHub(@js($exam['value']), @js($sub['slug']))">
                                                    <i class="fas fa-angle-right me-2 text-muted small"></i>{{ $sub['label'] }}
                                                </button>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </div>
                        @endforeach
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                        {{ __('actions.medical_records.cancel') }}
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

    {{-- ═══════════════════════════════════════════════════════════════════════
         MODAL: Calcular Presbiopia — coleta observação de lentes antes do
         cálculo. Disponível em CREATE e EDIT.
         ═══════════════════════════════════════════════════════════════════════ --}}
    <div class="modal fade" id="presbyopiaObsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h6 class="modal-title">
                        <i class="fas fa-glasses me-2" style="color:#00bcd4;"></i>
                        {{ __('actions.medical_records.presbyopia_obs_title') }}
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('actions.medical_records.close') }}"></button>
                </div>
                <div class="modal-body">
                    <label class="pmr-label mb-1">{{ __('actions.medical_records.presbyopia_obs_label') }}</label>
                    <textarea class="form-control form-control-sm"
                              rows="4"
                              maxlength="5000"
                              placeholder="{{ __('actions.medical_records.presbyopia_obs_ph') }}"
                              x-model="presbyopiaObsForm.content"></textarea>
                    <small class="text-muted d-block mt-1">
                        <span x-text="(presbyopiaObsForm.content || '').length"></span>/5000
                    </small>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                        {{ __('actions.medical_records.cancel') }}
                    </button>
                    <button type="button" class="btn btn-primary btn-sm"
                            @click="confirmPresbyopiaCalc()">
                        <i class="fas fa-pencil-alt me-1"></i>
                        {{ __('actions.medical_records.presbyopia_obs_confirm') }}
                    </button>
                </div>
            </div>
        </div>
    </div>

</form>
