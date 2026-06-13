<script setup>
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import SearchSelect from '@/Components/Panel/SearchSelect.vue';

const props = defineProps({
    doctor:         { type: Object, required: true },
    days:           { type: Array,  required: true },
    interval:       { type: [Number, String], default: null },
    entityInterval: { type: Number, default: 15 },
    blocks:         { type: Array,  default: () => [] },
    urls:           { type: Object, required: true },
    t:              { type: Object, default: () => ({}) },
});

const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

// ─────────────────────────────────────────────────────────────────────────────
// Estado reativo (clone profundo dos dados iniciais para não mutar props)
// ─────────────────────────────────────────────────────────────────────────────
const localDays      = ref(JSON.parse(JSON.stringify(props.days)));
const localInterval  = ref(props.interval ?? null);
const localBlocks    = ref([...props.blocks]);

// ── Horários ────────────────────────────────────────────────────────────────
const saving      = ref(false);
const saveSuccess = ref(false);
const saveError   = ref('');

const activeDaysCount = computed(() => localDays.value.filter(d => d.active).length);

function addRange(dayIndex) {
    localDays.value[dayIndex].ranges.push({ starts_at: '08:00', ends_at: '12:00' });
}

function removeRange(dayIndex, rangeIndex) {
    localDays.value[dayIndex].ranges.splice(rangeIndex, 1);
}

async function saveSchedule() {
    saving.value      = true;
    saveSuccess.value = false;
    saveError.value   = '';

    try {
        const res = await fetch(props.urls.sync, {
            method: 'PUT',
            headers: {
                'Content-Type':     'application/json',
                'X-CSRF-TOKEN':     csrf,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept':           'application/json',
            },
            body: JSON.stringify({
                schedule_interval: localInterval.value || null,
                days:              localDays.value,
            }),
        });

        const data = await res.json();

        if (!res.ok) {
            saveError.value = data.message ?? (props.t.work_schedule_save_error ?? 'Erro ao salvar escala.');
            return;
        }

        saveSuccess.value = true;
        if (window.showSuccessToast) window.showSuccessToast(data.message);
        setTimeout(() => { saveSuccess.value = false; }, 3000);
    } catch (e) {
        saveError.value = props.t.work_schedule_save_error ?? 'Erro de conexão.';
    } finally {
        saving.value = false;
    }
}

// ── Bloqueios ───────────────────────────────────────────────────────────────
const showBlockForm = ref(false);
const storingBlock  = ref(false);
const blockErrors   = ref({});
const blockForm     = ref({
    type:      'absence',
    starts_at: '',
    ends_at:   '',
    reason:    '',
});

function resetBlockForm() {
    blockForm.value = { type: 'absence', starts_at: '', ends_at: '', reason: '' };
    blockErrors.value = {};
}

async function storeBlock() {
    storingBlock.value = true;
    blockErrors.value  = {};

    try {
        const res = await fetch(props.urls.store_block, {
            method: 'POST',
            headers: {
                'Content-Type':     'application/json',
                'X-CSRF-TOKEN':     csrf,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept':           'application/json',
            },
            body: JSON.stringify(blockForm.value),
        });

        const data = await res.json();

        if (res.status === 422) {
            blockErrors.value = data.errors ?? {};
            return;
        }

        if (!res.ok) {
            if (window.showErrorToast) window.showErrorToast(data.message ?? 'Erro ao criar bloqueio.');
            return;
        }

        localBlocks.value.push(data.data);
        localBlocks.value.sort((a, b) => a.starts_at.localeCompare(b.starts_at));
        resetBlockForm();
        showBlockForm.value = false;
        if (window.showSuccessToast) window.showSuccessToast(data.message);
    } catch (e) {
        if (window.showErrorToast) window.showErrorToast('Erro de conexão.');
    } finally {
        storingBlock.value = false;
    }
}

async function destroyBlock(blockId) {
    if (!confirm(props.t.confirm_remove_block ?? 'Remover bloqueio? Esta ação não pode ser desfeita.')) {
        return;
    }

    try {
        const res = await fetch(`${props.urls.destroy_block}/${blockId}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN':     csrf,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept':           'application/json',
            },
        });

        const data = await res.json();

        if (!res.ok) {
            if (window.showErrorToast) window.showErrorToast(data.message ?? 'Erro ao remover.');
            return;
        }

        localBlocks.value = localBlocks.value.filter(b => b.id !== blockId);
        if (window.showSuccessToast) window.showSuccessToast(data.message);
    } catch (e) {
        if (window.showErrorToast) window.showErrorToast('Erro de conexão.');
    }
}

// ── Breadcrumbs ─────────────────────────────────────────────────────────────
const breadcrumbs = [
    { label: props.t.sidemenu?.dashboard ?? 'Dashboard', url: route('panel.dashboard'),     active: false },
    { label: props.t.sidemenu?.doctors   ?? 'Médicos',   url: route('panel.doctors.index'), active: false },
    { label: props.t.work_schedule       ?? 'Escala de Atendimento', url: '#', active: true },
];
</script>

<template>
    <AppLayout :title="t.work_schedule ?? 'Escala de Atendimento'" :breadcrumbs="breadcrumbs">

        <!-- ── Page toolbar (padrão do sistema: title + total à esquerda, ação à direita) ── -->
        <div class="d-flex align-items-center gap-2 pb-3 mb-3 border-bottom flex-wrap">
            <div class="d-flex align-items-center gap-2 me-auto">
                <h4 class="mb-0 fw-bold">{{ t.work_schedule ?? 'Escala de Atendimento' }}</h4>
                <span
                    style="font-size:.78rem;font-weight:600;color:#0d6efd;background:#eff4ff;border:1.5px solid #0d6efd;border-radius:20px;padding:2px 12px;white-space:nowrap;line-height:1.6;"
                >{{ activeDaysCount }} {{ activeDaysCount === 1 ? 'dia ativo' : 'dias ativos' }}</span>
            </div>
            <a :href="urls.back" class="btn btn-sm btn-outline-secondary">
                <i class="ti ti-arrow-left me-1"></i> {{ t.back ?? 'Voltar' }}
            </a>
        </div>

        <!-- ── Cabeçalho do médico ───────────────────────────────────────── -->
        <div class="card mb-3">
            <div class="card-body py-3">
                <div class="d-flex align-items-center gap-3">
                    <img
                        :src="doctor.photo_url"
                        :alt="doctor.name"
                        class="rounded-circle flex-shrink-0"
                        width="56" height="56"
                        :style="{ objectFit: 'cover', border: `3px solid ${doctor.color}` }"
                    >
                    <div>
                        <h5 class="mb-0 fw-bold" :style="{ color: doctor.color }">{{ doctor.name }}</h5>
                        <small class="text-muted">
                            <span v-if="doctor.record">CRM {{ doctor.record }} &mdash; </span>
                            <code>{{ doctor.code }}</code>
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3">

            <!-- ── Coluna esquerda — Horários ────────────────────────────────── -->
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <span><i class="fas fa-clock me-2 text-info"></i>{{ t.work_schedule ?? 'Horários de Atendimento' }}</span>
                        <span class="badge bg-secondary">{{ activeDaysCount }} {{ activeDaysCount === 1 ? 'dia ativo' : 'dias ativos' }}</span>
                    </div>
                    <div class="card-body">

                        <!-- Intervalo entre consultas -->
                        <div class="mb-4 p-3 bg-light rounded">
                            <label class="form-label fw-semibold mb-1">
                                <i class="fas fa-stopwatch me-1 text-secondary"></i>
                                {{ t.work_schedule_interval ?? 'Intervalo entre consultas' }}
                            </label>
                            <div class="d-flex align-items-center gap-2" style="max-width:280px;">
                                <input
                                    v-model.number="localInterval"
                                    type="number"
                                    class="form-control form-control-sm"
                                    min="5" max="120" step="5"
                                    placeholder="Ex: 15"
                                >
                                <span class="text-muted small flex-shrink-0">min</span>
                            </div>
                            <small class="text-muted d-block mt-1">
                                {{ t.clinic_default ?? 'Padrão da clínica' }}: {{ entityInterval }} min.
                            </small>
                        </div>

                        <!-- Grade de dias da semana -->
                        <div
                            v-for="(day, dayIndex) in localDays"
                            :key="day.day"
                            class="border rounded mb-2 overflow-hidden"
                        >
                            <!-- Cabeçalho do dia -->
                            <div
                                class="d-flex align-items-center px-3 py-2"
                                :class="day.active ? 'bg-info bg-opacity-10' : 'bg-light'"
                            >
                                <div class="form-check form-switch mb-0 flex-grow-1">
                                    <input
                                        v-model="day.active"
                                        type="checkbox"
                                        class="form-check-input"
                                        :id="`day-toggle-${day.day}`"
                                    >
                                    <label
                                        class="form-check-label fw-semibold"
                                        :for="`day-toggle-${day.day}`"
                                        :class="day.active ? 'text-dark' : 'text-muted'"
                                    >{{ day.name }}</label>
                                </div>
                                <button
                                    v-if="day.active"
                                    type="button"
                                    class="btn btn-sm btn-outline-info"
                                    :title="t.work_schedule_add_range ?? 'Adicionar faixa de horário'"
                                    @click="addRange(dayIndex)"
                                >
                                    <i class="fas fa-plus me-1"></i> {{ t.work_schedule_add_range ?? 'Faixa' }}
                                </button>
                            </div>

                            <!-- Faixas de horário (dia ativo) -->
                            <div v-if="day.active" class="px-3 pb-3 pt-2">
                                <div
                                    v-for="(range, rangeIndex) in day.ranges"
                                    :key="rangeIndex"
                                    class="d-flex align-items-center gap-2 mb-2"
                                >
                                    <span class="text-muted small flex-shrink-0" style="width:30px;">
                                        {{ t.work_schedule_time_from ?? 'De' }}
                                    </span>
                                    <input
                                        v-model="range.starts_at"
                                        type="time"
                                        class="form-control form-control-sm"
                                        style="max-width:120px;"
                                    >
                                    <span class="text-muted small flex-shrink-0">{{ t.work_schedule_time_until ?? 'até' }}</span>
                                    <input
                                        v-model="range.ends_at"
                                        type="time"
                                        class="form-control form-control-sm"
                                        style="max-width:120px;"
                                    >
                                    <button
                                        v-if="day.ranges.length > 1"
                                        type="button"
                                        class="btn btn-sm btn-outline-danger"
                                        :title="t.remove ?? 'Remover faixa'"
                                        @click="removeRange(dayIndex, rangeIndex)"
                                    >
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>

                                <div v-if="day.ranges.length === 0" class="text-muted small">
                                    {{ t.work_schedule_empty_ranges ?? 'Nenhuma faixa de horário. Clique em "+ Faixa" para adicionar.' }}
                                </div>
                            </div>

                            <!-- Dia inativo -->
                            <div v-else class="px-3 pb-2 pt-1">
                                <small class="text-muted">{{ t.work_schedule_day_off ?? 'Médico não atende neste dia.' }}</small>
                            </div>
                        </div>

                    </div>
                    <div class="card-footer d-flex align-items-center gap-2">
                        <button
                            type="button"
                            class="btn btn-info"
                            :disabled="saving"
                            @click="saveSchedule"
                        >
                            <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
                            <i v-else class="far fa-save me-1"></i>
                            {{ t.work_schedule_save ?? 'Salvar Escala' }}
                        </button>
                        <transition name="fade">
                            <span v-if="saveSuccess" class="text-success small">
                                <i class="fas fa-check-circle me-1"></i> {{ t.work_schedule_saved ?? 'Salvo com sucesso.' }}
                            </span>
                        </transition>
                        <transition name="fade">
                            <span v-if="saveError" class="text-danger small">{{ saveError }}</span>
                        </transition>
                    </div>
                </div>
            </div>

            <!-- ── Coluna direita — Bloqueios ─────────────────────────────────── -->
            <div class="col-lg-4">
                <div class="card">

                    <div class="card-header d-flex align-items-center justify-content-between">
                        <span><i class="fas fa-ban me-2 text-danger"></i>{{ t.work_schedule_blocks ?? 'Bloqueios / Ausências' }}</span>
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-danger"
                            :title="showBlockForm ? (t.cancel ?? 'Cancelar') : (t.block_add ?? 'Adicionar Bloqueio')"
                            @click="showBlockForm = !showBlockForm"
                        >
                            <i :class="showBlockForm ? 'fas fa-times' : 'fas fa-plus'"></i>
                        </button>
                    </div>

                    <!-- Formulário de novo bloqueio -->
                    <div v-if="showBlockForm" class="card-body border-bottom pb-3">
                        <div class="row g-2">
                            <div class="col-12">
                                <label class="form-label small mb-1">{{ t.block_type ?? 'Tipo' }}</label>
                                <SearchSelect
                                    v-model="blockForm.type"
                                    :options="[
                                        { value: 'absence', label: t.block_type_absence ?? 'Ausência' },
                                        { value: 'holiday', label: t.block_type_holiday ?? 'Feriado' },
                                        { value: 'meeting', label: t.block_type_meeting ?? 'Reunião / Compromisso' },
                                        { value: 'other',   label: t.block_type_other ?? 'Outro' },
                                    ]"
                                    :value-key="'value'"
                                    :label-key="'label'"
                                    :clearable="false"
                                />
                            </div>
                            <div class="col-12">
                                <label class="form-label small mb-1">
                                    {{ t.starts_at ?? 'Início' }} <span class="text-danger">*</span>
                                </label>
                                <input
                                    v-model="blockForm.starts_at"
                                    type="datetime-local"
                                    class="form-control form-control-sm"
                                    :class="{ 'is-invalid': blockErrors.starts_at }"
                                >
                                <div v-if="blockErrors.starts_at" class="invalid-feedback">{{ blockErrors.starts_at[0] }}</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label small mb-1">
                                    {{ t.ends_at ?? 'Fim' }} <span class="text-danger">*</span>
                                </label>
                                <input
                                    v-model="blockForm.ends_at"
                                    type="datetime-local"
                                    class="form-control form-control-sm"
                                    :class="{ 'is-invalid': blockErrors.ends_at }"
                                >
                                <div v-if="blockErrors.ends_at" class="invalid-feedback">{{ blockErrors.ends_at[0] }}</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label small mb-1">{{ t.reason ?? 'Motivo' }}</label>
                                <input
                                    v-model="blockForm.reason"
                                    type="text"
                                    class="form-control form-control-sm"
                                    :placeholder="t.block_reason_placeholder ?? 'Motivo (opcional)'"
                                >
                            </div>
                            <div class="col-12 d-flex gap-2">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-danger w-100"
                                    :disabled="storingBlock"
                                    @click="storeBlock"
                                >
                                    <span v-if="storingBlock" class="spinner-border spinner-border-sm me-1"></span>
                                    {{ t.block_add ?? 'Adicionar Bloqueio' }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Lista de bloqueios -->
                    <div class="card-body p-0">
                        <div v-if="localBlocks.length === 0" class="text-center py-4 text-muted">
                            <i class="fas fa-check-circle fa-2x mb-2 text-success"></i>
                            <p class="small mb-0">{{ t.work_schedule_no_blocks ?? 'Nenhum bloqueio futuro.' }}</p>
                        </div>

                        <div
                            v-for="block in localBlocks"
                            :key="block.id"
                            class="d-flex align-items-start gap-2 px-3 py-2 border-bottom"
                        >
                            <div class="flex-grow-1">
                                <div class="fw-semibold small">{{ block.type_label }}</div>
                                <div class="text-muted" style="font-size:.8rem;">
                                    <i class="fas fa-calendar-alt me-1"></i>
                                    <span>{{ block.starts_at }}</span>
                                    <span class="mx-1">→</span>
                                    <span>{{ block.ends_at }}</span>
                                </div>
                                <div v-if="block.reason" class="text-secondary" style="font-size:.8rem;">
                                    {{ block.reason }}
                                </div>
                            </div>
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-danger flex-shrink-0"
                                :title="t.remove ?? 'Remover bloqueio'"
                                @click="destroyBlock(block.id)"
                            >
                                <i class="fas fa-trash fa-xs"></i>
                            </button>
                        </div>
                    </div>

                </div>
            </div>

        </div>
    </AppLayout>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active { transition: opacity .25s ease; }
.fade-enter-from,
.fade-leave-to     { opacity: 0; }
</style>
