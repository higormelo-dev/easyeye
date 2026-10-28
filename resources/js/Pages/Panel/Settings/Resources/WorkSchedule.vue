<script setup>
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

/**
 * Escala e bloqueios de uma sala/equipamento (Configurações › Recursos →
 * "Escala e bloqueios"). A Agenda já recusa reservar o recurso fora da escala
 * ou num bloqueio (ScheduleService::isResourceWithinSchedule/isResourceBlocked)
 * — esta tela é onde a clínica configura as duas coisas. Mesmo padrão da
 * escala do médico (Panel/Doctors/WorkSchedule): grade semanal com faixas de
 * horário à esquerda, bloqueios (período + motivo) à direita.
 */
const props = defineProps({
    resource: { type: Object, required: true },
    days: { type: Array, required: true },
    blocks: { type: Array, default: () => [] },
    blockTypes: { type: Array, default: () => [] },
    urls: { type: Object, required: true },
    breadcrumbs: { type: Array, default: () => [] },
    t: { type: Object, default: () => ({}) },
});

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

function tr(key, replacements = {}) {
    let text = props.t[key] ?? key;
    for (const [name, value] of Object.entries(replacements)) {
        text = text.replaceAll(`:${name}`, value);
    }
    return text;
}

function toast(message, type = 'success') {
    if (!message) return;
    if (type === 'success' && window.showSuccessToast) window.showSuccessToast(message);
    if (type === 'error' && window.showErrorToast) window.showErrorToast(message);
}

// ── Escala ───────────────────────────────────────────────────────────────────
const localDays = ref(JSON.parse(JSON.stringify(props.days)));
const saving = ref(false);
const saveError = ref('');
const saved = ref(false);

const activeDaysCount = computed(() => localDays.value.filter((d) => d.active).length);
const activeDaysLabel = computed(() => {
    const n = activeDaysCount.value;
    if (n === 0) return tr('active_days_zero');
    if (n === 1) return tr('active_days_one');
    return tr('active_days_other', { count: n });
});

function addRange(day) {
    const last = day.ranges[day.ranges.length - 1];
    day.ranges.push(
        last ? { starts_at: last.ends_at, ends_at: last.ends_at } : { starts_at: '08:00', ends_at: '18:00' },
    );
}

function removeRange(day, index) {
    day.ranges.splice(index, 1);
}

async function saveSchedule() {
    if (saving.value) return;
    saving.value = true;
    saveError.value = '';
    saved.value = false;

    try {
        const res = await fetch(props.urls.sync, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ days: localDays.value }),
        });
        const json = await res.json().catch(() => ({}));

        if (!res.ok) {
            saveError.value = json.message ?? tr('save_error');
            return;
        }

        saved.value = true;
        toast(json.message ?? tr('saved'));
    } catch {
        saveError.value = tr('conn_error');
    } finally {
        saving.value = false;
    }
}

// ── Bloqueios ────────────────────────────────────────────────────────────────
const localBlocks = ref([...props.blocks]);
const showBlockForm = ref(false);
const storingBlock = ref(false);
const blockErrors = ref({});
const blockForm = ref(emptyBlock());

function emptyBlock() {
    return { type: props.blockTypes[0]?.value ?? 'absence', starts_at: '', ends_at: '', reason: '' };
}

function toggleBlockForm() {
    showBlockForm.value = !showBlockForm.value;
    blockForm.value = emptyBlock();
    blockErrors.value = {};
}

async function storeBlock() {
    if (storingBlock.value) return;
    storingBlock.value = true;
    blockErrors.value = {};

    try {
        const res = await fetch(props.urls.store_block, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(blockForm.value),
        });
        const json = await res.json().catch(() => ({}));

        if (res.status === 422) {
            blockErrors.value = json.errors ?? {};
            return;
        }
        if (!res.ok) {
            toast(json.message ?? tr('block_error'), 'error');
            return;
        }

        localBlocks.value = [...localBlocks.value, json.data];
        showBlockForm.value = false;
        blockForm.value = emptyBlock();
        toast(json.message);
    } catch {
        toast(tr('conn_error'), 'error');
    } finally {
        storingBlock.value = false;
    }
}

async function destroyBlock(block) {
    if (!window.confirm(tr('block_confirm'))) return;

    try {
        const res = await fetch(`${props.urls.destroy_block}/${block.id}`, {
            method: 'DELETE',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
        });
        const json = await res.json().catch(() => ({}));

        if (!res.ok) {
            toast(json.message ?? tr('block_error'), 'error');
            return;
        }

        localBlocks.value = localBlocks.value.filter((b) => b.id !== block.id);
        toast(json.message);
    } catch {
        toast(tr('conn_error'), 'error');
    }
}

const typeIcon = computed(() =>
    props.resource.type === 'equipment'
        ? 'ti ti-device-desktop-analytics'
        : props.resource.type === 'room'
          ? 'ti ti-door'
          : 'ti ti-box',
);
</script>

<template>
    <AppLayout :title="tr('title')" :breadcrumbs="breadcrumbs">
        <div class="resource-schedule">
            <!-- Barra da página: título + dias ativos, voltar à lista -->
            <div class="d-flex align-items-center gap-2 pb-3 mb-3 border-bottom flex-wrap">
                <div class="d-flex align-items-center gap-2 me-auto flex-wrap">
                    <h4 class="mb-0 fw-bold">{{ tr('title') }}</h4>
                    <span
                        class="badge rounded-pill bg-primary-subtle text-primary-emphasis border border-primary-subtle"
                    >
                        {{ activeDaysLabel }}
                    </span>
                </div>
                <a :href="urls.back" class="btn btn-sm btn-outline-secondary">
                    <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>{{ tr('back') }}
                </a>
            </div>

            <!-- Recurso -->
            <div class="card mb-3">
                <div class="card-body py-3 d-flex align-items-center gap-3">
                    <span
                        class="resource-schedule__icon rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                    >
                        <i :class="typeIcon" class="fs-3" aria-hidden="true"></i>
                    </span>
                    <div class="min-w-0">
                        <h5 class="mb-0 fw-bold text-break">{{ resource.name }}</h5>
                        <small class="text-body-secondary">
                            {{ resource.type_label }} &mdash; <code>{{ resource.code }}</code>
                        </small>
                    </div>
                </div>
            </div>

            <p class="text-body-secondary small mb-3">{{ tr('intro') }}</p>

            <div class="row g-3">
                <!-- Escala semanal -->
                <div class="col-lg-8">
                    <section class="card" aria-labelledby="resource-schedule-title">
                        <div class="card-header">
                            <h2 id="resource-schedule-title" class="h6 mb-0">
                                <i class="ti ti-clock me-2 text-info" aria-hidden="true"></i>{{ tr('schedule_title') }}
                            </h2>
                        </div>
                        <div class="card-body">
                            <div
                                v-for="day in localDays"
                                :key="day.day"
                                class="border rounded mb-2 overflow-hidden"
                                :data-testid="`day-${day.day}`"
                            >
                                <div
                                    class="d-flex align-items-center gap-2 px-3 py-2 flex-wrap"
                                    :class="day.active ? 'resource-schedule__day--on' : 'bg-body-tertiary'"
                                >
                                    <div class="form-check form-switch mb-0 flex-grow-1">
                                        <input
                                            :id="`rs-day-${day.day}`"
                                            v-model="day.active"
                                            type="checkbox"
                                            role="switch"
                                            class="form-check-input"
                                            :aria-label="tr('day_toggle', { day: day.name })"
                                        />
                                        <label
                                            class="form-check-label fw-semibold"
                                            :class="day.active ? '' : 'text-body-secondary'"
                                            :for="`rs-day-${day.day}`"
                                            >{{ day.name }}</label
                                        >
                                    </div>
                                    <button
                                        v-if="day.active"
                                        type="button"
                                        class="btn btn-sm btn-outline-info"
                                        :aria-label="tr('add_range_aria', { day: day.name })"
                                        :title="tr('add_range_aria', { day: day.name })"
                                        data-testid="add-range"
                                        @click="addRange(day)"
                                    >
                                        <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ tr('add_range') }}
                                    </button>
                                </div>

                                <div v-if="day.active" class="px-3 pb-3 pt-2">
                                    <div
                                        v-for="(range, index) in day.ranges"
                                        :key="index"
                                        class="d-flex align-items-center gap-2 mb-2 flex-wrap"
                                    >
                                        <!-- Pares "De [início]" / "até [fim]" quebram juntos no celular. -->
                                        <span class="d-inline-flex align-items-center gap-2">
                                            <span class="text-body-secondary small resource-schedule__lbl">{{
                                                tr('from')
                                            }}</span>
                                            <input
                                                v-model="range.starts_at"
                                                type="time"
                                                class="form-control form-control-sm resource-schedule__time"
                                                :aria-label="tr('range_start', { day: day.name })"
                                            />
                                        </span>
                                        <span class="d-inline-flex align-items-center gap-2">
                                            <span class="text-body-secondary small resource-schedule__lbl">{{
                                                tr('until')
                                            }}</span>
                                            <input
                                                v-model="range.ends_at"
                                                type="time"
                                                class="form-control form-control-sm resource-schedule__time"
                                                :aria-label="tr('range_end', { day: day.name })"
                                            />
                                        </span>
                                        <button
                                            v-if="day.ranges.length > 1"
                                            type="button"
                                            class="btn btn-sm btn-outline-danger"
                                            :aria-label="tr('remove_range')"
                                            :title="tr('remove_range')"
                                            @click="removeRange(day, index)"
                                        >
                                            <i class="ti ti-x" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                    <p v-if="day.ranges.length === 0" class="text-body-secondary small mb-0">
                                        {{ tr('no_ranges') }}
                                    </p>
                                </div>
                                <div v-else class="px-3 pb-2 pt-1">
                                    <small class="text-body-secondary">{{ tr('day_off') }}</small>
                                </div>
                            </div>

                            <div
                                v-if="saveError"
                                class="alert alert-danger py-2 mb-0 mt-3"
                                role="alert"
                                data-testid="save-error"
                            >
                                {{ saveError }}
                            </div>
                        </div>
                        <div class="card-footer d-flex align-items-center gap-2 flex-wrap">
                            <button
                                type="button"
                                class="btn btn-primary"
                                :disabled="saving"
                                data-testid="save-schedule"
                                @click="saveSchedule"
                            >
                                <span
                                    v-if="saving"
                                    class="spinner-border spinner-border-sm me-1"
                                    aria-hidden="true"
                                ></span>
                                <i v-else class="ti ti-device-floppy me-1" aria-hidden="true"></i>
                                {{ saving ? tr('saving') : tr('save') }}
                            </button>
                            <span v-if="saved" class="text-success small" role="status">
                                <i class="ti ti-circle-check me-1" aria-hidden="true"></i>{{ tr('saved') }}
                            </span>
                        </div>
                    </section>
                </div>

                <!-- Bloqueios -->
                <div class="col-lg-4">
                    <section class="card" aria-labelledby="resource-blocks-title">
                        <div class="card-header d-flex align-items-center justify-content-between gap-2">
                            <h2 id="resource-blocks-title" class="h6 mb-0">
                                <i class="ti ti-ban me-2 text-danger" aria-hidden="true"></i>{{ tr('blocks_title') }}
                            </h2>
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-danger"
                                :aria-expanded="String(showBlockForm)"
                                aria-controls="resource-block-form"
                                data-testid="toggle-block-form"
                                @click="toggleBlockForm"
                            >
                                <i
                                    :class="showBlockForm ? 'ti ti-x' : 'ti ti-plus'"
                                    class="me-1"
                                    aria-hidden="true"
                                ></i>
                                {{ showBlockForm ? tr('block_cancel') : tr('block_add') }}
                            </button>
                        </div>

                        <form
                            v-if="showBlockForm"
                            id="resource-block-form"
                            class="card-body border-bottom"
                            novalidate
                            @submit.prevent="storeBlock"
                        >
                            <div class="mb-2">
                                <label for="rs-block-type" class="form-label small mb-1">{{ tr('block_type') }}</label>
                                <select
                                    id="rs-block-type"
                                    v-model="blockForm.type"
                                    class="form-select form-select-sm"
                                    :class="{ 'is-invalid': blockErrors.type }"
                                >
                                    <option v-for="type in blockTypes" :key="type.value" :value="type.value">
                                        {{ type.label }}
                                    </option>
                                </select>
                                <div v-if="blockErrors.type" class="invalid-feedback">{{ blockErrors.type[0] }}</div>
                            </div>
                            <div class="mb-2">
                                <label for="rs-block-starts" class="form-label small mb-1">
                                    {{ tr('block_starts') }} <span class="text-danger" aria-hidden="true">*</span>
                                </label>
                                <input
                                    id="rs-block-starts"
                                    v-model="blockForm.starts_at"
                                    type="datetime-local"
                                    class="form-control form-control-sm"
                                    required
                                    :class="{ 'is-invalid': blockErrors.starts_at }"
                                />
                                <div v-if="blockErrors.starts_at" class="invalid-feedback">
                                    {{ blockErrors.starts_at[0] }}
                                </div>
                            </div>
                            <div class="mb-2">
                                <label for="rs-block-ends" class="form-label small mb-1">
                                    {{ tr('block_ends') }} <span class="text-danger" aria-hidden="true">*</span>
                                </label>
                                <input
                                    id="rs-block-ends"
                                    v-model="blockForm.ends_at"
                                    type="datetime-local"
                                    class="form-control form-control-sm"
                                    required
                                    :class="{ 'is-invalid': blockErrors.ends_at }"
                                />
                                <div v-if="blockErrors.ends_at" class="invalid-feedback">
                                    {{ blockErrors.ends_at[0] }}
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="rs-block-reason" class="form-label small mb-1">{{
                                    tr('block_reason')
                                }}</label>
                                <input
                                    id="rs-block-reason"
                                    v-model="blockForm.reason"
                                    type="text"
                                    maxlength="255"
                                    class="form-control form-control-sm"
                                    :placeholder="tr('block_reason_ph')"
                                    :class="{ 'is-invalid': blockErrors.reason }"
                                />
                                <div v-if="blockErrors.reason" class="invalid-feedback">
                                    {{ blockErrors.reason[0] }}
                                </div>
                            </div>
                            <button
                                type="submit"
                                class="btn btn-sm btn-danger w-100"
                                :disabled="storingBlock"
                                data-testid="save-block"
                            >
                                <span
                                    v-if="storingBlock"
                                    class="spinner-border spinner-border-sm me-1"
                                    aria-hidden="true"
                                ></span>
                                {{ tr('block_save') }}
                            </button>
                        </form>

                        <div class="card-body p-0">
                            <p class="small text-body-secondary px-3 pt-3 mb-2">{{ tr('blocks_hint') }}</p>
                            <div v-if="localBlocks.length === 0" class="text-center pb-4 pt-2 text-body-secondary">
                                <i class="ti ti-circle-check fs-2 d-block mb-1 text-success" aria-hidden="true"></i>
                                <span class="small">{{ tr('no_blocks') }}</span>
                            </div>
                            <ul v-else class="list-unstyled mb-0" data-testid="blocks-list">
                                <li
                                    v-for="block in localBlocks"
                                    :key="block.id"
                                    class="d-flex align-items-start gap-2 px-3 py-2 border-top"
                                >
                                    <div class="flex-grow-1 min-w-0">
                                        <div class="fw-semibold small">{{ block.type_label }}</div>
                                        <div class="text-body-secondary small">
                                            <i class="ti ti-calendar me-1" aria-hidden="true"></i>{{ block.starts_at }}
                                            <span aria-hidden="true">→</span>
                                            {{ block.ends_at }}
                                        </div>
                                        <div v-if="block.reason" class="small text-break">{{ block.reason }}</div>
                                    </div>
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-danger flex-shrink-0"
                                        :aria-label="
                                            tr('block_remove_aria', { start: block.starts_at, end: block.ends_at })
                                        "
                                        :title="tr('block_remove')"
                                        @click="destroyBlock(block)"
                                    >
                                        <i class="ti ti-trash" aria-hidden="true"></i>
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.resource-schedule__icon {
    width: 56px;
    height: 56px;
    background: rgba(13, 110, 253, 0.1);
    color: #0d6efd;
}
.resource-schedule__day--on {
    background: rgba(13, 202, 240, 0.1);
}
.resource-schedule__time {
    width: 7.5rem;
    max-width: 100%;
}
/* "De"/"até" com a mesma largura: no celular as faixas empilham alinhadas. */
.resource-schedule__lbl {
    min-width: 1.75rem;
}
.min-w-0 {
    min-width: 0;
}
[data-bs-theme='dark'] .resource-schedule__icon {
    background: rgba(110, 168, 254, 0.16);
    color: #6ea8fe;
}
[data-bs-theme='dark'] .resource-schedule__day--on {
    background: rgba(13, 202, 240, 0.16);
}
</style>
