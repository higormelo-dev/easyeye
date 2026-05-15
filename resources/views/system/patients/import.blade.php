@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')

@php
    $previewImportId = session('import_preview_id');
    $startedImportId = session('import_started');

    // Import pendente de confirmação (carregado do banco ou flash)
    $previewImport = $previewImportId
        ? $imports->firstWhere('id', $previewImportId)
        : ($pendingImport?->confirmed_at === null ? $pendingImport : null);

    // Import que acabou de ser confirmado e está em progresso
    $activeImportId = $startedImportId ?? ($pendingImport?->confirmed_at !== null ? $pendingImport->id : null);

    $planUsed      = $planStatus->used;
    $planLimit     = $planStatus->isUnlimited ? null : $planStatus->limit;
    $planRemaining = $planStatus->isUnlimited ? null : $planStatus->remaining;
    $planFull      = ! $planStatus->isUnlimited && $planStatus->remaining <= 0;
@endphp

<div x-data="patientImporter(
    @js($activeImportId),
    @js(route('panel.patients.import.status', ':id')),
    @js(route('panel.patients.index'))
)">

    {{-- ══ Page Header ═══════════════════════════════════════════════════════ --}}
    <div class="d-flex align-items-sm-center flex-sm-row flex-column gap-2 pb-3 mb-4 border-1 border-bottom">
        <div class="flex-grow-1">
            <h4 class="fw-bold mb-1">{{ __('imports.patients.title') }}</h4>
            <p class="text-muted mb-0 fs-13">{{ __('imports.patients.subtitle') }}</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('panel.patients.import.template') }}"
               class="btn btn-outline-secondary btn-sm">
                <i class="ti ti-download me-1"></i>{{ __('imports.patients.download_template') }}
            </a>
            <a href="{{ route('panel.patients.index') }}" class="btn btn-light btn-sm">
                <i class="ti ti-arrow-left me-1"></i>{{ __('actions.back') }}
            </a>
        </div>
    </div>

    {{-- ══ Cota do Plano ══════════════════════════════════════════════════════ --}}
    @if($planFull)
    <div class="alert alert-danger d-flex align-items-center mb-4">
        <i class="ti ti-lock fs-4 me-3 flex-shrink-0"></i>
        <div>
            <strong>{{ __('imports.patients.plan_full') }}</strong>
        </div>
    </div>
    @elseif($planLimit !== null)
    <div class="alert alert-info d-flex align-items-center gap-3 mb-4 py-2">
        <i class="ti ti-users fs-5 flex-shrink-0"></i>
        <div class="d-flex gap-4 flex-wrap fs-13">
            <span>{{ __('imports.patients.plan_limit_title') }}:</span>
            <span><strong>{{ number_format($planUsed) }}</strong> / {{ number_format($planLimit) }} {{ strtolower(__('imports.patients.col_imported')) }}</span>
            <span class="text-success fw-semibold">{{ number_format($planRemaining) }} {{ __('imports.patients.plan_remaining', ['remaining' => '']) }}</span>
        </div>
    </div>
    @endif

    {{-- ══ ETAPA 1: Upload ════════════════════════════════════════════════════ --}}
    @if(! $previewImport && ! $activeImportId)
    <div class="row justify-content-center mb-4" x-show="!resultData">
        <div class="col-lg-8">

            @if($planFull)
            <div class="card shadow-sm opacity-50">
                <div class="card-body p-4 text-center py-5 text-muted">
                    <i class="ti ti-ban fs-1 d-block mb-2"></i>
                    <p class="mb-0">Faça upgrade do plano para importar mais pacientes.</p>
                </div>
            </div>
            @else
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h5 class="card-title fw-semibold mb-1">
                        <i class="ti ti-file-upload me-2 text-primary"></i>{{ __('imports.patients.upload_title') }}
                    </h5>
                    <p class="text-muted fs-13 mb-4">{{ __('imports.patients.upload_hint') }}</p>

                    <form method="POST"
                          action="{{ route('panel.patients.import.store') }}"
                          enctype="multipart/form-data"
                          x-ref="uploadForm">
                        @csrf

                        {{-- Drop zone --}}
                        <div class="border border-2 border-dashed rounded-3 p-5 text-center mb-3"
                             :class="dragOver ? 'border-primary bg-primary bg-opacity-10' : 'border-secondary'"
                             @dragover.prevent="dragOver = true"
                             @dragleave.prevent="dragOver = false"
                             @drop.prevent="handleDrop($event)"
                             @click="$refs.fileInput.click()"
                             style="cursor:pointer">

                            <template x-if="!selectedFile">
                                <div>
                                    <i class="ti ti-cloud-upload fs-1 text-muted d-block mb-2"></i>
                                    <p class="mb-1 fw-medium">{{ __('imports.patients.drop_here') }}</p>
                                    <p class="text-muted fs-12 mb-0">{{ __('imports.patients.accepted_formats') }}</p>
                                </div>
                            </template>

                            <template x-if="selectedFile">
                                <div>
                                    <i class="ti ti-file-type-csv fs-1 text-success d-block mb-2"></i>
                                    <p class="fw-medium mb-1" x-text="selectedFile.name"></p>
                                    <p class="text-muted fs-12 mb-0"
                                       x-text="formatBytes(selectedFile.size)"></p>
                                </div>
                            </template>
                        </div>

                        <input type="file"
                               name="file"
                               accept=".csv,.txt"
                               x-ref="fileInput"
                               class="d-none"
                               @change="handleFileSelect($event)">

                        @error('file')
                            <div class="alert alert-danger py-2 mb-3 fs-13">
                                <i class="ti ti-alert-circle me-1"></i>{{ $message }}
                            </div>
                        @enderror

                        <div class="d-flex align-items-center gap-3">
                            <button type="submit"
                                    class="btn btn-primary"
                                    :disabled="!selectedFile || uploading">
                                <span x-show="!uploading">
                                    <i class="ti ti-upload me-1"></i>{{ __('imports.patients.start_import') }}
                                </span>
                                <span x-show="uploading">
                                    <span class="spinner-border spinner-border-sm me-1" role="status"></span>
                                    {{ __('imports.patients.uploading') }}
                                </span>
                            </button>
                            <span class="text-muted fs-12">{{ __('imports.patients.max_size') }}</span>
                        </div>
                    </form>
                </div>
            </div>

            {{-- Instrução DE-PARA --}}
            <div class="card mt-3 border-0 bg-light">
                <div class="card-body p-3">
                    <h6 class="fw-semibold mb-2 fs-13">
                        <i class="ti ti-info-circle me-1 text-primary"></i>{{ __('imports.patients.column_guide_title') }}
                    </h6>
                    <div class="row g-2 fs-12 text-muted">
                        <div class="col-sm-6">
                            <strong>{{ __('imports.patients.required_columns') }}</strong>
                            <ul class="mb-0 ps-3 mt-1">
                                <li><code>nome</code> — {{ __('imports.patients.col_name') }}</li>
                                <li><code>celular</code> <em>{{ __('imports.patients.or') }}</em> <code>telefone</code></li>
                            </ul>
                        </div>
                        <div class="col-sm-6">
                            <strong>{{ __('imports.patients.optional_columns') }}</strong>
                            <ul class="mb-0 ps-3 mt-1">
                                <li><code>cpf</code>, <code>data_nascimento</code>, <code>email</code></li>
                                <li><code>sexo</code> (M/F), <code>estado_civil</code> (1–8)</li>
                                <li><code>convenio</code>, <code>carteirinha</code></li>
                                <li><code>cep</code>, <code>cidade</code>, <code>estado</code></li>
                            </ul>
                        </div>
                    </div>
                    <p class="mt-2 mb-0 fs-12 text-muted">
                        {{ __('imports.patients.dedup_hint') }}
                    </p>
                </div>
            </div>
            @endif
        </div>
    </div>
    @endif

    {{-- ══ ETAPA 2: Preview de Mapeamento ════════════════════════════════════ --}}
    @if($previewImport)
    @php $preview = $previewImport->preview ?? [] @endphp
    <div class="row justify-content-center mb-4">
        <div class="col-lg-10">

            {{-- Cabeçalho da etapa --}}
            <div class="card shadow-sm border-primary border-opacity-50">
                <div class="card-header bg-primary bg-opacity-10 border-primary border-opacity-25 d-flex align-items-center gap-2">
                    <i class="ti ti-table-check text-primary fs-5"></i>
                    <div class="flex-grow-1">
                        <h6 class="mb-0 fw-semibold">{{ __('imports.patients.preview_title') }}</h6>
                        <p class="mb-0 fs-12 text-muted">{{ __('imports.patients.preview_subtitle') }}</p>
                    </div>
                    <span class="badge bg-primary-subtle border border-primary text-primary fs-12">
                        {{ __('imports.patients.preview_rows', ['count' => number_format($preview['total_rows'] ?? 0)]) }}
                    </span>
                </div>
                <div class="card-body p-4">

                    {{-- Alertas de colunas obrigatórias --}}
                    @if(! empty($preview['missing_required']))
                    <div class="alert alert-danger mb-4">
                        <h6 class="alert-heading fw-semibold mb-1">
                            <i class="ti ti-alert-triangle me-1"></i>{{ __('imports.patients.preview_missing') }}
                        </h6>
                        <ul class="mb-0 ps-3">
                            @foreach($preview['missing_required'] as $missing)
                                <li class="fs-13">{{ $missing }}</li>
                            @endforeach
                        </ul>
                    </div>
                    @else
                    <div class="alert alert-success py-2 mb-4 d-flex align-items-center gap-2 fs-13">
                        <i class="ti ti-circle-check fs-5"></i>
                        {{ __('imports.patients.preview_required_ok') }} —
                        {{ number_format($preview['total_rows'] ?? 0) }} {{ __('imports.patients.rows') }} prontos para importar.
                    </div>
                    @endif

                    {{-- Colunas mapeadas --}}
                    @if(! empty($preview['mapped_columns']))
                    <h6 class="fw-semibold mb-2 fs-13">
                        <i class="ti ti-check text-success me-1"></i>{{ __('imports.patients.preview_mapped') }}
                    </h6>
                    <div class="table-responsive mb-4">
                        <table class="table table-sm table-bordered fs-13 mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Coluna no CSV</th>
                                    <th>Campo no sistema</th>
                                    <th class="text-center" style="width:80px">Req.</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($preview['mapped_columns'] as $col)
                                <tr>
                                    <td><code>{{ $col['csv_header'] }}</code></td>
                                    <td>{{ $col['label'] }}</td>
                                    <td class="text-center">
                                        @if($col['required'])
                                            <i class="ti ti-asterisk text-danger fs-11" title="Obrigatório"></i>
                                        @endif
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @endif

                    {{-- Colunas ignoradas --}}
                    @if(! empty($preview['unmapped_columns']))
                    <div class="mb-4">
                        <h6 class="fw-semibold mb-1 fs-13 text-muted">
                            <i class="ti ti-minus me-1"></i>{{ __('imports.patients.preview_unmapped') }}
                        </h6>
                        <p class="fs-12 text-muted mb-2">{{ __('imports.patients.preview_unmapped_hint') }}</p>
                        <div class="d-flex flex-wrap gap-1">
                            @foreach($preview['unmapped_columns'] as $col)
                                <span class="badge bg-secondary-subtle border border-secondary text-secondary fs-11">{{ $col }}</span>
                            @endforeach
                        </div>
                    </div>
                    @endif

                    {{-- Amostra de dados --}}
                    @if(! empty($preview['sample_rows']))
                    <h6 class="fw-semibold mb-2 fs-13">
                        <i class="ti ti-eye me-1 text-muted"></i>{{ __('imports.patients.preview_sample') }}
                    </h6>
                    <div class="table-responsive mb-4">
                        <table class="table table-sm table-striped table-bordered fs-12 mb-0">
                            <thead class="table-light">
                                <tr>
                                    @foreach(array_keys($preview['sample_rows'][0] ?? []) as $label)
                                        <th>{{ $label }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($preview['sample_rows'] as $row)
                                <tr>
                                    @foreach($row as $value)
                                        <td class="{{ blank($value) ? 'text-muted fst-italic' : '' }}">
                                            {{ blank($value) ? '—' : $value }}
                                        </td>
                                    @endforeach
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @endif

                    {{-- Ações --}}
                    <div class="d-flex gap-2 pt-2 border-top">
                        @if(empty($preview['missing_required']))
                        <form method="POST" action="{{ route('panel.patients.import.confirm', $previewImport) }}">
                            @csrf
                            <button type="submit" class="btn btn-primary">
                                <i class="ti ti-player-play me-1"></i>{{ __('imports.patients.confirm_import') }}
                            </button>
                        </form>
                        @endif

                        <form method="POST" action="{{ route('panel.patients.import.cancel', $previewImport) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-outline-secondary">
                                <i class="ti ti-x me-1"></i>{{ __('imports.patients.cancel_import') }}
                            </button>
                        </form>
                    </div>

                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- ══ ETAPA 3: Em Progresso (polling) ═══════════════════════════════════ --}}
    <template x-if="activeImport && !activeImport.is_done">
        <div class="row justify-content-center mb-4">
            <div class="col-lg-8">
                <div class="card shadow-sm border-primary border-opacity-50">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center mb-3">
                            <span class="spinner-border spinner-border-sm text-primary me-2" role="status"></span>
                            <h6 class="fw-semibold mb-0">{{ __('imports.patients.processing') }}</h6>
                        </div>

                        <div class="progress mb-2" style="height:8px">
                            <div class="progress-bar progress-bar-striped progress-bar-animated"
                                 role="progressbar"
                                 :style="'width:' + activeImport.progress + '%'"
                                 :aria-valuenow="activeImport.progress"
                                 aria-valuemin="0" aria-valuemax="100"></div>
                        </div>

                        <div class="d-flex justify-content-between text-muted fs-12">
                            <span x-text="activeImport.processed_rows + ' / ' + activeImport.total_rows + ' {{ __('imports.patients.rows') }}'"></span>
                            <span x-text="activeImport.progress + '%'"></span>
                        </div>

                        <div class="d-flex gap-3 mt-3 fs-13">
                            <span class="text-success">
                                <i class="ti ti-check me-1"></i>
                                <span x-text="activeImport.imported_rows"></span> {{ __('imports.patients.result_imported') }}
                            </span>
                            <span class="text-muted">
                                <i class="ti ti-minus me-1"></i>
                                <span x-text="activeImport.skipped_rows"></span> {{ __('imports.patients.result_skipped') }}
                            </span>
                            <span :class="activeImport.error_rows > 0 ? 'text-danger' : 'text-muted'">
                                <i class="ti ti-alert-circle me-1"></i>
                                <span x-text="activeImport.error_rows"></span> {{ __('imports.patients.result_errors') }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </template>

    {{-- ══ ETAPA 4: Resultado Final (em-tela, sem reload) ═════════════════════ --}}
    <template x-if="resultData">
        <div class="row justify-content-center mb-4">
            <div class="col-lg-8">
                <div class="card shadow-sm"
                     :class="resultData.error_rows > 0 ? 'border-warning' : 'border-success border-opacity-50'">
                    <div class="card-header d-flex align-items-center gap-2"
                         :class="resultData.error_rows > 0 ? 'bg-warning bg-opacity-10' : 'bg-success bg-opacity-10'">
                        <i class="ti ti-circle-check fs-5"
                           :class="resultData.error_rows > 0 ? 'text-warning' : 'text-success'"></i>
                        <h6 class="mb-0 fw-semibold">{{ __('imports.patients.result_title') }}</h6>
                    </div>
                    <div class="card-body p-4">

                        {{-- Números grandes --}}
                        <div class="row g-3 mb-4 text-center">
                            <div class="col-4">
                                <div class="fs-2 fw-bold text-success" x-text="resultData.imported_rows"></div>
                                <div class="fs-13 text-muted">{{ __('imports.patients.result_imported') }}</div>
                            </div>
                            <div class="col-4">
                                <div class="fs-2 fw-bold text-secondary" x-text="resultData.skipped_rows"></div>
                                <div class="fs-13 text-muted">{{ __('imports.patients.result_skipped') }}</div>
                            </div>
                            <div class="col-4">
                                <div class="fs-2 fw-bold"
                                     :class="resultData.error_rows > 0 ? 'text-danger' : 'text-secondary'"
                                     x-text="resultData.error_rows"></div>
                                <div class="fs-13 text-muted">{{ __('imports.patients.result_errors') }}</div>
                            </div>
                        </div>

                        {{-- Aviso de interrupção --}}
                        <template x-if="resultData.abort_reason">
                            <div class="alert alert-warning py-2 fs-13 mb-3">
                                <i class="ti ti-info-circle me-1"></i>
                                <strong>{{ __('imports.patients.result_aborted') }}</strong>
                                <span x-text="resultData.abort_reason"></span>
                            </div>
                        </template>

                        {{-- Ações pós-resultado --}}
                        <div class="d-flex flex-wrap gap-2">
                            <a :href="patientsUrl" class="btn btn-primary">
                                <i class="ti ti-users me-1"></i>{{ __('imports.patients.result_view_patients') }}
                            </a>
                            <template x-if="resultData.has_errors_file">
                                <a :href="resultData.errors_url" class="btn btn-outline-danger">
                                    <i class="ti ti-download me-1"></i>{{ __('imports.patients.result_download_errors') }}
                                </a>
                            </template>
                            <button @click="resetToUpload()" class="btn btn-outline-secondary">
                                <i class="ti ti-upload me-1"></i>{{ __('imports.patients.result_new_import') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </template>

    {{-- ══ Histórico de Importações ════════════════════════════════════════════ --}}
    @if($imports->where('confirmed_at', '!=', null)->isNotEmpty() || $imports->whereIn('status', ['done', 'failed'])->isNotEmpty())
    <div class="row justify-content-center" x-show="!resultData">
        <div class="col-lg-10">
            <div class="card shadow-sm">
                <div class="card-header d-flex align-items-center bg-white">
                    <h6 class="mb-0 fw-semibold">
                        <i class="ti ti-history me-2 text-muted"></i>{{ __('imports.patients.history_title') }}
                    </h6>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 fs-13">
                        <thead class="table-light">
                            <tr>
                                <th>{{ __('imports.patients.col_file') }}</th>
                                <th>{{ __('imports.patients.col_user') }}</th>
                                <th>{{ __('imports.patients.col_date') }}</th>
                                <th class="text-center">{{ __('imports.patients.col_status') }}</th>
                                <th class="text-center">{{ __('imports.patients.col_imported') }}</th>
                                <th class="text-center">{{ __('imports.patients.col_skipped') }}</th>
                                <th class="text-center">{{ __('imports.patients.col_errors') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($imports->filter(fn ($i) => $i->confirmed_at !== null || in_array($i->status->value, ['done', 'failed'])) as $import)
                            <tr x-data="importRow(
                                    @js($import->id),
                                    @js($import->status->value),
                                    @js(route('panel.patients.import.status', $import)),
                                    @js($import->imported_rows),
                                    @js($import->skipped_rows),
                                    @js($import->error_rows),
                                    @js($import->errors_file_path !== null),
                                    @js($import->errors_file_path !== null ? route('panel.patients.import.errors', $import) : null),
                                    @js($import->abort_reason)
                                )">
                                <td>
                                    <span class="fw-medium text-truncate d-block" style="max-width:200px"
                                          title="{{ $import->original_name }}">
                                        {{ $import->original_name }}
                                    </span>
                                    <span class="text-muted fs-12">
                                        {{ $import->total_rows }} {{ __('imports.patients.rows') }}
                                    </span>
                                </td>
                                <td>{{ $import->user?->name ?? '—' }}</td>
                                <td>
                                    <span title="{{ $import->created_at->format('d/m/Y H:i:s') }}">
                                        {{ $import->created_at->diffForHumans() }}
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="badge"
                                          :class="'bg-' + statusColor + '-subtle border border-' + statusColor + ' text-' + statusColor"
                                          x-text="statusLabel"></span>

                                    <template x-if="!isDone">
                                        <div class="progress mt-1" style="height:4px;min-width:80px">
                                            <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary"
                                                 role="progressbar"
                                                 :style="'width:' + progress + '%'"></div>
                                        </div>
                                    </template>
                                </td>
                                <td class="text-center fw-semibold text-success" x-text="importedRows"></td>
                                <td class="text-center text-muted" x-text="skippedRows"></td>
                                <td class="text-center">
                                    <span :class="errorRows > 0 ? 'text-danger fw-semibold' : 'text-muted'"
                                          x-text="errorRows"></span>
                                </td>
                                <td class="text-end">
                                    <template x-if="hasErrorsFile">
                                        <a :href="errorsUrl"
                                           class="btn btn-outline-danger btn-sm"
                                           title="{{ __('imports.patients.download_errors') }}">
                                            <i class="ti ti-download fs-13"></i>
                                        </a>
                                    </template>
                                    <template x-if="abortReason">
                                        <span class="badge bg-warning-subtle border border-warning text-warning ms-1"
                                              :title="abortReason">
                                            <i class="ti ti-info-circle fs-11"></i>
                                        </span>
                                    </template>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    @endif

</div>
@endsection

@push('scripts')
<script>
document.addEventListener('alpine:init', () => {

    Alpine.data('patientImporter', (activeImportId, statusUrlTemplate, patientsUrl) => ({
        dragOver:     false,
        selectedFile: null,
        uploading:    false,
        activeImport: null,
        resultData:   null,
        pollInterval: null,
        patientsUrl,

        init() {
            this.$refs.uploadForm?.addEventListener('submit', () => {
                this.uploading = true;
            });

            if (activeImportId) {
                this.startPolling(activeImportId, statusUrlTemplate);
            }
        },

        handleDrop(event) {
            this.dragOver = false;
            const file = event.dataTransfer.files[0];
            if (file && (file.name.endsWith('.csv') || file.name.endsWith('.txt'))) {
                this.selectedFile = file;
                const dt = new DataTransfer();
                dt.items.add(file);
                if (this.$refs.fileInput) this.$refs.fileInput.files = dt.files;
            }
        },

        handleFileSelect(event) {
            this.selectedFile = event.target.files[0] ?? null;
        },

        formatBytes(bytes) {
            if (bytes < 1024)    return bytes + ' B';
            if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / 1048576).toFixed(1) + ' MB';
        },

        startPolling(importId, template) {
            const url  = template.replace(':id', importId);
            const poll = async () => {
                try {
                    const res  = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    const data = await res.json();
                    this.activeImport = data;

                    if (data.is_done) {
                        clearInterval(this.pollInterval);
                        // Mostra resultado em-tela sem reload de página
                        this.resultData   = data;
                        this.activeImport = null;
                    }
                } catch {
                    clearInterval(this.pollInterval);
                }
            };
            poll();
            this.pollInterval = setInterval(poll, 3000);
        },

        resetToUpload() {
            this.resultData   = null;
            this.selectedFile = null;
        },

        destroy() {
            if (this.pollInterval) clearInterval(this.pollInterval);
        },
    }));

    Alpine.data('importRow', (id, initialStatus, statusUrl, importedRows, skippedRows, errorRows, hasErrorsFile, errorsUrl, abortReason) => ({
        status:       initialStatus,
        statusLabel:  '',
        statusColor:  '',
        progress:     0,
        isDone:       ['done', 'failed'].includes(initialStatus),
        importedRows,
        skippedRows,
        errorRows,
        hasErrorsFile,
        errorsUrl,
        abortReason,
        pollInterval: null,

        init() {
            this.updateStatus(initialStatus);
            if (!this.isDone) {
                this.startPolling();
            }
        },

        updateStatus(statusValue) {
            const labels = @js([
                'pending'    => __('imports.status.pending'),
                'processing' => __('imports.status.processing'),
                'done'       => __('imports.status.done'),
                'failed'     => __('imports.status.failed'),
            ]);
            const colors = { pending: 'secondary', processing: 'primary', done: 'success', failed: 'danger' };
            this.status      = statusValue;
            this.statusLabel = labels[statusValue] ?? statusValue;
            this.statusColor = colors[statusValue] ?? 'secondary';
        },

        startPolling() {
            const poll = async () => {
                try {
                    const res  = await fetch(statusUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    const data = await res.json();
                    this.updateStatus(data.status);
                    this.progress      = data.progress;
                    this.importedRows  = data.imported_rows;
                    this.skippedRows   = data.skipped_rows;
                    this.errorRows     = data.error_rows;
                    this.hasErrorsFile = data.has_errors_file;
                    this.errorsUrl     = data.errors_url;
                    this.abortReason   = data.abort_reason;
                    if (data.is_done) {
                        this.isDone = true;
                        clearInterval(this.pollInterval);
                    }
                } catch {
                    clearInterval(this.pollInterval);
                }
            };
            poll();
            this.pollInterval = setInterval(poll, 3000);
        },

        destroy() {
            if (this.pollInterval) clearInterval(this.pollInterval);
        },
    }));
});
</script>
@endpush
