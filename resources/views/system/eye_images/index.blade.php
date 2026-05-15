@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')
    <div x-data="eyeImagesApp(@js($patients))">

        {{-- ── Card de filtros (sem título) ──────────────────────────────────────── --}}
        <div class="card mb-3">
            <div class="card-body py-2 px-3">

                {{-- Linha principal ------------------------------------------------ --}}
                <div class="row g-2 align-items-center">

                    {{-- Busca --}}
                    <div class="col-12 col-sm-4 col-md-3">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text"><i class="fa fa-search"></i></span>
                            <input type="text" class="form-control" placeholder="{{ __('eye_images.search_placeholder') }}"
                                x-model="search" @keydown.escape="search = ''">
                            <button class="btn btn-outline-secondary" type="button" x-show="search" x-cloak
                                @click="search = ''">
                                <i class="fa fa-times"></i>
                            </button>
                        </div>
                    </div>

                    {{-- Período --}}
                    <div class="col-6 col-sm-3 col-md-2">
                        <select class="form-select form-select-sm" :value="period"
                            @change="changePeriod($event.target.value)">
                            <option value="hoje">{{ __('eye_images.period_today') }}</option>
                            <option value="7">{{ __('eye_images.period_7') }}</option>
                            <option value="15">{{ __('eye_images.period_15') }}</option>
                            <option value="30">{{ __('eye_images.period_30') }}</option>
                            <option value="90">{{ __('eye_images.period_90') }}</option>
                        </select>
                    </div>

                    {{-- Botão avançado --}}
                    <div class="col-6 col-sm-auto">
                        <button type="button" class="btn btn-sm"
                            :class="showFilters ? 'btn-primary' : 'btn-outline-secondary'"
                            @click="showFilters = !showFilters">
                            <i class="fa fa-filter me-1"></i>
                            {{ __('eye_images.filters_btn') }}
                            <i class="fa fa-chevron-down ms-1" x-show="!showFilters"></i>
                            <i class="fa fa-chevron-up ms-1" x-show="showFilters" x-cloak></i>
                        </button>
                    </div>

                    {{-- Novo --}}
                    <div class="col col-md d-flex justify-content-end">
                        <button type="button" class="btn btn-primary btn-sm">
                            <i class="fa fa-plus"></i> {{ __('actions.new') }}
                        </button>
                    </div>

                </div>

                {{-- Linha avançada (colapsável) ------------------------------------ --}}
                <div x-show="showFilters" x-cloak class="row g-2 mt-1 pt-2 border-top align-items-center">

                    {{-- Lateralidade --}}
                    <div class="col-auto">
                        <div class="d-flex align-items-center gap-2">
                            <span class="text-muted small fw-semibold" style="white-space:nowrap;">{{ __('eye_images.eye_label') }}</span>
                            <div class="btn-group btn-group-sm" role="group">
                                <input type="radio" class="btn-check" name="f-lat" id="f-lat-all" value=""
                                    x-model="laterality">
                                <label class="btn btn-outline-secondary" for="f-lat-all">{{ __('eye_images.all') }}</label>

                                <input type="radio" class="btn-check" name="f-lat" id="f-lat-od" value="od"
                                    x-model="laterality">
                                <label class="btn btn-outline-primary" for="f-lat-od">OD</label>

                                <input type="radio" class="btn-check" name="f-lat" id="f-lat-oe" value="oe"
                                    x-model="laterality">
                                <label class="btn btn-outline-danger" for="f-lat-oe">OE</label>

                                <input type="radio" class="btn-check" name="f-lat" id="f-lat-ao" value="ao"
                                    x-model="laterality">
                                <label class="btn btn-outline-dark" for="f-lat-ao">AO</label>
                            </div>
                        </div>
                    </div>

                    {{-- Tipo de exame --}}
                    <div class="col-12 col-sm-6 col-md-3">
                        <select class="form-select form-select-sm" x-model="examTypeId">
                            <option value="">{{ __('eye_images.all_exams') }}</option>
                            <template x-for="t in availableExamTypes" :key="t.id">
                                <option :value="t.id" x-text="t.name"></option>
                            </template>
                        </select>
                    </div>

                    {{-- Status --}}
                    <div class="col-12 col-sm-6 col-md-2">
                        <select class="form-select form-select-sm" x-model="examStatus">
                            <option value="">{{ __('eye_images.all_statuses') }}</option>
                            <option value="solicitado">{{ __('eye_images.status_requested') }}</option>
                            <option value="realizado">{{ __('eye_images.status_done') }}</option>
                            <option value="laudado">{{ __('eye_images.status_reported') }}</option>
                            <option value="cancelado">{{ __('eye_images.status_cancelled') }}</option>
                        </select>
                    </div>

                    {{-- Médico --}}
                    <div class="col-12 col-sm-6 col-md-3">
                        <select class="form-select form-select-sm" :value="doctorId"
                            @change="setDoctor($event.target.value)">
                            <option value="">{{ __('eye_images.all_doctors') }}</option>
                            @foreach ($doctors as $doctor)
                                <option value="{{ $doctor->id }}">{{ $doctor->person->full_name ?? '—' }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Limpar filtros avançados --}}
                    <div class="col-auto">
                        <button type="button" class="btn btn-sm btn-outline-secondary" @click="clearFilters()">
                            <i class="fa fa-times me-1"></i> {{ __('eye_images.clear_btn') }}
                        </button>
                    </div>

                </div>

            </div>
        </div>

        {{-- ── Painel lateral + área principal ────────────────────────────────── --}}
        <div class="row">

            {{-- ── Coluna lateral (col-3): período + médicos + pacientes ──────────── --}}
            <div class="col-xs-12 col-sm-3 col-md-3 col-lg-3">
                <div class="card panel-info">
                    <div class="card-body p-2">

                        {{-- Pacientes ------------------------------------------------- --}}
                        <h6 class="font-bold text-uppercase px-1 mb-1 mt-3">{{ __('eye_images.patients_title') }}</h6>
                        <hr class="mt-0 mb-2">

                        {{-- Spinner --}}
                        <div x-show="loading" x-cloak class="text-center py-3">
                            <div class="spinner-border spinner-border-sm text-info" role="status"></div>
                        </div>

                        {{-- Lista --}}
                        <div x-show="!loading" style="max-height:420px;overflow-y:auto;overflow-x:hidden;">

                            <template x-if="filteredPatients.length === 0">
                                <p class="text-muted text-center small py-3 mb-0">{{ __('eye_images.no_patients') }}</p>
                            </template>

                            <template x-for="patient in filteredPatients" :key="patient.id">
                                <div class="d-flex align-items-center gap-2 px-1 py-1 rounded mb-1"
                                    style="cursor:pointer;transition:background .12s;"
                                    :style="selectedPatient?.id === patient.id ? 'background:#e8f0fe;' :
                                        'background:transparent;'"
                                    @click="selectPatient(patient)"
                                    @mouseenter="if(selectedPatient?.id !== patient.id) $el.style.background='#f4f6fb'"
                                    @mouseleave="if(selectedPatient?.id !== patient.id) $el.style.background='transparent'">

                                    <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 text-white fw-bold"
                                        :style="{
                                            background: avatarColor(patient.person?.full_name),
                                            width: '30px',
                                            height: '30px',
                                            fontSize: '.62rem'
                                        }"
                                        x-text="initials(patient.person?.full_name)">
                                    </div>

                                    <div class="flex-grow-1 min-w-0">
                                        <div class="text-truncate fw-semibold" style="font-size:.75rem; line-height:1.2;"
                                            x-text="patient.person?.full_name ?? '—'"></div>
                                        <div class="text-muted" style="font-size:.65rem; line-height:1.2;"
                                            x-text="patient.code"></div>
                                    </div>

                                    <span class="badge bg-primary rounded-pill flex-shrink-0" style="font-size:.6rem;"
                                        x-text="patient.exams.length"></span>
                                </div>
                            </template>

                        </div>

                        <div x-show="!loading" class="text-muted px-1 mt-1" style="font-size:.65rem;"
                            x-text="filteredPatients.length + ' {{ __('eye_images.patients_count_suffix') }}'"></div>

                    </div>
                </div>
            </div>

            {{-- ── Coluna principal (col-9): detalhe dos exames ───────────────────── --}}
            <div class="col-xs-12 col-sm-9 col-md-9 col-lg-9">
                <div class="card">
                    <h5 class="card-header d-flex align-items-center gap-2">
                        <span x-show="!selectedPatient">{{ $meta['action'] }}</span>
                        <template x-if="selectedPatient">
                            <span class="d-flex align-items-center gap-2 w-100">
                                <button type="button" class="btn btn-outline-secondary btn-sm"
                                    @click="selectedPatient = null; selectedExamIds = [];">
                                    <i class="fa fa-arrow-left"></i>
                                </button>
                                <span>
                                    <span x-text="selectedPatient.person?.full_name"></span>
                                    <small class="text-muted fw-normal ms-2" style="font-size:.72rem;"
                                        x-text="selectedPatient.code"></small>
                                </span>
                                <div class="flex-grow-1"></div>
                                <a :href="`{{ rtrim(route('panel.patients.medicalrecords.index', ['patient' => '__ID__']), '') }}`
                                .replace('__ID__', selectedPatient.id)"
                                    target="_blank" class="btn btn-outline-primary btn-sm" style="font-size:.72rem;">
                                    {{ __('eye_images.medical_record') }} <i class="fa fa-external-link ms-1"></i>
                                </a>
                            </span>
                        </template>
                    </h5>

                    {{-- Barra de ações do paciente --}}
                    <div x-show="selectedPatient" x-cloak
                        class="d-flex align-items-center gap-2 px-3 py-2 border-bottom bg-body-secondary">
                        <button type="button" class="btn btn-sm btn-outline-primary"
                            :disabled="selectedExamIds.length === 0" @click="openViewerModal(selectedExamsData)">
                            <i class="fa fa-images me-1"></i>{{ __('eye_images.view_selected') }}
                            <span class="badge bg-primary ms-1" x-show="selectedExamIds.length > 0"
                                x-text="selectedExamIds.length"></span>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary"
                            @click="openViewerModal(selectedPatient.exams)">
                            <i class="fa fa-th me-1"></i>{{ __('eye_images.view_all') }}
                        </button>
                        <div class="vr opacity-25"></div>
                        <button type="button" class="btn btn-sm btn-outline-dark"
                            @click="openPrintModal(selectedPatient.exams, true)">
                            <i class="fa fa-print me-1"></i>{{ __('eye_images.print_btn') }}
                        </button>
                        <div class="flex-grow-1"></div>
                        <span class="text-muted" style="font-size:.7rem;" x-show="selectedExamIds.length > 0"
                            x-text="selectedExamIds.length + ' {{ __('eye_images.selected_suffix') }}'"></span>
                    </div>

                    <div class="card-body">

                        {{-- Placeholder --}}
                        <template x-if="!selectedPatient">
                            <div class="text-center py-5 text-muted">
                                <i class="ti ti-eye" style="font-size:3rem;opacity:.3;"></i>
                                <p class="mt-3 mb-0">{{ __('eye_images.select_patient_hint') }}</p>
                            </div>
                        </template>

                        {{-- Detalhe --}}
                        <template x-if="selectedPatient">
                            <div class="row g-0" style="min-height:480px;">

                                {{-- ── col-4: lista de exames ──────────────────────── --}}
                                <div class="col-12 border-end pe-0">

                                    {{-- Spinner de URLs --}}
                                    <div x-show="urlsLoading" x-cloak class="text-center py-3">
                                        <div class="spinner-border spinner-border-sm text-secondary" role="status"></div>
                                        <p class="text-muted small mt-1 mb-0">{{ __('eye_images.loading_images') }}</p>
                                    </div>

                                    {{-- Vazio --}}
                                    <template x-if="filteredExams.length === 0 && !urlsLoading">
                                        <p class="text-muted text-center small py-4">{{ __('eye_images.no_exams') }}</p>
                                    </template>

                                    {{-- Grupos: data + equipamento + tipo --}}
                                    <div x-show="!urlsLoading"
                                        style="max-height:520px;overflow-y:auto;overflow-x:hidden;">

                                        <template x-for="group in groupedExams" :key="group.key">
                                            <div class="mb-1">

                                                {{-- Header: data : equipamento + filtros + ações --}}
                                                <div class="px-2 py-1 d-flex align-items-center gap-1 flex-wrap bg-body-tertiary text-body border-bottom fw-semibold"
                                                    style="font-size:.7rem;row-gap:3px;">

                                                    {{-- Esquerda: data + equipamento --}}
                                                    <span x-text="formatDateFull(group.date)"></span>
                                                    <template x-if="group.equipment">
                                                        <span class="d-flex align-items-center gap-1">
                                                            <span class="opacity-50">:</span>
                                                            <span x-text="group.equipment.name"></span>
                                                        </span>
                                                    </template>

                                                    <div class="flex-grow-1"></div>

                                                    {{-- Seleção por lateralidade --}}
                                                    <div class="btn-group btn-group-sm" role="group">
                                                        <button type="button" class="btn py-0 px-2"
                                                            :class="groupLatActive(group, 'od') ? 'btn-primary' :
                                                                'btn-outline-primary'"
                                                            style="font-size:.6rem;"
                                                            @click.stop="selectExamByLaterality(group, 'od')">OD</button>
                                                        <button type="button" class="btn py-0 px-2"
                                                            :class="groupLatActive(group, 'oe') ? 'btn-danger' :
                                                                'btn-outline-danger'"
                                                            style="font-size:.6rem;"
                                                            @click.stop="selectExamByLaterality(group, 'oe')">OE</button>
                                                        <button type="button" class="btn py-0 px-2"
                                                            :class="groupLatActive(group, 'ao') ? 'btn-secondary' :
                                                                'btn-outline-secondary'"
                                                            style="font-size:.6rem;"
                                                            @click.stop="selectExamByLaterality(group, 'ao')">AO</button>
                                                        <button type="button" class="btn py-0 px-2"
                                                            :class="groupLatActive(group, 'all') ? 'btn-secondary' :
                                                                'btn-outline-secondary'"
                                                            style="font-size:.6rem;"
                                                            @click.stop="selectExamByLaterality(group, 'all')">{{ __('eye_images.all') }}</button>
                                                    </div>

                                                    <div class="vr opacity-25 mx-1"></div>

                                                    {{-- Upload --}}
                                                    <button type="button"
                                                        class="btn btn-sm py-0 px-2 btn-outline-secondary"
                                                        style="font-size:.6rem;" title="Upload de imagem">
                                                        <i class="fa fa-upload me-1"></i>{{ __('eye_images.upload_btn') }}
                                                    </button>

                                                    {{-- Download --}}
                                                    <button type="button"
                                                        class="btn btn-sm py-0 px-2 btn-outline-secondary"
                                                        style="font-size:.6rem;" title="Download das imagens">
                                                        <i class="fa fa-download me-1"></i>{{ __('eye_images.download_btn') }}
                                                    </button>

                                                </div>

                                                {{-- Subtítulo: tipo de exame --}}
                                                <div class="px-2 py-1 bg-body-secondary text-body-secondary border-bottom"
                                                    style="font-size:.68rem;">
                                                    <span x-text="group.examType?.name || 'Exame'"></span>
                                                </div>

                                                {{-- Thumbnails: fundo sempre escuro (padrão de software médico) --}}
                                                <div class="d-flex flex-wrap gap-2 p-2 bg-dark">
                                                    <template x-for="(exam, examIdx) in group.exams"
                                                        :key="exam.id">
                                                        <div class="position-relative"
                                                            style="cursor:pointer;flex-shrink:0;"
                                                            @click="toggleExamSelection(exam.id)">

                                                            {{-- Badge lateralidade --}}
                                                            <span
                                                                class="position-absolute top-0 end-0 rounded-circle d-flex align-items-center justify-content-center text-white fw-bold"
                                                                :class="{
                                                                    'bg-primary': exam.laterality === 1,
                                                                    'bg-danger': exam.laterality === 2,
                                                                    'bg-secondary': exam.laterality !== 1 && exam
                                                                        .laterality !== 2
                                                                }"
                                                                style="width:22px;height:22px;font-size:.55rem;z-index:1;margin:3px;"
                                                                x-text="latLabel(exam.laterality)"></span>

                                                            {{-- Checkbox seleção --}}
                                                            <span
                                                                class="position-absolute bottom-0 start-0 d-flex align-items-center justify-content-center"
                                                                style="z-index:2;margin:3px;">
                                                                <span
                                                                    class="rounded d-flex align-items-center justify-content-center"
                                                                    :class="isSelected(exam.id) ? 'bg-primary' :
                                                                        'bg-dark border border-secondary'"
                                                                    style="width:16px;height:16px;">
                                                                    <i class="fa fa-check text-white"
                                                                        style="font-size:.5rem;"
                                                                        x-show="isSelected(exam.id)"></i>
                                                                </span>
                                                            </span>

                                                            {{-- Thumbnail com imagem --}}
                                                            <template x-if="examUrls[exam.id] && !brokenUrls[exam.id]">
                                                                <img :src="examUrls[exam.id]" :alt="exam.exam_type?.name"
                                                                    width="100" height="76"
                                                                    style="object-fit:cover;display:block;border-radius:4px;transition:outline .1s;"
                                                                    :style="isSelected(exam.id) ? 'outline:2px solid #6ea8fe;' :
                                                                        'outline:2px solid transparent;'"
                                                                    x-on:error="brokenUrls = {...brokenUrls, [exam.id]: true}">
                                                            </template>

                                                            {{-- Placeholder sem imagem ou quebrada --}}
                                                            <template x-if="!examUrls[exam.id] || brokenUrls[exam.id]">
                                                                <div class="d-flex align-items-center justify-content-center rounded"
                                                                    style="width:100px;height:76px;background:#3a3c42;border-radius:4px;transition:outline .1s;"
                                                                    :style="isSelected(exam.id) ? 'outline:2px solid #6ea8fe;' :
                                                                        'outline:2px solid transparent;'">
                                                                    <i class="ti ti-photo-off"
                                                                        style="font-size:1.4rem;color:#555;"></i>
                                                                </div>
                                                            </template>

                                                        </div>
                                                    </template>
                                                </div>

                                            </div>
                                        </template>

                                    </div>

                                </div>


                            </div>
                        </template>

                    </div>
                </div>
            </div>

        </div>

        {{-- ── Modal visualizador split-panel ─────────────────────────────────── --}}
        <div x-show="showViewerModal" x-cloak
            style="position:fixed;inset:0;z-index:9998;background:#0a0a0a;display:flex;flex-direction:column;overflow:hidden;"
            @keydown.escape.window="showViewerModal = false" @keydown.arrow-left.window="viewerPrev()"
            @keydown.arrow-right.window="viewerNext()">

            {{-- Toolbar --}}
            <div class="d-flex align-items-center gap-1 px-3 py-2 flex-shrink-0 flex-wrap"
                style="background:#111;border-bottom:1px solid #222;row-gap:4px;">

                {{-- Contagem de painéis: 1 2 3 4 --}}
                <div class="btn-group btn-group-sm" role="group">
                    <template x-for="n in [1,2,3,4]" :key="n">
                        <button type="button" class="btn fw-semibold"
                            :class="viewerPanelCount === n ? 'btn-primary' : 'btn-outline-secondary'"
                            style="min-width:26px;font-size:.72rem;" @click="setViewerPanelCount(n)"
                            x-text="n"></button>
                    </template>
                </div>

                <div class="vr opacity-25 mx-1"></div>

                {{-- All --}}
                <button type="button" class="btn btn-sm fw-semibold"
                    :class="viewerAllMode ? 'btn-info text-dark' : 'btn-outline-secondary'" style="font-size:.72rem;"
                    @click="viewerToggleAll()">All</button>

                <div class="vr opacity-25 mx-1"></div>

                {{-- Lens --}}
                <button type="button" class="btn btn-sm fw-semibold"
                    :class="viewerLensActive ? 'btn-warning text-dark' : 'btn-outline-secondary'"
                    style="font-size:.72rem;" @click="viewerLensActive = !viewerLensActive; viewerLensVisible = false">
                    <i class="fa fa-search-plus"></i> Lens
                </button>
                <template x-if="viewerLensActive">
                    <div class="d-flex align-items-center gap-1">
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1"
                            @click="viewerZoom = Math.max(1.5, viewerZoom - 0.5)">
                            <i class="fa fa-minus" style="font-size:.65rem;"></i></button>
                        <span style="color:#fff;font-size:.78rem;font-weight:600;min-width:48px;text-align:center;display:inline-block;"
                            x-text="viewerZoom.toFixed(1) + 'x'"></span>
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1"
                            @click="viewerZoom = Math.min(40, viewerZoom + 0.5)">
                            <i class="fa fa-plus" style="font-size:.65rem;"></i></button>
                    </div>
                </template>

                <div class="vr opacity-25 mx-1"></div>

                {{-- Fit <--> --}}
                <button type="button" class="btn btn-sm fw-semibold"
                    :class="viewerFitMode ? 'btn-light text-dark' : 'btn-outline-secondary'" style="font-size:.72rem;"
                    @click="viewerFitMode = !viewerFitMode" title="Ajustar à área de visualização">
                    <i class="fa fa-compress-arrows-alt me-1"></i>Fit
                </button>

                {{-- OD|OE --}}
                <button type="button" class="btn btn-sm fw-semibold"
                    :class="viewerSplitMode ? 'btn-info text-dark' : 'btn-outline-secondary'"
                    style="font-size:.72rem;" @click="viewerSplitOdOs()">OD|OE</button>

                {{-- Laser View --}}
                <button type="button" class="btn btn-sm fw-semibold"
                    :class="viewerLaserMode ? 'btn-success' : 'btn-outline-secondary'"
                    style="font-size:.72rem;" @click="toggleAllFlip()"
                    title="Inverter imagem verticalmente">
                    <i class="fa fa-undo me-1"></i>Laser
                </button>

                <div class="vr opacity-25 mx-1"></div>

                <div class="flex-grow-1"></div>

                {{-- Fechar --}}
                <button type="button" class="btn btn-sm btn-outline-danger" @click="showViewerModal = false">
                    <i class="fa fa-times"></i></button>

            </div>

            {{-- Corpo: painéis + faixas de thumbnails --}}
            <div
                :style="!viewerAllMode ? 'display:flex;flex-direction:column;flex-grow:1;overflow:hidden;min-height:0;' :
                    'display:none;'">

                {{-- Grade de painéis --}}
                <div x-show="!viewerAllMode" x-cloak class="flex-grow-1" :style="viewerPanelGridStyle">

                    <template x-for="pi in viewerPanelCount" :key="pi">
                        <div class="position-relative d-flex flex-column"
                            style="background:#111;border-radius:3px;min-height:0;cursor:pointer;overflow:hidden;"
                            :style="viewerActivePanel === (pi - 1) ? 'outline:2px solid #0d6efd;' : 'outline:1px solid #2a2a2a;'"
                            @click="viewerActivePanel = pi - 1">

                            {{-- Área da imagem --}}
                            <div class="flex-grow-1 position-relative d-flex"
                                :class="viewerFitMode ? 'align-items-start justify-content-center' :
                                    'align-items-center justify-content-center'"
                                :style="(viewerFitMode ? 'height:84vh;min-height:0;overflow-y:auto;overflow-x:hidden;' :
                                    'min-height:0;overflow:hidden;') + (
                                    viewerLensActive && viewerPanelUrls[pi - 1] && !viewerPanelBroken[pi - 1] ? 'cursor:none;' : '')"
                                @mousemove.stop="viewerActivePanel = pi-1; onViewerLensMove($event)"
                                @mouseleave.stop="viewerLensVisible = false"
                                @mouseenter.stop="viewerActivePanel = pi-1; if(viewerLensActive && viewerPanelUrls[pi-1] && !viewerPanelBroken[pi-1]) viewerLensVisible = true"
                                @wheel.prevent.stop="if(viewerLensActive) { viewerActivePanel = pi-1; const s = $event.deltaY < 0 ? 0.5 : -0.5; viewerZoom = Math.min(40, Math.max(1.5, viewerZoom + s)); }">

                                {{-- Spinner --}}
                                <div x-show="viewerPanelLoading[pi-1]" class="text-center text-white position-absolute"
                                    style="z-index:5;">
                                    <div class="spinner-border spinner-border-sm text-light" role="status"></div>
                                </div>

                                {{-- Painel vazio --}}
                                <div x-show="!viewerPanelExams[pi-1] && !viewerPanelLoading[pi-1]"
                                    class="text-center text-muted">
                                    <i class="ti ti-photo" style="font-size:2.5rem;opacity:.12;"></i>
                                    <p class="mt-1 mb-0" style="font-size:.65rem;opacity:.35;" x-text="'{{ __('eye_images.panel_prefix') }}' + pi">
                                    </p>
                                </div>

                                {{-- Exame sem imagem --}}
                                <div x-show="viewerPanelExams[pi-1] && !viewerPanelUrls[pi-1] && !viewerPanelLoading[pi-1] && !viewerPanelBroken[pi-1]"
                                    x-cloak class="text-center text-muted">
                                    <i class="ti ti-photo-off" style="font-size:2rem;opacity:.3;"></i>
                                    <p class="mt-1 mb-0" style="font-size:.65rem;">{{ __('eye_images.no_image') }}</p>
                                </div>

                                {{-- Arquivo não encontrado --}}
                                <div x-show="viewerPanelBroken[pi-1]" x-cloak class="text-center text-muted">
                                    <i class="ti ti-photo-off" style="font-size:2rem;opacity:.3;"></i>
                                    <p class="mt-1 mb-0" style="font-size:.65rem;">{{ __('eye_images.not_found') }}</p>
                                </div>

                                {{-- Imagem principal --}}
                                <img x-show="viewerPanelUrls[pi-1] && !viewerPanelLoading[pi-1] && !viewerPanelBroken[pi-1]"
                                    x-cloak :src="viewerPanelUrls[pi - 1] ?? ''" alt="Exame"
                                    :style="(viewerFitMode ?
                                        'width:100%;height:auto;max-width:100%;max-height:none;flex-shrink:0;' :
                                        'width:100%;height:84vh;object-fit:contain;') +
                                    'display:block;user-select:none;' + (viewerPanelFlipped[pi - 1] ?
                                        'transform:scaleY(-1);' : '')"
                                    x-on:load="setPanelLoaded(pi-1)" x-on:error="setPanelError(pi-1)">

                            </div>

                            {{-- Barra de info do painel --}}
                            <div class="d-flex align-items-center gap-1 px-2 flex-shrink-0"
                                style="background:#0d0d0d;font-size:.6rem;min-height:22px;border-top:1px solid #1a1a1a;">
                                <template x-if="viewerPanelExams[pi-1]">
                                    <span class="d-flex align-items-center gap-1 overflow-hidden w-100">
                                        <span class="badge flex-shrink-0"
                                            :class="{
                                                'bg-primary': viewerPanelExams[pi - 1].laterality === 1,
                                                'bg-danger': viewerPanelExams[pi - 1].laterality === 2,
                                                'bg-secondary': viewerPanelExams[pi - 1].laterality !== 1 &&
                                                    viewerPanelExams[pi - 1].laterality !== 2
                                            }"
                                            style="font-size:.5rem;"
                                            x-text="latLabel(viewerPanelExams[pi-1].laterality)"></span>
                                        <span class="text-secondary text-truncate"
                                            x-text="viewerPanelExams[pi-1].exam_type?.name ?? '—'"></span>
                                        <span class="text-secondary opacity-50 flex-shrink-0 ms-auto"
                                            x-text="formatDateFull(viewerPanelExams[pi-1].created_at?.substring(0,10))"></span>
                                    </span>
                                </template>
                                <template x-if="!viewerPanelExams[pi-1]">
                                    <span class="text-secondary" style="opacity:.3;" x-text="'{{ __('eye_images.panel_prefix') }}' + pi"></span>
                                </template>
                            </div>

                            {{-- Strip de thumbnails do painel (sticky no rodapé do painel) --}}
                            <div class="flex-shrink-0 d-flex align-items-center gap-1 overflow-x-auto overflow-y-hidden py-1 px-1"
                                style="background:#0d0d0d;height:80px;border-top:1px solid #222;"
                                :style="viewerActivePanel === (pi - 1) ? 'border-top-color:#0d6efd;' : ''" @click.stop>
                                <template x-for="exam in panelStripExams(pi-1)" :key="'tn-' + pi + '-' + exam.id">
                                    <div style="flex-shrink:0;cursor:pointer;"
                                        @click.stop="setPanelExam(pi-1, exam); viewerActivePanel = pi-1;">
                                        <div class="position-relative rounded overflow-hidden"
                                            style="width:64px;height:64px;"
                                            :style="viewerPanelExams[pi - 1]?.id === exam.id ? 'outline:2px solid #0d6efd;' :
                                                'outline:1px solid #2a2a2a;'">
                                            <span
                                                class="position-absolute top-0 end-0 rounded-circle d-flex align-items-center justify-content-center text-white fw-bold"
                                                :class="{
                                                    'bg-primary': exam.laterality === 1,
                                                    'bg-danger': exam.laterality === 2,
                                                    'bg-secondary': exam.laterality !== 1 && exam.laterality !== 2
                                                }"
                                                style="width:14px;height:14px;font-size:.4rem;z-index:1;margin:2px;"
                                                x-text="latLabel(exam.laterality)"></span>
                                            <template x-if="examUrls[exam.id] && !brokenUrls[exam.id]">
                                                <img :src="examUrls[exam.id]"
                                                    style="width:64px;height:64px;object-fit:cover;display:block;"
                                                    x-on:error="brokenUrls = {...brokenUrls, [exam.id]: true}">
                                            </template>
                                            <template x-if="!examUrls[exam.id] || brokenUrls[exam.id]">
                                                <div class="w-100 h-100 d-flex align-items-center justify-content-center"
                                                    style="background:#1a1a1a;">
                                                    <i class="ti ti-photo-off" style="color:#444;font-size:.9rem;"></i>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>

                        </div>
                    </template>

                </div>


            </div>

            {{-- Modo All: sibling do corpo, ocupa flex-grow-1 quando ativo --}}
            <div id="viewImages" class="ei-scroll"
                :style="viewerAllMode ? 'position:absolute;top:50px;left:0;right:0;bottom:0;overflow-y:auto;overflow-x:hidden;background:#0a0a0a;padding:4px;' : 'display:none;'">

                <div :style="`display:grid;grid-template-columns:repeat(${viewerSplitMode ? 2 : viewerPanelCount},1fr);gap:4px;`">
                    <template x-for="exam in allGridExams()" :key="'all-grid-' + exam.id">
                        <div class="position-relative" style="background:#111;border-radius:3px;overflow:hidden;display:flex;flex-direction:column;">
                            <div class="d-flex align-items-center gap-2 px-2 py-1"
                                style="background:#0d0d0d;border-bottom:1px solid #1a1a1a;font-size:.65rem;">
                                <span class="badge flex-shrink-0"
                                    :class="{
                                        'bg-primary': exam.laterality === 1,
                                        'bg-danger': exam.laterality === 2,
                                        'bg-secondary': exam.laterality !== 1 && exam.laterality !== 2
                                    }"
                                    style="font-size:.5rem;" x-text="latLabel(exam.laterality)"></span>
                                <span class="text-secondary text-truncate" x-text="exam.exam_type?.name ?? '—'"></span>
                                <span class="text-secondary opacity-50 flex-shrink-0 ms-auto"
                                    x-text="formatDateTime(exam.created_at)"></span>
                            </div>
                            <div class="position-relative"
                                :style="viewerLensActive && examUrls[exam.id] && !brokenUrls[exam.id] ? 'cursor:none;' : ''"
                                @mousemove.stop="if(viewerLensActive && examUrls[exam.id] && !brokenUrls[exam.id]) onAllLensMove($event, exam)"
                                @mouseleave.stop="if(viewerLensActive) viewerLensVisible = false"
                                @mouseenter.stop="if(viewerLensActive && examUrls[exam.id] && !brokenUrls[exam.id]) viewerLensVisible = true"
                                @wheel="if(viewerLensActive) { $event.preventDefault(); $event.stopPropagation(); const s = $event.deltaY < 0 ? 0.5 : -0.5; viewerZoom = Math.min(40, Math.max(1.5, viewerZoom + s)); }">
                                <template x-if="examUrls[exam.id] && !brokenUrls[exam.id]">
                                    <img :src="examUrls[exam.id]"
                                        :style="'width:100%;height:auto;display:block;user-select:none;' + (viewerLaserMode ? 'transform:scaleY(-1);' : '')"
                                        x-on:error="brokenUrls = {...brokenUrls, [exam.id]: true}">
                                </template>
                                <template x-if="!examUrls[exam.id] || brokenUrls[exam.id]">
                                    <div class="d-flex align-items-center justify-content-center"
                                        style="width:100%;aspect-ratio:4/3;background:#1a1a1a;">
                                        <i class="ti ti-photo-off" style="color:#444;font-size:2rem;"></i>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>

            </div>

            {{-- Lupa global do viewer --}}
            <div x-show="viewerLensActive && viewerLensVisible" x-cloak
                :style="viewerLensStyle"
                style="position:fixed;pointer-events:none;z-index:10000;"></div>

        </div>

        {{-- ── Modal de impressão ──────────────────────────────────────────────── --}}
        <div x-show="showPrintModal" x-cloak
            style="position:fixed;inset:0;z-index:9999;display:flex;flex-direction:column;"
            @keydown.escape.window="showPrintModal = false">

            {{-- Barra de ferramentas --}}
            <div class="d-flex align-items-center gap-1 px-3 py-2 flex-shrink-0" style="background:#2c2c2c;color:#fff;">

                {{-- Colunas por linha --}}
                <div class="btn-group btn-group-sm me-2" role="group">
                    <template x-for="n in [1,2,4,6,9,12,16]" :key="n">
                        <button type="button" class="btn btn-sm"
                            :class="printCols === n ? 'btn-light' : 'btn-outline-secondary'"
                            style="font-size:.72rem;min-width:28px;" @click="printCols = n" x-text="n"></button>
                    </template>
                </div>

                <div class="vr opacity-25 mx-1"></div>

                {{-- Orientação --}}
                <button type="button" class="btn btn-sm"
                    :class="printOrientation === 'portrait' ? 'btn-light' : 'btn-outline-secondary'"
                    style="font-size:.72rem;" @click="printOrientation = 'portrait'">
                    <i class="fa fa-file me-1"></i>{{ __('eye_images.portrait') }}
                </button>
                <button type="button" class="btn btn-sm"
                    :class="printOrientation === 'landscape' ? 'btn-light' : 'btn-outline-secondary'"
                    style="font-size:.72rem;" @click="printOrientation = 'landscape'">
                    <i class="fa fa-file me-1" style="transform:rotate(90deg);display:inline-block;"></i>{{ __('eye_images.landscape') }}
                </button>

                <div class="vr opacity-25 mx-1"></div>

                <button type="button" class="btn btn-sm btn-warning text-dark fw-semibold" style="font-size:.72rem;"
                    @click="printReport()">
                    <i class="fa fa-print me-1"></i>{{ __('eye_images.print_btn') }}
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" style="font-size:.72rem;"
                    @click="showPrintModal = false">
                    <i class="fa fa-times me-1"></i>{{ __('eye_images.close_btn') }}
                </button>

            </div>

            {{-- Área de preview --}}
            <div class="flex-grow-1 overflow-auto" style="background:#888;">
                <div id="ei-print-content" :class="printOrientation === 'landscape' ? 'ei-landscape' : 'ei-portrait'"
                    class="mx-auto my-3 bg-white shadow"
                    style="width:210mm;min-height:297mm;padding:12mm;box-sizing:border-box;">

                    {{-- Cabeçalho da clínica --}}
                    <div class="d-flex justify-content-between align-items-start mb-3 pb-2"
                        style="border-bottom:2px solid #1a6fc4;">
                        <div>
                            <div style="font-size:1.1rem;font-weight:700;color:#1a6fc4;" x-text="EI_ENTITY.name"></div>
                            <div style="font-size:.72rem;color:#555;" x-show="EI_ENTITY.address"
                                x-text="EI_ENTITY.address"></div>
                            <div style="font-size:.72rem;color:#555;" x-show="EI_ENTITY.email" x-text="EI_ENTITY.email">
                            </div>
                            <div style="font-size:.72rem;color:#555;" x-show="EI_ENTITY.telephone || EI_ENTITY.cellphone"
                                x-text="[EI_ENTITY.telephone, EI_ENTITY.cellphone].filter(Boolean).join(' | ')"></div>
                        </div>
                        <div class="text-end">
                            <div style="font-size:.72rem;color:#555;">{{ __('eye_images.report_date') }}</div>
                            <div style="font-size:.85rem;font-weight:600;"
                                x-text="new Date().toLocaleDateString('pt-BR')"></div>
                        </div>
                    </div>

                    {{-- Dados do paciente --}}
                    <div class="mb-3 p-2 rounded" style="background:#f0f4ff;font-size:.78rem;">
                        <strong x-text="selectedPatient?.person?.full_name"></strong>
                        <span class="ms-2 text-muted" x-text="selectedPatient?.code"></span>
                    </div>

                    {{-- Grade de imagens --}}
                    <div :style="`display:grid;grid-template-columns:repeat(${printCols},1fr);gap:8px;`">
                        <template x-for="exam in printExams" :key="exam.id">
                            <div style="break-inside:avoid;">
                                <div class="text-center mb-1" style="font-size:.65rem;color:#333;font-weight:600;"
                                    x-text="`${exam.exam_type?.name ?? 'Exame'} - ${latLabel(exam.laterality)} - ${formatDateTime(exam.created_at)}`">
                                </div>
                                <template x-if="examUrls[exam.id] && !brokenUrls[exam.id]">
                                    <img :src="examUrls[exam.id]"
                                        style="width:100%;height:auto;display:block;border:1px solid #ddd;"
                                        x-on:error="brokenUrls = {...brokenUrls, [exam.id]: true}">
                                </template>
                                <template x-if="!examUrls[exam.id] || brokenUrls[exam.id]">
                                    <div class="d-flex align-items-center justify-content-center"
                                        style="width:100%;aspect-ratio:4/3;background:#eee;border:1px solid #ddd;">
                                        <i class="ti ti-photo-off" style="font-size:2rem;color:#aaa;"></i>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>

                </div>
            </div>

        </div>
    @endsection

    @section('styles')
        <style>
            @media print {
                body * {
                    visibility: hidden !important;
                }

                #ei-print-content,
                #ei-print-content * {
                    visibility: visible !important;
                }

                #ei-print-content {
                    position: fixed !important;
                    left: 0 !important;
                    top: 0 !important;
                    width: 100% !important;
                    margin: 0 !important;
                    box-shadow: none !important;
                }
            }

            .ei-landscape {
                width: 297mm;
                min-height: 210mm;
            }

            /* Scrollbar dark do viewer modal */
            #viewImages::-webkit-scrollbar,
            .ei-scroll::-webkit-scrollbar {
                width: 10px;
                height: 10px;
                background-color: #222;
            }

            #viewImages::-webkit-scrollbar-track,
            .ei-scroll::-webkit-scrollbar-track {
                background-color: #222;
            }

            #viewImages::-webkit-scrollbar-thumb,
            .ei-scroll::-webkit-scrollbar-thumb {
                background-color: #555;
                border-radius: 5px;
            }

            #viewImages::-webkit-scrollbar-thumb:hover,
            .ei-scroll::-webkit-scrollbar-thumb:hover {
                background-color: #777;
            }

            #viewImages,
            .ei-scroll {
                scrollbar-width: thin;
                scrollbar-color: #555 #222;
            }
        </style>
    @endsection

    @section('javascript')
        <script>
            const EI_SEARCH_URL = '{{ route('panel.eye-images.search') }}';
            const EI_ENTITY = @js($entity ? ['name' => $entity->name, 'address' => $entity->address, 'telephone' => $entity->telephone, 'cellphone' => $entity->cellphone, 'email' => $entity->email] : []);

            function eyeImagesApp(initialPatients) {
                return {
                    patients: initialPatients ?? [],
                    selectedPatient: null,
                    examUrls: {},
                    brokenUrls: {},
                    urlsLoading: false,
                    search: '',
                    period: 'hoje',
                    laterality: '',
                    doctorId: '',
                    examTypeId: '',
                    examStatus: '',
                    showFilters: false,
                    loading: false,
                    selectedExamIds: [],
                    showPrintModal: false,
                    printExams: [],
                    printCols: 2,
                    printOrientation: 'portrait',
                    showViewerModal: false,
                    viewerExams: [],
                    viewerPanelCount: 1,
                    viewerActivePanel: 0,
                    viewerPanelExams: [null, null, null, null],
                    viewerPanelUrls: [null, null, null, null],
                    viewerPanelLoading: [false, false, false, false],
                    viewerPanelBroken: [false, false, false, false],
                    viewerPanelFlipped: [false, false, false, false],
                    viewerLaserMode: false,
                    viewerFitMode: false,
                    viewerAllMode: false,
                    viewerSplitMode: false,
                    viewerLensActive: false,
                    viewerLensVisible: false,
                    viewerLensX: 0,
                    viewerLensY: 0,
                    viewerZoom: 3,
                    _viewerW: 0,
                    _viewerH: 0,
                    _lensImgX: 0,
                    _lensImgY: 0,
                    _lensUrl: null,
                    _lensExamId: null,

                    // ── Computed ──────────────────────────────────────────────────────────

                    get availableExamTypes() {
                        const map = new Map();
                        for (const p of this.patients) {
                            for (const e of p.exams) {
                                if (e.exam_type && !map.has(e.exam_id)) {
                                    map.set(e.exam_id, {
                                        id: e.exam_id,
                                        name: e.exam_type.name
                                    });
                                }
                            }
                        }
                        return [...map.values()].sort((a, b) => (a.name ?? '').localeCompare(b.name ?? ''));
                    },

                    get filteredPatients() {
                        let list = this.patients;

                        const q = this.search.trim().toLowerCase();
                        if (q) {
                            list = list.filter(p =>
                                (p.person?.full_name ?? '').toLowerCase().includes(q) ||
                                (p.code ?? '').toLowerCase().includes(q)
                            );
                        }

                        if (this.laterality || this.examTypeId || this.examStatus) {
                            list = list.filter(p => p.exams.some(e => this.examMatchesFilters(e)));
                        }

                        return list;
                    },

                    get filteredExams() {
                        if (!this.selectedPatient) return [];
                        return this.selectedPatient.exams.filter(e => this.examMatchesFilters(e));
                    },

                    get groupedExams() {
                        const groups = [];
                        const seen = {};
                        for (const exam of this.filteredExams) {
                            const date = exam.created_at?.substring(0, 10) ?? 'unknown';
                            const equipId = exam.entity_integrator_equipment_id ?? '';
                            const typeId = exam.exam_id ?? '';
                            const key = `${date}|${equipId}|${typeId}`;
                            if (!seen[key]) {
                                seen[key] = {
                                    key,
                                    date,
                                    equipment: exam.equipment ?? null,
                                    examType: exam.exam_type ?? null,
                                    exams: []
                                };
                                groups.push(seen[key]);
                            }
                            seen[key].exams.push(exam);
                        }
                        return groups.sort((a, b) => b.date.localeCompare(a.date));
                    },

                    get selectedExamsData() {
                        if (!this.selectedPatient) return [];
                        return this.selectedPatient.exams.filter(e => this.selectedExamIds.includes(e.id));
                    },

                    get viewerCurrentExam() {
                        return this.viewerPanelExams[this.viewerActivePanel] ?? null;
                    },

                    get viewerPanelGridStyle() {
                        const base = 'display:grid;gap:2px;padding:2px;overflow:hidden;min-height:0;';
                        return base + `grid-template-columns:repeat(${this.viewerPanelCount},1fr);`;
                    },

                    get viewerActivePanelIndex() {
                        const exam = this.viewerPanelExams[this.viewerActivePanel];
                        if (!exam) return -1;
                        return this.viewerExams.findIndex(e => e.id === exam.id);
                    },

                    get viewerLensStyle() {
                        const url = this._lensUrl || this.viewerPanelUrls[this.viewerActivePanel];
                        if (!this.viewerLensVisible || !url) return 'display:none;';
                        const lensSize = 360;
                        const bW = this._viewerW * this.viewerZoom;
                        const bH = this._viewerH * this.viewerZoom;
                        const lookX = this._lensImgX ?? this.viewerLensX;
                        const lookY = this._lensImgY ?? this.viewerLensY;
                        const bX = -(lookX * this.viewerZoom - lensSize / 2);
                        const bY = -(lookY * this.viewerZoom - lensSize / 2);
                        return `position:absolute;left:${this.viewerLensX}px;top:${this.viewerLensY}px;` +
                            `width:${lensSize}px;height:${lensSize}px;` +
                            `border-radius:50%;border:2px solid rgba(255,255,255,0.8);` +
                            `transform:translate(-50%,-50%);pointer-events:none;` +
                            `background-image:url(${url});background-repeat:no-repeat;` +
                            `background-size:${bW}px ${bH}px;background-position:${bX}px ${bY}px;` +
                            `box-shadow:0 0 0 1px rgba(0,0,0,0.5);z-index:10;`;
                    },

                    // ── Helpers ───────────────────────────────────────────────────────────

                    examMatchesFilters(e) {
                        if (this.laterality) {
                            if (this.laterality === 'ao') {
                                if (e.laterality === 1 || e.laterality === 2) return false;
                            } else {
                                const target = this.laterality === 'od' ? 1 : 2;
                                if (e.laterality !== target) return false;
                            }
                        }
                        if (this.examTypeId && String(e.exam_id) !== String(this.examTypeId)) return false;
                        if (this.examStatus && this.deriveStatus(e) !== this.examStatus) return false;
                        return true;
                    },

                    deriveStatus(exam) {
                        if (exam.active === false || exam.active === 0) return 'cancelado';
                        if (!exam.archive) return 'solicitado';
                        return 'realizado';
                    },

                    statusLabel(exam) {
                        return {
                            solicitado: '{{ __('eye_images.status_requested') }}',
                            realizado:  '{{ __('eye_images.status_done') }}',
                            cancelado:  '{{ __('eye_images.status_cancelled') }}'
                        } [this.deriveStatus(exam)] ?? '—';
                    },

                    // ── Actions ───────────────────────────────────────────────────────────

                    groupLatMatching(group, lat) {
                        if (lat === 'all') return group.exams;
                        if (lat === 'od') return group.exams.filter(e => e.laterality === 1);
                        if (lat === 'oe') return group.exams.filter(e => e.laterality === 2);
                        return group.exams.filter(e => e.laterality !== 1 && e.laterality !== 2);
                    },

                    groupLatActive(group, lat) {
                        const matching = this.groupLatMatching(group, lat);
                        return matching.length > 0 && matching.every(e => this.selectedExamIds.includes(e.id));
                    },

                    selectExamByLaterality(group, lat) {
                        const matching = this.groupLatMatching(group, lat);
                        if (!matching.length) return;
                        const allSelected = matching.every(e => this.selectedExamIds.includes(e.id));
                        if (allSelected) {
                            this.selectedExamIds = this.selectedExamIds.filter(id => !matching.find(e => e.id === id));
                        } else {
                            const toAdd = matching.filter(e => !this.selectedExamIds.includes(e.id)).map(e => e.id);
                            this.selectedExamIds = [...this.selectedExamIds, ...toAdd];
                        }
                    },

                    toggleExamSelection(examId) {
                        const idx = this.selectedExamIds.indexOf(examId);
                        if (idx >= 0) this.selectedExamIds.splice(idx, 1);
                        else this.selectedExamIds.push(examId);
                    },

                    isSelected(examId) {
                        return this.selectedExamIds.includes(examId);
                    },

                    openPrintModal(exams, autoPrint = false) {
                        this.printExams = exams ?? [];
                        this.showPrintModal = true;
                        if (autoPrint) {
                            this.$nextTick(() => setTimeout(() => this.printReport(), 300));
                        }
                    },

                    printReport() {
                        window.print();
                    },

                    openViewerModal(exams, startIndex = 0) {
                        if (!exams || exams.length === 0) return;
                        this.viewerExams = exams;
                        this.viewerPanelExams = [null, null, null, null];
                        this.viewerPanelUrls = [null, null, null, null];
                        this.viewerPanelLoading = [false, false, false, false];
                        this.viewerPanelBroken = [false, false, false, false];
                        this.viewerPanelFlipped = [false, false, false, false];
                        this.viewerActivePanel = 0;
                        this.viewerAllMode = false;
                        this.viewerSplitMode = false;
                        this.viewerLaserMode = false;
                        this.viewerFitMode = false;
                        this.viewerLensActive = false;
                        this.viewerLensVisible = false;
                        this.showViewerModal = true;
                        this.setPanelExam(0, exams[startIndex]);
                    },

                    viewerGoTo(idx) {
                        const exam = this.viewerExams[idx];
                        if (!exam) return;
                        this.viewerLensVisible = false;
                        this.setPanelExam(this.viewerActivePanel, exam);
                    },

                    viewerNext() {
                        const idx = this.viewerActivePanelIndex;
                        if (idx >= 0 && idx < this.viewerExams.length - 1) this.viewerGoTo(idx + 1);
                    },

                    viewerPrev() {
                        const idx = this.viewerActivePanelIndex;
                        if (idx > 0) this.viewerGoTo(idx - 1);
                    },

                    setPanelExam(pi, exam) {
                        const exams = [...this.viewerPanelExams];
                        const urls = [...this.viewerPanelUrls];
                        const loading = [...this.viewerPanelLoading];
                        const broken = [...this.viewerPanelBroken];
                        exams[pi] = exam;
                        urls[pi] = null;
                        loading[pi] = false;
                        broken[pi] = false;
                        this.viewerPanelExams = exams;
                        this.viewerPanelUrls = urls;
                        this.viewerPanelLoading = loading;
                        this.viewerPanelBroken = broken;
                        this._loadPanelUrl(pi, exam);
                    },

                    _loadPanelUrl(pi, exam) {
                        if (!exam) return;
                        const url = this.examUrls[exam.id] ?? null;
                        if (!url) return;
                        const probe = new Image();
                        probe.src = url;
                        if (probe.complete && probe.naturalWidth > 0) {
                            const urls = [...this.viewerPanelUrls];
                            urls[pi] = url;
                            this.viewerPanelUrls = urls;
                        } else {
                            const loading = [...this.viewerPanelLoading];
                            loading[pi] = true;
                            this.viewerPanelLoading = loading;
                            const urls = [...this.viewerPanelUrls];
                            urls[pi] = url;
                            this.viewerPanelUrls = urls;
                        }
                    },

                    setPanelLoaded(pi) {
                        const loading = [...this.viewerPanelLoading];
                        loading[pi] = false;
                        this.viewerPanelLoading = loading;
                    },

                    setPanelError(pi) {
                        const loading = [...this.viewerPanelLoading];
                        const broken = [...this.viewerPanelBroken];
                        const urls = [...this.viewerPanelUrls];
                        loading[pi] = false;
                        broken[pi] = true;
                        urls[pi] = null;
                        this.viewerPanelLoading = loading;
                        this.viewerPanelBroken = broken;
                        this.viewerPanelUrls = urls;
                    },

                    setViewerPanelCount(n) {
                        this.viewerPanelCount = n;
                        this.viewerSplitMode = false;
                        for (let i = 0; i < n; i++) {
                            if (!this.viewerPanelExams[i] && this.viewerExams[i]) {
                                this.setPanelExam(i, this.viewerExams[i]);
                            }
                        }
                    },

                    viewerToggleAll() {
                        if (this.viewerAllMode) {
                            this.viewerAllMode = false;
                            this.viewerPanelExams = [null, null, null, null];
                            this.viewerPanelUrls = [null, null, null, null];
                            this.viewerPanelLoading = [false, false, false, false];
                            this.viewerPanelBroken = [false, false, false, false];
                            this.viewerPanelCount = 0;
                            this.$nextTick(() => {
                                if (this.viewerSplitMode) {
                                    this.viewerPanelCount = 2;
                                    const od = this.viewerExams.find(e => e.laterality === 1);
                                    const oe = this.viewerExams.find(e => e.laterality === 2);
                                    if (od) this.setPanelExam(0, od);
                                    if (oe) this.setPanelExam(1, oe);
                                } else {
                                    this.viewerPanelCount = 1;
                                    if (this.viewerExams[0]) this.setPanelExam(0, this.viewerExams[0]);
                                }
                            });
                        } else {
                            this.viewerAllMode = true;
                            this.viewerPanelExams = [null, null, null, null];
                            this.viewerPanelUrls = [null, null, null, null];
                            this.viewerPanelLoading = [false, false, false, false];
                            this.viewerPanelBroken = [false, false, false, false];
                        }
                    },

                    viewerSplitOdOs() {
                        if (this.viewerSplitMode) {
                            this.viewerSplitMode = false;
                            if (!this.viewerAllMode) {
                                this.viewerPanelCount = 1;
                                this.viewerPanelExams = [null, null, null, null];
                                this.viewerPanelUrls = [null, null, null, null];
                                this.viewerPanelLoading = [false, false, false, false];
                                this.viewerPanelBroken = [false, false, false, false];
                                if (this.viewerExams[0]) this.setPanelExam(0, this.viewerExams[0]);
                            }
                        } else {
                            this.viewerSplitMode = true;
                            if (!this.viewerAllMode) {
                                this.viewerPanelCount = 2;
                                const od = this.viewerExams.find(e => e.laterality === 1);
                                const oe = this.viewerExams.find(e => e.laterality === 2);
                                if (od) this.setPanelExam(0, od);
                                if (oe) this.setPanelExam(1, oe);
                            }
                        }
                    },

                    allGridExams() {
                        if (this.viewerSplitMode) {
                            return this.viewerExams.filter(e => e.laterality === 1 || e.laterality === 2);
                        }
                        return this.viewerExams;
                    },

                    panelStripExams(pi) {
                        const exam = this.viewerPanelExams[pi];
                        if (!exam) return this.viewerExams;
                        const lat = exam.laterality;
                        if (lat === 1 || lat === 2) {
                            return this.viewerExams.filter(e => e.laterality === lat);
                        }
                        return this.viewerExams;
                    },

                    toggleAllFlip() {
                        this.viewerLaserMode = !this.viewerLaserMode;
                        this.viewerPanelFlipped = [
                            this.viewerLaserMode,
                            this.viewerLaserMode,
                            this.viewerLaserMode,
                            this.viewerLaserMode,
                        ];
                    },

                    togglePanelFlip(pi) {
                        const flipped = [...this.viewerPanelFlipped];
                        flipped[pi] = !flipped[pi];
                        this.viewerPanelFlipped = flipped;
                    },

                    onAllLensMove(event, exam) {
                        if (!this.viewerLensActive) return;
                        const url = this.examUrls[exam.id];
                        if (!url) return;
                        const container = event.currentTarget;
                        const img = container.querySelector('img');
                        if (!img) {
                            this.viewerLensVisible = false;
                            return;
                        }
                        const imgRect = img.getBoundingClientRect();
                        const imgX = event.clientX - imgRect.left;
                        const imgY = event.clientY - imgRect.top;
                        if (imgX < 0 || imgY < 0 || imgX > imgRect.width || imgY > imgRect.height) {
                            this.viewerLensVisible = false;
                            return;
                        }
                        this.viewerLensX = event.clientX;
                        this.viewerLensY = event.clientY;
                        this._viewerW = imgRect.width;
                        this._viewerH = imgRect.height;
                        this._lensImgX = imgX;
                        this._lensImgY = imgY;
                        this._lensUrl = url;
                        this._lensExamId = exam.id;
                        this.viewerLensVisible = true;
                    },

                    onViewerLensMove(event) {
                        if (!this.viewerLensActive) return;
                        const url = this.viewerPanelUrls[this.viewerActivePanel];
                        if (!url) return;
                        const container = event.currentTarget;
                        const img = container.querySelector('img');
                        if (!img) {
                            this.viewerLensVisible = false;
                            return;
                        }
                        const imgRect = img.getBoundingClientRect();
                        const imgX = event.clientX - imgRect.left;
                        const imgY = event.clientY - imgRect.top;
                        if (imgX < 0 || imgY < 0 || imgX > imgRect.width || imgY > imgRect.height) {
                            this.viewerLensVisible = false;
                            return;
                        }
                        this.viewerLensX = event.clientX;
                        this.viewerLensY = event.clientY;
                        this._viewerW = imgRect.width;
                        this._viewerH = imgRect.height;
                        this._lensImgX = imgX;
                        this._lensImgY = imgY;
                        this._lensUrl = null;
                        this._lensExamId = null;
                        this.viewerLensVisible = true;
                    },

                    async selectPatient(patient) {
                        this.selectedPatient = patient;
                        this.examUrls = {};
                        this.brokenUrls = {};
                        this.selectedExamIds = [];
                        this.urlsLoading = true;
                        try {
                            const res = await fetch(`{{ route('panel.eye-images.patient-urls', '__ID__') }}`.replace(
                                '__ID__', patient.id), {
                                headers: {
                                    'X-Requested-With': 'XMLHttpRequest'
                                },
                            });
                            const data = await res.json();
                            this.examUrls = data.urls ?? {};
                        } catch {
                            this.examUrls = {};
                        } finally {
                            this.urlsLoading = false;
                        }
                    },

                    setDoctor(id) {
                        this.doctorId = id;
                        this.selectedPatient = null;
                        this.fetchPatients();
                    },

                    async changePeriod(period) {
                        this.period = period;
                        this.selectedPatient = null;
                        this.search = '';
                        await this.fetchPatients();
                    },

                    clearFilters() {
                        this.search = '';
                        this.laterality = '';
                        this.examTypeId = '';
                        this.examStatus = '';
                    },

                    async fetchPatients() {
                        this.loading = true;
                        try {
                            const params = new URLSearchParams({
                                period: this.period
                            });
                            if (this.doctorId) params.append('doctor_id', this.doctorId);
                            const res = await fetch(`${EI_SEARCH_URL}?${params}`, {
                                headers: {
                                    'X-Requested-With': 'XMLHttpRequest'
                                },
                            });
                            const data = await res.json();
                            this.patients = data.patients ?? [];
                        } catch (e) {
                            console.error('Erro ao buscar pacientes:', e);
                        } finally {
                            this.loading = false;
                        }
                    },

                    initials(name) {
                        if (!name) return '?';
                        const parts = name.trim().split(' ').filter(Boolean);
                        return parts.length >= 2 ?
                            (parts[0][0] + parts[parts.length - 1][0]).toUpperCase() :
                            parts[0][0].toUpperCase();
                    },

                    avatarColor(name) {
                        const palette = ['#3b82f6', '#8b5cf6', '#06b6d4', '#10b981', '#f59e0b', '#ef4444', '#ec4899',
                            '#6366f1'
                        ];
                        if (!name) return palette[0];
                        let h = 0;
                        for (const c of name) h = c.charCodeAt(0) + ((h << 5) - h);
                        return palette[Math.abs(h) % palette.length];
                    },

                    latLabel: (v) => ({
                        1: 'OD',
                        2: 'OE'
                    } [v] ?? 'AO'),

                    formatDateFull(ymd) {
                        if (!ymd || ymd === 'unknown') return '—';
                        const [y, m, d] = ymd.split('-');
                        return `${d}/${m}/${y}`;
                    },

                    formatDateShort(ymd) {
                        if (!ymd || ymd === 'unknown') return '—';
                        const [y, m, d] = ymd.split('-');
                        return `${d}/${m}/${y.slice(2)}`;
                    },

                    formatDateTime(dt) {
                        if (!dt) return '—';
                        const d = new Date(dt);
                        return d.toLocaleDateString('pt-BR') + ' ' + d.toLocaleTimeString('pt-BR', {
                            hour: '2-digit',
                            minute: '2-digit',
                            second: '2-digit'
                        });
                    },

                };
            }
        </script>
    @endsection
