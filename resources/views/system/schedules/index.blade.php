@extends('layouts.app')

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
            covenant_id:         '',
            visit_id:            '',
            situation:           1
        },
        onSuccess: () => $dispatch('schedule-saved')
    })"
         @patient-selected="form.patient_id = $event.detail.id; form.full_name = $event.detail.full_name; form.cellphone = $event.detail.cellphone ?? ''; form.telephone = $event.detail.telephone ?? ''"
         @patient-cleared="form.patient_id = ''"
         @edit-schedule.window="loadAndEdit(
         @js(url('panel/schedules')) + '/' + $event.detail.id,
         @js(url('panel/schedules')) + '/' + $event.detail.id,
         'scheduleModal'
     )">

        @include('system.schedules._form-modal')

        {{-- scheduleView: calendário e lista de agendamentos --}}
        <div x-data="scheduleView(
            @js(route('panel.schedules.ajaxlist')),
            @js(csrf_token()),
            @js(session('selected_entity_doctor_id', 'tudo'))
         )"
             x-init="init()"
             @schedule-saved.window="fetchList()">

            {{-- ── Toolbar ─────────────────────────────────────────────────────── --}}
            <div class="row mb-3 align-items-center">
                <div class="col-12 col-md-auto">
                    <button type="button"
                            class="btn btn-info btn-sm"
                            data-bs-toggle="modal"
                            data-bs-target="#scheduleModal">
                        <i class="fa fa-plus"></i> {{ __('actions.new') }}
                    </button>
                </div>
                <div class="col-12 col-md d-flex align-items-center gap-2 mt-2 mt-md-0 justify-content-md-end">
                    <div class="input-group input-group-sm flex-grow-1" style="max-width: 320px;">
                        <span class="input-group-text"><i class="fa fa-search"></i></span>
                        <input type="text"
                               class="form-control"
                               placeholder="{{ __('actions.search') }}..."
                               x-model="search"
                               @input.debounce.400ms="fetchList()">
                        <button class="btn btn-outline-secondary" type="button"
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
                            <div class="btn-group w-100" role="group" aria-label="Selecionar turno">

                                <button type="button"
                                        class="btn btn-sm"
                                        :class="bout == 1 ? 'btn-secondary' : 'btn-outline-secondary'"
                                        @click="setBout(1)"
                                        title="Exibir todos os horários">
                                    <i class="fa fa-calendar"></i>
                                    <span class="d-block" style="font-size:.65rem;">TUDO</span>
                                </button>

                                <button type="button"
                                        class="btn btn-sm"
                                        :class="bout == 2 ? 'btn-warning' : 'btn-outline-warning'"
                                        @click="setBout(2)"
                                        title="Manhã">
                                    <i class="fa fa-sun-o"></i>
                                    <span class="d-block" style="font-size:.65rem;">MANHÃ</span>
                                </button>

                                <button type="button"
                                        class="btn btn-sm"
                                        :class="bout == 3 ? 'btn-primary' : 'btn-outline-primary'"
                                        @click="setBout(3)"
                                        title="Tarde">
                                    <i class="fa fa-moon-o"></i>
                                    <span class="d-block" style="font-size:.65rem;">TARDE</span>
                                </button>

                                <button type="button"
                                        class="btn btn-sm"
                                        :class="bout == 4 ? 'btn-dark' : 'btn-outline-dark'"
                                        @click="setBout(4)"
                                        title="Noite">
                                    <i class="fa fa-moon-o"></i>
                                    <span class="d-block" style="font-size:.65rem;">NOITE</span>
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

                            {{-- Spinner ------------------------------------------- --}}
                            <div x-show="loading" x-cloak class="text-center py-4">
                                <div class="spinner-border text-info" role="status">
                                    <span class="visually-hidden">Carregando…</span>
                                </div>
                            </div>

                            {{-- Lista -------------------------------------------- --}}
                            <div x-show="!loading">
                                <div id="list-schedule">
                                    @include('system.schedules.list')
                                </div>
                            </div>

                        </div>
                    </div>
                </div>{{-- /col principal --}}

            </div>{{-- /row --}}

        </div>{{-- /scheduleView --}}

    </div>{{-- /crudForm --}}

@endsection

@section('modals')
    @include('components.modal_default')
@endsection

@section('javascript')
    @vite(['resources/js/system/schedules.js'])
@endsection

@push('styles')
    <style>
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