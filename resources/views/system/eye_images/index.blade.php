@extends('layouts.app')

@section('breadcrumb')
    {{-- Custom breadcrumb or hidden if needed --}}
    @include('components.breadcrumbs')
@endsection

@section('content')

<style>
    /* Clinical Top Bar Styles */
    .clinical-top-bar {
        position: sticky;
        top: 0;
        z-index: 1020;
        background: #f8f9fa;
        border-bottom: 2px solid #dee2e6;
        padding: 0.75rem 1rem;
        margin-left: -1.5rem;
        margin-right: -1.5rem;
        margin-top: -1.5rem;
        margin-bottom: 1.5rem;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }

    .clinical-search-input {
        border-radius: 20px 0 0 20px;
        border-right: none;
        padding-left: 1.25rem;
    }

    .clinical-search-btn {
        border-radius: 0 20px 20px 0;
        padding-right: 1.25rem;
    }

    .filter-pill {
        font-size: 0.75rem;
        font-weight: 600;
        padding: 0.25rem 0.75rem;
        border-radius: 15px;
        background: #fff;
        border: 1px solid #ced4da;
        color: #495057;
        cursor: pointer;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
    }

    .filter-pill:hover, .filter-pill.active {
        background: #0d6efd;
        color: #fff;
        border-color: #0d6efd;
    }

    .clinical-btn-group .btn {
        padding: 0.4rem 0.75rem;
        font-size: 0.85rem;
        font-weight: 500;
    }

    .indicator-badge {
        font-size: 0.7rem;
        padding: 0.2rem 0.5rem;
        border-radius: 10px;
    }

    .shortcut-hint {
        font-size: 0.65rem;
        color: #adb5bd;
        margin-left: 0.5rem;
    }

    /* Professional Background for the module */
    .eye-images-module {
        background: #e9ecef;
        min-height: calc(100vh - 150px);
        padding: 1rem;
        border-radius: 8px;
    }
</style>

<div x-data="eyeImagesViewer({
    doctors: @js($doctors),
    examTypes: @js($examTypes),
    categories: @js($categories)
})" class="eye-images-module">

    {{-- ── BARRA SUPERIOR CLÍNICA ────────────────────────────────────────── --}}
    <div class="clinical-top-bar d-flex flex-wrap align-items-center gap-3">
        
        {{-- Busca Global --}}
        <div class="flex-grow-1" style="min-width: 300px;">
            <div class="input-group">
                <input type="text" 
                       class="form-control clinical-search-input" 
                       placeholder="Buscar paciente, ID, exame, médico, diagnóstico..."
                       x-model="searchQuery"
                       @keydown.slash.window.prevent="$el.focus()"
                       @keydown.escape.window="clearSearch()"
                       @input.debounce.500ms="performSearch()">
                <button class="btn btn-primary clinical-search-btn" @click="performSearch()">
                    <i class="fa fa-search"></i>
                    <span class="shortcut-hint">[/]</span>
                </button>
            </div>
        </div>

        {{-- Filtros Rápidos --}}
        <div class="d-flex flex-wrap align-items-center gap-2">
            <div class="dropdown">
                <button class="filter-pill dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="fa fa-user-md"></i> Médico: <span x-text="selectedDoctorName || 'Todos'"></span>
                </button>
                <ul class="dropdown-menu shadow">
                    <li><a class="dropdown-item" href="#" @click.prevent="filterByDoctor(null)">Todos os Médicos</a></li>
                    <template x-for="doctor in doctors" :key="doctor.id">
                        <li><a class="dropdown-item" href="#" @click.prevent="filterByDoctor(doctor)" x-text="doctor.person.full_name"></a></li>
                    </template>
                </ul>
            </div>

            <div class="dropdown">
                <button class="filter-pill dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="fa fa-file-image-o"></i> Exame: <span x-text="selectedExamTypeName || 'Todos'"></span>
                </button>
                <ul class="dropdown-menu shadow" style="max-height: 300px; overflow-y: auto;">
                    <li><a class="dropdown-item" href="#" @click.prevent="filterByExamType(null)">Todos os Tipos</a></li>
                    <template x-for="type in examTypes" :key="type.id">
                        <li><a class="dropdown-item" href="#" @click.prevent="filterByExamType(type)" x-text="type.name"></a></li>
                    </template>
                </ul>
            </div>

            <div class="btn-group clinical-btn-group" role="group">
                <input type="radio" class="btn-check" name="laterality" id="lat_ao" value="null" x-model="filters.laterality" checked>
                <label class="btn btn-outline-secondary" for="lat_ao">AO</label>
                
                <input type="radio" class="btn-check" name="laterality" id="lat_od" value="1" x-model="filters.laterality">
                <label class="btn btn-outline-secondary" for="lat_od">OD</label>
                
                <input type="radio" class="btn-check" name="laterality" id="lat_oe" value="2" x-model="filters.laterality">
                <label class="btn btn-outline-secondary" for="lat_oe">OE</label>
            </div>

            <div class="dropdown">
                <button class="filter-pill dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="fa fa-calendar"></i> Período: <span x-text="filters.periodLabel || 'Hoje'"></span>
                </button>
                <ul class="dropdown-menu shadow">
                    <li><a class="dropdown-item" href="#" @click.prevent="setPeriod('today', 'Hoje')">Hoje</a></li>
                    <li><a class="dropdown-item" href="#" @click.prevent="setPeriod('7days', '7 dias')">Últimos 7 dias</a></li>
                    <li><a class="dropdown-item" href="#" @click.prevent="setPeriod('30days', '30 dias')">Últimos 30 dias</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="#">Personalizado...</a></li>
                </ul>
            </div>
        </div>

        {{-- Filtro por Perfil Clínico --}}
        <div class="d-flex align-items-center gap-2">
            <span class="small text-muted fw-bold text-uppercase">Perfil:</span>
            <div class="d-flex gap-1 overflow-auto pb-1" style="max-width: 400px;">
                <template x-for="(label, key) in categories" :key="key">
                    <button class="filter-pill" 
                            :class="{ 'active': filters.category == key }"
                            @click="toggleCategory(key)"
                            x-text="label">
                    </button>
                </template>
            </div>
        </div>

        {{-- Botões de Ação --}}
        <div class="ms-auto d-flex align-items-center gap-2">
            <div class="btn-group clinical-btn-group">
                <button class="btn btn-outline-primary" title="Comparar Exames" :disabled="selectedCount < 2">
                    <i class="fa fa-columns"></i>
                </button>
                <button class="btn btn-outline-primary" title="Criar Panorama">
                    <i class="fa fa-th-large"></i>
                </button>
                <button class="btn btn-outline-primary" title="Multi-seleção" @click="toggleMultiSelect()" :class="{ 'active': multiSelect }">
                    <i class="fa fa-check-square-o"></i>
                </button>
            </div>
            
            <div class="btn-group clinical-btn-group">
                <button class="btn btn-success" title="Upload Exame Externo">
                    <i class="fa fa-upload"></i>
                </button>
                <button class="btn btn-primary" title="Novo Exame">
                    <i class="fa fa-plus"></i>
                </button>
                <button class="btn btn-light" title="Atualizar Lista" @click="refresh()">
                    <i class="fa fa-refresh"></i>
                </button>
            </div>
        </div>
    </div>

    {{-- Indicadores --}}
    <div class="d-flex align-items-center gap-3 mb-3 px-2">
        <span class="badge bg-secondary indicator-badge">
            <span x-text="resultCount"></span> exames encontrados
        </span>
        <span class="badge bg-info indicator-badge">
            <span x-text="openImagesCount"></span> imagens abertas
        </span>
        <span class="badge bg-primary indicator-badge" x-show="selectedCount > 0">
            <span x-text="selectedCount"></span> selecionados
            <a href="#" class="text-white ms-1" @click.prevent="clearSelection()"><i class="fa fa-times-circle"></i></a>
        </span>
    </div>

    {{-- Área de Conteúdo (Placeholder para o visualizador/lista) --}}
    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm border-0" style="min-height: 400px;">
                <div class="card-body d-flex flex-column align-items-center justify-content-center text-muted">
                    <i class="fa fa-eye fa-4x mb-3 opacity-25"></i>
                    <h5>Módulo Eye Images</h5>
                    <p>Utilize a barra superior para pesquisar exames e pacientes.</p>
                </div>
            </div>
        </div>
    </div>

</div>

@endsection

@section('javascript')
<script>
    function eyeImagesViewer(config) {
        return {
            doctors: config.doctors,
            examTypes: config.examTypes,
            categories: config.categories,
            searchQuery: '',
            resultCount: 0,
            openImagesCount: 0,
            selectedCount: 0,
            multiSelect: false,
            filters: {
                doctor_id: null,
                exam_type_id: null,
                laterality: 'null',
                period: 'today',
                periodLabel: 'Hoje',
                category: null
            },
            selectedDoctorName: '',
            selectedExamTypeName: '',

            init() {
                console.log('Eye Images Viewer Initialized');
                // Adicionar listener para Fullscreen
                window.addEventListener('keydown', (e) => {
                    if (e.key === 'f' || e.key === 'F') {
                        this.toggleFullscreen();
                    }
                });
            },

            performSearch() {
                console.log('Searching for:', this.searchQuery, 'with filters:', this.filters);
                // Aqui seria a chamada AJAX para buscar os exames
                this.resultCount = Math.floor(Math.random() * 10); // Mock
            },

            clearSearch() {
                this.searchQuery = '';
                this.performSearch();
            },

            filterByDoctor(doctor) {
                this.filters.doctor_id = doctor ? doctor.id : null;
                this.selectedDoctorName = doctor ? doctor.person.full_name : '';
                this.performSearch();
            },

            filterByExamType(type) {
                this.filters.exam_type_id = type ? type.id : null;
                this.selectedExamTypeName = type ? type.name : '';
                this.performSearch();
            },

            setPeriod(period, label) {
                this.filters.period = period;
                this.filters.periodLabel = label;
                this.performSearch();
            },

            toggleCategory(key) {
                this.filters.category = (this.filters.category === key) ? null : key;
                this.performSearch();
            },

            toggleMultiSelect() {
                this.multiSelect = !this.multiSelect;
            },

            clearSelection() {
                this.selectedCount = 0;
            },

            refresh() {
                this.performSearch();
            },

            toggleFullscreen() {
                console.log('Toggling fullscreen mode...');
                // Implementação de fullscreen do viewer
            }
        }
    }
</script>
@endsection
