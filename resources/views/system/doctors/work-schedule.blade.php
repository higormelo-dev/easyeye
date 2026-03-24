@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')

@php
    $syncUrl        = url("panel/doctors/{$doctor->id}/work-schedule");
    $storeBlockUrl  = url("panel/doctors/{$doctor->id}/blocks");
    $destroyBlockBase = url("panel/doctors/{$doctor->id}/blocks");
    $doctorName     = $doctor->entityUser->user->name ?? '—';
    $doctorRecord   = $doctor->record ?? '—';
@endphp

<div x-data="workScheduleManager(
        @js($days),
        @js($interval),
        @js($syncUrl),
        @js(csrf_token())
    )"
     x-init="init()">

    {{-- ══════════════════════════════════════════════════════════
         Cabeçalho do médico
    ══════════════════════════════════════════════════════════ --}}
    <div class="row mb-3">
        <div class="col-12">
            <div class="card card-body py-3">
                <div class="d-flex align-items-center gap-3">
                    @php
                        $userId    = $doctor->entityUser->user_id ?? null;
                        $photoPath = 'system/images/users/' . $userId . '.jpg';
                        $photoUrl  = $userId && file_exists(public_path($photoPath))
                            ? asset($photoPath)
                            : asset('system/images/team.png');
                        $color     = $doctor->color ?: '#6c757d';
                    @endphp
                    <img src="{{ $photoUrl }}" alt="{{ $doctorName }}"
                         class="rounded-circle flex-shrink-0"
                         width="56" height="56"
                         style="object-fit:cover;border:3px solid {{ $color }};">
                    <div>
                        <h5 class="mb-0 fw-bold" style="color:{{ $color }};">{{ $doctorName }}</h5>
                        <small class="text-muted">CRM {{ $doctorRecord }} &mdash; {{ $doctor->code }}</small>
                    </div>
                    <div class="ms-auto">
                        <a href="{{ route('panel.doctors.index') }}" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i> Voltar
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">

        {{-- ══════════════════════════════════════════════════════════
             Coluna esquerda — Horários de atendimento
        ══════════════════════════════════════════════════════════ --}}
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span><i class="fas fa-clock me-2 text-info"></i>Horários de Atendimento</span>
                    <span class="badge bg-secondary" x-text="activeDaysCount() + ' dia(s) ativo(s)'"></span>
                </div>
                <div class="card-body">

                    {{-- Intervalo entre consultas --}}
                    <div class="mb-4 p-3 bg-light rounded">
                        <label class="form-label fw-semibold mb-1">
                            <i class="fas fa-stopwatch me-1 text-secondary"></i>
                            Intervalo entre consultas
                        </label>
                        <div class="d-flex align-items-center gap-2" style="max-width:280px;">
                            <input type="number"
                                   class="form-control form-control-sm"
                                   x-model.number="interval"
                                   min="5" max="120" step="5"
                                   placeholder="Ex: 15">
                            <span class="text-muted small flex-shrink-0">minutos</span>
                        </div>
                        @php $entityInterval = $doctor->entityUser->entity->schedule_interval ?? 15; @endphp
                        <small class="text-muted d-block mt-1">
                            Padrão da clínica: {{ $entityInterval }} min.
                            Deixe em branco para usar o padrão.
                        </small>
                    </div>

                    {{-- Grade de dias --}}
                    <template x-for="(day, dayIndex) in days" :key="day.day">
                        <div class="border rounded mb-2 overflow-hidden">

                            {{-- Cabeçalho do dia --}}
                            <div class="d-flex align-items-center px-3 py-2"
                                 :class="day.active ? 'bg-info bg-opacity-10' : 'bg-light'">
                                <div class="form-check form-switch mb-0 flex-grow-1">
                                    <input class="form-check-input"
                                           type="checkbox"
                                           :id="'day-toggle-' + day.day"
                                           x-model="day.active">
                                    <label class="form-check-label fw-semibold"
                                           :for="'day-toggle-' + day.day"
                                           x-text="day.name"
                                           :class="day.active ? 'text-dark' : 'text-muted'">
                                    </label>
                                </div>
                                <button type="button"
                                        class="btn btn-sm btn-outline-info"
                                        x-show="day.active"
                                        x-cloak
                                        @click="addRange(dayIndex)"
                                        title="Adicionar faixa de horário">
                                    <i class="fas fa-plus me-1"></i> Faixa
                                </button>
                            </div>

                            {{-- Faixas de horário --}}
                            <div x-show="day.active" x-cloak class="px-3 pb-3 pt-2">
                                <template x-for="(range, rangeIndex) in day.ranges" :key="rangeIndex">
                                    <div class="d-flex align-items-center gap-2 mb-2">
                                        <span class="text-muted small flex-shrink-0" style="width:30px;"
                                              x-text="rangeIndex === 0 ? 'De' : 'De'"></span>
                                        <input type="time"
                                               class="form-control form-control-sm"
                                               style="max-width:120px;"
                                               x-model="range.starts_at">
                                        <span class="text-muted small flex-shrink-0">até</span>
                                        <input type="time"
                                               class="form-control form-control-sm"
                                               style="max-width:120px;"
                                               x-model="range.ends_at">
                                        <button type="button"
                                                class="btn btn-sm btn-outline-danger"
                                                x-show="day.ranges.length > 1"
                                                @click="removeRange(dayIndex, rangeIndex)"
                                                title="Remover faixa">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </template>

                                <div x-show="day.ranges.length === 0" class="text-muted small">
                                    Nenhuma faixa de horário. Clique em "+ Faixa" para adicionar.
                                </div>
                            </div>

                            {{-- Dia inativo --}}
                            <div x-show="!day.active" class="px-3 pb-2 pt-1">
                                <small class="text-muted">Médico não atende neste dia.</small>
                            </div>

                        </div>
                    </template>

                </div>
                <div class="card-footer d-flex align-items-center gap-2">
                    <button type="button"
                            class="btn btn-info"
                            @click="save()"
                            :disabled="saving">
                        <span x-show="saving" class="spinner-border spinner-border-sm me-1"></span>
                        <i x-show="!saving" class="far fa-save me-1"></i>
                        Salvar Escala
                    </button>
                    <span x-show="saveSuccess"
                          x-cloak
                          x-transition
                          class="text-success small">
                        <i class="fas fa-check-circle me-1"></i> Salvo com sucesso.
                    </span>
                    <span x-show="saveError"
                          x-cloak
                          x-transition
                          class="text-danger small"
                          x-text="saveError">
                    </span>
                </div>
            </div>
        </div>

        {{-- ══════════════════════════════════════════════════════════
             Coluna direita — Bloqueios / Ausências
        ══════════════════════════════════════════════════════════ --}}
        <div class="col-lg-4">
            <div class="card"
                 x-data="scheduleBlockManager(
                     @js($storeBlockUrl),
                     @js($destroyBlockBase),
                     @js(csrf_token()),
                     @js($blocks->map(fn($b) => [
                         'id'         => $b->id,
                         'starts_at'  => $b->starts_at->format('d/m/Y H:i'),
                         'ends_at'    => $b->ends_at->format('d/m/Y H:i'),
                         'reason'     => $b->reason,
                         'type_label' => $b->typeLabel(),
                     ])->values()->toArray())
                 )">

                <div class="card-header d-flex align-items-center justify-content-between">
                    <span><i class="fas fa-ban me-2 text-danger"></i>Bloqueios / Ausências</span>
                    <button type="button"
                            class="btn btn-sm btn-outline-danger"
                            @click="showForm = !showForm">
                        <i :class="showForm ? 'fas fa-times' : 'fas fa-plus'"></i>
                    </button>
                </div>

                {{-- Formulário de novo bloqueio --}}
                <div x-show="showForm" x-cloak class="card-body border-bottom pb-3">
                    <div class="row g-2">
                        <div class="col-12">
                            <label class="form-label small mb-1">Tipo</label>
                            <select class="form-select form-select-sm" x-model="form.type">
                                <option value="absence">Ausência</option>
                                <option value="holiday">Feriado</option>
                                <option value="meeting">Reunião / Compromisso</option>
                                <option value="other">Outro</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small mb-1">Início <span class="text-danger">*</span></label>
                            <input type="datetime-local"
                                   class="form-control form-control-sm"
                                   :class="{ 'is-invalid': formErrors.starts_at }"
                                   x-model="form.starts_at">
                            <div class="invalid-feedback" x-text="formErrors.starts_at?.[0]"></div>
                        </div>
                        <div class="col-12">
                            <label class="form-label small mb-1">Fim <span class="text-danger">*</span></label>
                            <input type="datetime-local"
                                   class="form-control form-control-sm"
                                   :class="{ 'is-invalid': formErrors.ends_at }"
                                   x-model="form.ends_at">
                            <div class="invalid-feedback" x-text="formErrors.ends_at?.[0]"></div>
                        </div>
                        <div class="col-12">
                            <label class="form-label small mb-1">Motivo</label>
                            <input type="text"
                                   class="form-control form-control-sm"
                                   x-model="form.reason"
                                   placeholder="Opcional...">
                        </div>
                        <div class="col-12 d-flex gap-2">
                            <button type="button"
                                    class="btn btn-sm btn-danger w-100"
                                    @click="storeBlock()"
                                    :disabled="storing">
                                <span x-show="storing" class="spinner-border spinner-border-sm me-1"></span>
                                Adicionar Bloqueio
                            </button>
                        </div>
                    </div>
                </div>

                {{-- Lista de bloqueios --}}
                <div class="card-body p-0">
                    <template x-if="blocks.length === 0">
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-check-circle fa-2x mb-2 text-success"></i>
                            <p class="small mb-0">Nenhum bloqueio futuro.</p>
                        </div>
                    </template>

                    <template x-for="block in blocks" :key="block.id">
                        <div class="d-flex align-items-start gap-2 px-3 py-2 border-bottom">
                            <div class="flex-grow-1">
                                <div class="fw-semibold small" x-text="block.type_label"></div>
                                <div class="text-muted" style="font-size:.8rem;">
                                    <i class="fas fa-calendar-alt me-1"></i>
                                    <span x-text="block.starts_at"></span>
                                    <span class="mx-1">→</span>
                                    <span x-text="block.ends_at"></span>
                                </div>
                                <div class="text-secondary" style="font-size:.8rem;" x-show="block.reason" x-text="block.reason"></div>
                            </div>
                            <button type="button"
                                    class="btn btn-sm btn-outline-danger flex-shrink-0"
                                    @click="destroyBlock(block.id)"
                                    title="Remover bloqueio">
                                <i class="fas fa-trash fa-xs"></i>
                            </button>
                        </div>
                    </template>
                </div>

            </div>
        </div>

    </div>{{-- /row --}}
</div>{{-- /x-data workScheduleManager --}}

@endsection

@section('javascript')
<script>
function workScheduleManager(initialDays, initialInterval, syncUrl, csrf) {
    return {
        days:        JSON.parse(JSON.stringify(initialDays)),
        interval:    initialInterval,
        saving:      false,
        saveSuccess: false,
        saveError:   '',

        init() {},

        activeDaysCount() {
            return this.days.filter(d => d.active).length;
        },

        addRange(dayIndex) {
            this.days[dayIndex].ranges.push({ starts_at: '08:00', ends_at: '12:00' });
        },

        removeRange(dayIndex, rangeIndex) {
            this.days[dayIndex].ranges.splice(rangeIndex, 1);
        },

        async save() {
            this.saving     = true;
            this.saveSuccess = false;
            this.saveError   = '';

            try {
                const res = await fetch(syncUrl, {
                    method: 'PUT',
                    headers: {
                        'Content-Type':     'application/json',
                        'X-CSRF-TOKEN':     csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept':           'application/json',
                    },
                    body: JSON.stringify({
                        schedule_interval: this.interval || null,
                        days:              this.days,
                    }),
                });

                const data = await res.json();

                if (! res.ok) {
                    this.saveError = data.message ?? 'Erro ao salvar.';
                    return;
                }

                this.saveSuccess = true;
                if (window.showSuccessToast) showSuccessToast(data.message);
                setTimeout(() => { this.saveSuccess = false; }, 3000);
            } catch (e) {
                this.saveError = 'Erro de conexão.';
            } finally {
                this.saving = false;
            }
        },
    };
}

function scheduleBlockManager(storeUrl, destroyBase, csrf, initialBlocks) {
    return {
        blocks:     initialBlocks,
        showForm:   false,
        storing:    false,
        formErrors: {},
        form: {
            type:      'absence',
            starts_at: '',
            ends_at:   '',
            reason:    '',
        },

        async storeBlock() {
            this.storing    = true;
            this.formErrors = {};

            try {
                const res = await fetch(storeUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type':     'application/json',
                        'X-CSRF-TOKEN':     csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept':           'application/json',
                    },
                    body: JSON.stringify(this.form),
                });

                const data = await res.json();

                if (res.status === 422) {
                    this.formErrors = data.errors ?? {};
                    return;
                }

                if (! res.ok) {
                    if (window.showErrorToast) showErrorToast(data.message ?? 'Erro ao criar bloqueio.');
                    return;
                }

                this.blocks.push(data.data);
                this.blocks.sort((a, b) => a.starts_at.localeCompare(b.starts_at));
                this.form      = { type: 'absence', starts_at: '', ends_at: '', reason: '' };
                this.showForm  = false;
                if (window.showSuccessToast) showSuccessToast(data.message);
            } catch (e) {
                if (window.showErrorToast) showErrorToast('Erro de conexão.');
            } finally {
                this.storing = false;
            }
        },

        async destroyBlock(blockId) {
            const confirmed = await Swal.fire({
                title:              'Remover bloqueio?',
                text:               'Esta ação não pode ser desfeita.',
                icon:               'warning',
                showCancelButton:   true,
                confirmButtonColor: '#dc3545',
                confirmButtonText:  'Remover',
                cancelButtonText:   'Cancelar',
            });

            if (! confirmed.isConfirmed) return;

            try {
                const res = await fetch(destroyBase + '/' + blockId, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN':     csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept':           'application/json',
                    },
                });

                const data = await res.json();

                if (! res.ok) {
                    if (window.showErrorToast) showErrorToast(data.message ?? 'Erro ao remover.');
                    return;
                }

                this.blocks = this.blocks.filter(b => b.id !== blockId);
                if (window.showSuccessToast) showSuccessToast(data.message);
            } catch (e) {
                if (window.showErrorToast) showErrorToast('Erro de conexão.');
            }
        },
    };
}
</script>
@endsection
