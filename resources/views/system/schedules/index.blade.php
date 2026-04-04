@extends('layouts.app')

@push('styles')
@endpush

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')

    {{-- crudForm: gerencia o modal de criação/edição de agendamento --}}
    <div x-data="crudForm({
        storeUrl:  @js(route('panel.schedules.store')),
        updateUrl: @js(route('panel.schedules.index')),
        fields: {
            entity_id:           '',
            doctor_id:           '',
            patient_id:          '',
            full_name:           '',
            date_time:           '',
            telephone:           '',
            cellphone:           '',
            cellphone_whatsapp:  false,
            notes:               '',
            covenant_id:         '',
            visit_id:            '',
            situation:           1,
            resource_ids:        [],
            resources_available: [],
            waiting_list_id:     '',
            use_recurrence:      false,
            recurrence_type:     'weekly',
            recurrence_until:    ''
        },
        onSuccess: () => $dispatch('schedule-saved')
    })"
         @patient-selected="form.patient_id = $event.detail.id; form.full_name = $event.detail.full_name; form.cellphone = $event.detail.cellphone ?? ''; form.telephone = $event.detail.telephone ?? ''"
         @patient-cleared="form.patient_id = ''"
         @edit-schedule.window="loadAndEdit(
         @js(url('panel/schedules')) + '/' + $event.detail.id,
         @js(url('panel/schedules')) + '/' + $event.detail.id,
         'scheduleModal'
     )"
         x-on:slot-selected.window="form.date_time = $event.detail.datetime"
         x-on:resources-loaded="form.resource_ids = []; form.resources_available = $event.detail.resources"
         x-on:prefill-from-waiting.window="
             reset();
             form.doctor_id          = $event.detail.doctor_id           ?? '';
             form.patient_id         = $event.detail.patient_id          ?? '';
             form.full_name          = $event.detail.full_name           ?? '';
             form.telephone          = $event.detail.telephone           ?? '';
             form.cellphone          = $event.detail.cellphone           ?? '';
             form.cellphone_whatsapp = $event.detail.cellphone_whatsapp  ?? false;
             form.covenant_id        = $event.detail.covenant_id         ?? '';
             form.visit_id           = $event.detail.visit_id            ?? '';
             form.notes              = $event.detail.notes               ?? '';
             form.waiting_list_id    = $event.detail.id;
             $nextTick(() => bootstrap.Modal.getOrCreateInstance(document.getElementById('scheduleModal')).show())
         ">

        @include('system.schedules._form-modal')

        {{-- scheduleView: calendário e lista de agendamentos --}}
        <div x-data="scheduleView(
            @js(route('panel.schedules.ajaxlist')),
            @js(csrf_token()),
            @js(session('selected_entity_doctor_id', 'tudo'))
         )"
             x-init="init()"
             @schedule-saved.window="fetchList()"
             @waiting-list-saved.window="fetchWaiting(); showWaitingPanel = true">

            {{-- ── Toolbar ─────────────────────────────────────────────────────── --}}
            <div class="row mb-3 align-items-center g-2">

                {{-- Botão Novo + Lista de Espera (sempre à esquerda) --}}
                <div class="col-12 col-md-auto d-flex align-items-center gap-2">
                    <button type="button"
                            class="btn btn-primary btn-sm"
                            data-bs-toggle="modal"
                            data-bs-target="#scheduleModal">
                        <i class="fa fa-plus"></i> {{ __('actions.new') }}
                    </button>
                    @if(session()->get('selected_entity_user_rule') !== 'doctor')
                        <button type="button"
                                class="btn btn-warning btn-sm position-relative"
                                @click="toggleWaitingPanel()"
                                title="Lista de espera">
                            <i class="fas fa-hourglass-half me-1"></i> Lista de Espera
                            <span x-show="waitingCount > 0" x-cloak
                                  class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                                  x-text="waitingCount"></span>
                        </button>
                        <button type="button"
                                class="btn btn-outline-warning btn-sm"
                                data-bs-toggle="modal"
                                data-bs-target="#waitingListModal"
                                title="Adicionar à lista de espera">
                            <i class="fas fa-plus me-1"></i> Fila
                        </button>
                    @endif
                </div>

                {{-- Lado direito: busca (normal) ou controles de bulk (seleção) --}}
                <div class="col-12 col-md d-flex align-items-center gap-2 mt-2 mt-md-0 justify-content-md-end flex-wrap">

                    {{-- Modo normal: busca + botão Selecionar --}}
                    @if(session()->get('selected_entity_user_rule') !== 'doctor')
                        <button type="button"
                                class="btn btn-outline-white btn-sm flex-shrink-0"
                                x-show="! selectionMode"
                                @click="toggleSelectionMode()"
                                title="Selecionar agendamentos para ação em massa">
                            <i class="fas fa-check-square me-1"></i> Selecionar
                        </button>
                    @endif

                    {{-- Modo seleção: controles de bulk substituem a busca --}}
                    <div class="d-flex align-items-center gap-2 flex-wrap"
                         x-show="selectionMode"
                         x-cloak>
                        <button type="button"
                                class="btn btn-outline-white btn-sm"
                                @click="clearSelection()"
                                title="Sair do modo de seleção">
                            <i class="fas fa-times me-1"></i> Cancelar
                        </button>
                        <button type="button"
                                class="btn btn-outline-white btn-sm"
                                @click="toggleSelectAll()"
                                title="Selecionar / desselecionar todos">
                            <i class="fas me-1" :class="isAllSelected() ? 'fa-check-square' : 'fa-square'"></i>
                            Todos
                        </button>
                        <span class="text-muted small fw-semibold"
                              x-text="selectedIds.length + ' selecionado(s)'"></span>
                        <div class="vr"></div>
                        <button type="button"
                                id="btn-bulk-confirm"
                                class="btn btn-info btn-sm"
                                :disabled="selectedIds.length === 0"
                                title="Confirmar agendamentos selecionados">
                            <i class="fas fa-check-circle me-1"></i> Confirmar
                        </button>
                        <button type="button"
                                id="btn-bulk-noshow"
                                class="btn btn-warning btn-sm text-dark"
                                :disabled="selectedIds.length === 0"
                                title="Marcar como Faltou">
                            <i class="fas fa-user-times me-1"></i> Faltou
                        </button>
                        <button type="button"
                                id="btn-bulk-cancel"
                                class="btn btn-danger btn-sm"
                                :disabled="selectedIds.length === 0"
                                title="Cancelar agendamentos selecionados">
                            <i class="fas fa-ban me-1"></i> Cancelar
                        </button>
                        <button type="button"
                                id="btn-bulk-reschedule"
                                class="btn btn-secondary btn-sm"
                                :disabled="selectedIds.length === 0"
                                title="Alterar data dos agendamentos selecionados">
                            <i class="fas fa-calendar-alt me-1"></i> Alterar Data
                        </button>
                    </div>

                    {{-- Campo de busca (oculto no modo seleção) --}}
                    <div class="input-group input-group-sm flex-grow-1"
                         style="max-width: 320px;"
                         x-show="! selectionMode">
                        <span class="input-group-text"><i class="fa fa-search"></i></span>
                        <input type="text"
                               class="form-control"
                               placeholder="{{ __('actions.search') }}..."
                               x-model="search"
                               @input.debounce.400ms="fetchList()">
                        <button class="btn btn-outline-white rounded-start-pill" type="button"
                                x-show="search"
                                x-cloak
                                @click="search = ''; fetchList()">
                            <i class="fa fa-times"></i>
                        </button>
                    </div>

                </div>

            </div>

            {{-- ── Painel lateral + Grade principal ─────────────────────────── --}}
            <div class="row">

                {{-- ── Coluna lateral ──────────────────────────────────────────── --}}
                <div class="col-xs-12 col-sm-3 col-md-3 col-lg-3">
                    <div class="card panel-info">
                        <div class="card-body">

                            {{-- Calendário ---------------------------------------- --}}
                            <div class="schedule-calendar-wrap">
                                <div x-ref="calendarPicker"></div>
                            </div>

                            {{-- Médicos ------------------------------------------- --}}
                            @if(session()->get('selected_entity_user_rule') !== 'doctor')
                                @if(count($doctors))
                                    <h6 class="font-bold mt-5 text-uppercase">Médicos</h6>
                                    <hr>

                                    {{-- Todos os médicos --}}
                                    <div class="d-flex align-items-center mb-3">
                                        @php $fallback = asset('system/images/team.png'); @endphp
                                        <button type="button"
                                                class="btn p-0 border-0 bg-transparent"
                                                @click="setDoctor('tudo')"
                                                title="Selecionar todos os médicos">
                                            <img src="{{ $fallback }}"
                                                 alt="Todos"
                                                 width="48"
                                                 class="img-circle"
                                                 :style="doctor === 'tudo' ? 'border:2px solid #000000;' : 'border:1px solid #ccc;'">
                                        </button>
                                        <div class="ps-2 flex-grow-1">
                                            <button type="button"
                                                    class="btn btn-link p-0 text-decoration-none fw-bold text-dark d-block w-100 text-start"
                                                    @click="setDoctor('tudo')">
                                                <span class="d-block">TUDO</span>
                                                <small class="d-block text-muted fw-normal">Selecionar todos</small>
                                            </button>
                                        </div>
                                    </div>

                                    @foreach($doctors as $doc)
                                        @php
                                            $photoPath = 'system/images/users/' . $doc->user_id . '.jpg';
                                            $photoUrl  = file_exists(public_path($photoPath))
                                                ? asset($photoPath)
                                                : asset('system/images/team.png');
                                            $color     = $doc->color ?: '#6c757d';
                                        @endphp
                                        <div class="d-flex align-items-center mb-3">
                                            <button type="button"
                                                    class="btn p-0 border-0 bg-transparent"
                                                    @click="setDoctor('{{ $doc->id }}')"
                                                    title="{{ $doc->user_name }}">
                                                <img src="{{ $photoUrl }}"
                                                     alt="{{ $doc->user_name }}"
                                                     width="48"
                                                     class="img-circle"
                                                     :style="doctor === '{{ $doc->id }}' ? 'border:2px solid {{ $color }};' : 'border:1px solid {{ $color }};'">
                                            </button>
                                            <div class="ps-2 flex-grow-1">
                                                <button type="button"
                                                        class="btn btn-link p-0 text-decoration-none fw-bold d-block w-100 text-start"
                                                        style="color:{{ $color }}"
                                                        @click="setDoctor('{{ $doc->id }}')">
                                                    <span class="d-block">{{ $doc->user_name }}</span>
                                                    <small class="d-block fw-normal"
                                                           style="color:{{ $color }}">{{ $doc->record }}</small>
                                                </button>
                                            </div>
                                        </div>
                                    @endforeach
                                @endif
                            @endif

                            {{-- Turno — ícones no lugar do <select> --------------- --}}
                            <h6 class="font-bold mt-5 text-uppercase">Horário</h6>
                            <hr>
                            <div class="d-flex w-100 border rounded overflow-hidden" role="group" aria-label="Selecionar turno" style="border-color:#e2e8f0!important;">

                                {{-- TUDO --}}
                                <button type="button"
                                        class="flex-fill btn btn-sm rounded-0 text-center border-0 p-3"
                                        style="padding:.6rem .25rem .5rem;background:#fff;color:#6c757d;border-color:#e2e8f0!important;"
                                        :style="bout == 1 ? 'background:#6c757d;color:#fff;' : 'background:#fff;color:#6c757d;'"
                                        @click="setBout(1)"
                                        title="Exibir todos os horários">
                                    <i class="fas fa-th d-block" style="font-size:1.1rem;"></i>
                                    <span class="d-block" style="font-size:.6rem;font-weight:700;letter-spacing:.05em;margin-top:.2rem;">TUDO</span>
                                    <span class="d-block mt-1"
                                          :style="{ height: '4px', background: bout == 1 ? 'rgba(255,255,255,.5)' : '#6c757d' }"></span>
                                </button>

                                {{-- MANHÃ --}}
                                <button type="button"
                                        class="flex-fill btn btn-sm rounded-0 text-center border-0 p-3 border-start"
                                        style="padding:.6rem .25rem .5rem;background:#fff;color:#b07a10;border-color:#e2e8f0!important;"
                                        :style="bout == 2 ? 'background:#f5a623;color:#fff;' : 'background:#fff;color:#b07a10;'"
                                        @click="setBout(2)"
                                        title="Manhã">
                                    <i class="fas fa-sun d-block" style="font-size:1.1rem;"></i>
                                    <span class="d-block" style="font-size:.6rem;font-weight:700;letter-spacing:.05em;margin-top:.2rem;">MANHÃ</span>
                                    <span class="d-block mt-1"
                                          :style="{ height: '4px', background: bout == 2 ? 'rgba(255,255,255,.5)' : '#f5a623' }"></span>
                                </button>

                                {{-- TARDE --}}
                                <button type="button"
                                        class="flex-fill btn btn-sm rounded-0 text-center border-0 p-3 border-start"
                                        style="padding:.6rem .25rem .5rem;background:#fff;color:#1565c0;border-color:#e2e8f0!important;"
                                        :style="bout == 3 ? 'background:#1976d2;color:#fff;' : 'background:#fff;color:#1565c0;'"
                                        @click="setBout(3)"
                                        title="Tarde">
                                    <i class="fas fa-cloud-sun d-block" style="font-size:1.1rem;"></i>
                                    <span class="d-block" style="font-size:.6rem;font-weight:700;letter-spacing:.05em;margin-top:.2rem;">TARDE</span>
                                    <span class="d-block mt-1"
                                          :style="{ height: '4px', background: bout == 3 ? 'rgba(255,255,255,.5)' : '#1976d2' }"></span>
                                </button>

                                {{-- NOITE --}}
                                <button type="button"
                                        class="flex-fill btn btn-sm rounded-0 text-center border-0 p-3 border-start"
                                        style="padding:.6rem .25rem .5rem;background:#fff;color:#495057;border-color:#e2e8f0!important;"
                                        :style="bout == 4 ? 'background:#343a40;color:#fff;' : 'background:#fff;color:#495057;'"
                                        @click="setBout(4)"
                                        title="Noite">
                                    <i class="fas fa-moon d-block" style="font-size:1.1rem;"></i>
                                    <span class="d-block" style="font-size:.6rem;font-weight:700;letter-spacing:.05em;margin-top:.2rem;">NOITE</span>
                                    <span class="d-block mt-1"
                                          :style="{ height: '4px', background: bout == 4 ? 'rgba(255,255,255,.5)' : '#adb5bd' }"></span>
                                </button>

                            </div>

                        </div>{{-- /card-body --}}
                    </div>
                </div>{{-- /col lateral --}}

                {{-- ── Coluna principal ─────────────────────────────────────────── --}}
                <div class="col-xs-12 col-sm-9 col-md-9 col-lg-9">
                    <div class="card">
                        <h5 class="card-header">{{ $meta['action'] }}</h5>

                        <div class="card-body">

                            {{-- ── Mural de Recados ──────────────────────────────── --}}
                        @include('system.schedules._notices-panel')

                        {{-- ── Painel Lista de Espera (aparece antes da lista) ── --}}
                            <div x-show="showWaitingPanel" x-cloak class="mb-3 border border-warning rounded">
                                <div class="d-flex align-items-center justify-content-between px-3 py-2 bg-warning rounded-top">
                                    <span class="fw-semibold">
                                        <i class="fas fa-hourglass-half me-2"></i> Lista de Espera
                                        <span class="badge bg-dark ms-2" x-text="waitingCount"></span>
                                    </span>
                                    <div class="d-flex gap-2 align-items-center">
                                        <button type="button"
                                                class="btn btn-sm btn-dark"
                                                data-bs-toggle="modal"
                                                data-bs-target="#waitingListModal">
                                            <i class="fas fa-plus me-1"></i> Adicionar
                                        </button>
                                        <button type="button" class="btn-close" @click="toggleWaitingPanel()"></button>
                                    </div>
                                </div>

                                <template x-if="waitingEntries.length === 0">
                                    <p class="text-muted text-center py-3 mb-0 small">
                                        <i class="fas fa-check-circle me-1"></i> Nenhum paciente na lista de espera.
                                    </p>
                                </template>

                                <div class="list-group list-group-flush rounded-bottom">
                                    <template x-for="(entry, index) in waitingEntries" :key="entry.id">
                                        <div class="list-group-item px-2 py-2">
                                            <div class="d-flex align-items-center gap-2">

                                                <span class="badge bg-secondary rounded-pill flex-shrink-0"
                                                      x-text="index + 1"
                                                      style="min-width:1.6rem;"></span>

                                                <div class="d-flex flex-column flex-shrink-0" style="gap:0;">
                                                    <button type="button"
                                                            class="btn p-0 lh-1 border-0 text-muted"
                                                            style="font-size:.75rem;"
                                                            @click="moveWaiting(index, -1)"
                                                            :disabled="index === 0">
                                                        <i class="fas fa-caret-up"></i>
                                                    </button>
                                                    <button type="button"
                                                            class="btn p-0 lh-1 border-0 text-muted"
                                                            style="font-size:.75rem;"
                                                            @click="moveWaiting(index, 1)"
                                                            :disabled="index === waitingEntries.length - 1">
                                                        <i class="fas fa-caret-down"></i>
                                                    </button>
                                                </div>

                                                <div class="flex-grow-1 min-w-0">
                                                    <div class="fw-semibold text-truncate small" x-text="entry.full_name"></div>
                                                    <div class="text-muted" style="font-size:.75rem;">
                                                        <span x-text="entry.doctor_name"></span>
                                                        <template x-if="entry.covenant_name">
                                                            <span> &middot; <span x-text="entry.covenant_name"></span></span>
                                                        </template>
                                                        <template x-if="entry.visit_name">
                                                            <span> &middot; <span x-text="entry.visit_name"></span></span>
                                                        </template>
                                                    </div>
                                                    <div x-show="entry.preferred_date_from"
                                                         class="text-muted" style="font-size:.7rem;">
                                                        <i class="fas fa-calendar me-1"></i>
                                                        <span x-text="entry.preferred_date_from"></span>
                                                        <template x-if="entry.preferred_date_until">
                                                            <span> até <span x-text="entry.preferred_date_until"></span></span>
                                                        </template>
                                                    </div>
                                                    <template x-if="entry.notes">
                                                        <p class="mb-0 mt-1 text-muted fst-italic"
                                                           style="font-size:.7rem;"
                                                           x-text="entry.notes"></p>
                                                    </template>
                                                </div>

                                                <div class="d-flex gap-1 flex-shrink-0">
                                                    <button type="button"
                                                            class="btn btn-sm btn-info"
                                                            @click="scheduleFromWaiting(entry)"
                                                            title="Agendar este paciente">
                                                        <i class="fas fa-calendar-plus"></i>
                                                    </button>
                                                    <button type="button"
                                                            class="btn btn-sm btn-outline-danger"
                                                            @click="removeWaiting(entry.id)"
                                                            title="Remover da lista">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </div>

                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            {{-- Spinner ------------------------------------------- --}}
                            <div x-show="loading" x-cloak class="text-center py-4">
                                <div class="spinner-border text-info" role="status">
                                    <span class="visually-hidden">Carregando…</span>
                                </div>
                            </div>

                            {{-- Lista -------------------------------------------- --}}
                            <div x-show="!loading">
                                <div id="list-schedule"
                                     :class="{ 'selection-mode': selectionMode }">
                                    @include('system.schedules.list')
                                </div>
                            </div>

                        </div>{{-- /card-body --}}
                    </div>{{-- /card --}}
                </div>{{-- /col principal --}}

            </div>{{-- /row --}}

        </div>{{-- /scheduleView --}}

    </div>{{-- /crudForm --}}

@endsection

@section('modals')
    @include('components.modal_default')
    @include('system.schedules._reschedule-modal')
    @include('system.schedules._patient-modal')
    @include('system.schedules._waiting-list-modal')
@endsection

@section('javascript')
    @vite(['resources/js/system/schedules.js'])
    <script>
    window.schedulesLang = {
        rescheduleSuccess:  @js(__('actions.reschedule_success')),
        rescheduleErrorDate: @js(__('actions.reschedule_error_date')),
    };
    </script>
    <script>
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.copy-btn');
        if (!btn) return;

        var text = btn.dataset.copy;
        var icon = btn.querySelector('i');

        function showCheck() {
            icon.classList.replace('fa-copy', 'fa-check');
            setTimeout(function () { icon.classList.replace('fa-check', 'fa-copy'); }, 1500);
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(showCheck);
        } else {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.cssText = 'position:fixed;opacity:0';
            document.body.appendChild(ta);
            ta.focus();
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            showCheck();
        }
    });
    </script>
@endsection

@push('styles')
    <style>
        /* Checkboxes de seleção em massa — ocultos por padrão */
        .schedule-select-cb { display: none; }
        #list-schedule.selection-mode .schedule-select-cb { display: flex; align-items: center; }

        /* Card selecionado */
        #list-schedule.selection-mode .schedule-card { cursor: pointer; }
        #list-schedule.selection-mode .schedule-card:has(.schedule-checkbox:checked) {
            background-color: #e8f4ff !important;
            border-color: #0d6efd !important;
        }

        /* Flatpickr inline — ocupa toda a largura da coluna */
        .schedule-calendar-wrap .flatpickr-calendar {
            width: 100% !important;
            box-shadow: none;
            border: 1px solid #dee2e6;
            border-radius: .375rem;
        }

        .schedule-calendar-wrap .flatpickr-days,
        .schedule-calendar-wrap .dayContainer {
            width: 100% !important;
            min-width: 100% !important;
            max-width: 100% !important;
        }

        .schedule-calendar-wrap .flatpickr-day {
            max-width: none;
            flex-basis: 14.2857%;
        }
    </style>
