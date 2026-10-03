<script setup>
import { computed, ref, watch, onBeforeUnmount } from 'vue';
import axios from 'axios';
import ConfirmationWithReasonModal from '@/Components/Panel/ConfirmationWithReasonModal.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';

/**
 * Planos do convênio na gaveta de detalhes (manager). Planos da ANS são só
 * leitura (vêm da sincronização); planos manuais — convênio fora da ANS ou
 * plano que ainda não está nos dados abertos — têm cadastro, edição,
 * ativação e exclusão (sem uso, com justificativa).
 */
const props = defineProps({
    covenant: { type: Object, required: true },
    t: { type: Object, default: () => ({}) }, // manager_covenants
    tp: { type: Object, default: () => ({}) }, // covenant_plans
});

const emit = defineEmits(['changed']);

const { number } = useLocaleFormat();

const search = ref('');
const status = ref('');
const page = ref(1);
const rows = ref([]);
const meta = ref({ total: 0, from: 0, to: 0, last_page: 1 });
const counts = ref({ active: 0, total: 0 });
const state = ref('idle'); // idle | loading | done | error
let request = 0;

// Cadastro / edição de plano manual (antes do watch imediato abaixo, que fecha o formulário).
const form = ref(null); // { id?, name, ans_code, active }
const formErrors = ref({});
const saving = ref(false);

async function load() {
    const current = ++request;
    state.value = 'loading';

    try {
        const { data } = await axios.get(route('manager.covenants.plans.index', props.covenant.id), {
            params: { search: search.value || undefined, status: status.value || undefined, page: page.value },
        });
        if (current !== request) return;
        rows.value = data.data ?? [];
        meta.value = { total: data.total ?? 0, from: data.from ?? 0, to: data.to ?? 0, last_page: data.last_page ?? 1 };
        counts.value = data.counts ?? { active: 0, total: 0 };
        state.value = 'done';
    } catch {
        if (current !== request) return;
        state.value = 'error';
    }
}

watch(
    () => props.covenant.id,
    () => {
        search.value = '';
        status.value = '';
        page.value = 1;
        closeForm();
        load();
    },
    { immediate: true },
);

let searchTimer = null;
watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        page.value = 1;
        load();
    }, 350);
});
watch(status, () => {
    page.value = 1;
    load();
});
onBeforeUnmount(() => clearTimeout(searchTimer));

function goTo(target) {
    page.value = Math.min(Math.max(1, target), meta.value.last_page);
    load();
}

const showing = computed(() =>
    (props.t.plans_showing ?? '')
        .replace(':from', number(meta.value.from ?? 0))
        .replace(':to', number(meta.value.to ?? 0))
        .replace(':total', number(meta.value.total ?? 0)),
);

const countLabel = computed(() =>
    (props.t.plans_count ?? '')
        .replace(':active', number(counts.value.active ?? 0))
        .replace(':total', number(counts.value.total ?? 0)),
);

// ── Cadastro / edição de plano manual ─────────────────────────────────────
function openCreate() {
    formErrors.value = {};
    form.value = { id: null, name: '', ans_code: '', active: true };
}

function openEdit(plan) {
    formErrors.value = {};
    form.value = { id: plan.id, name: plan.name, ans_code: plan.ans_code ?? '', active: plan.active };
}

function closeForm() {
    form.value = null;
    formErrors.value = {};
}

async function save() {
    if (!form.value || saving.value) return;
    saving.value = true;
    formErrors.value = {};

    const payload = { name: form.value.name, ans_code: form.value.ans_code || null, active: form.value.active };

    try {
        if (form.value.id) {
            await axios.put(route('manager.covenants.plans.update', form.value.id), payload);
        } else {
            await axios.post(route('manager.covenants.plans.store', props.covenant.id), payload);
        }
        closeForm();
        await load();
        emit('changed');
    } catch (error) {
        const errors = error.response?.data?.errors;
        formErrors.value = errors
            ? Object.fromEntries(Object.entries(errors).map(([k, v]) => [k, Array.isArray(v) ? v[0] : v]))
            : { name: error.response?.data?.message ?? props.t.plans_failed };
    } finally {
        saving.value = false;
    }
}

async function toggleActive(plan) {
    try {
        await axios.put(route('manager.covenants.plans.update', plan.id), { active: !plan.active });
        await load();
        emit('changed');
    } catch {
        state.value = 'error';
    }
}

// ── Exclusão (plano manual sem uso): justificativa + trilha ──────────────
const removing = ref(null);
const removeError = ref('');
const removeSaving = ref(false);

function askRemove(plan) {
    removeError.value = '';
    removing.value = plan;
}

async function confirmRemove(reason) {
    if (!removing.value) return;
    removeSaving.value = true;
    removeError.value = '';

    try {
        await axios.delete(route('manager.covenants.plans.destroy', removing.value.id), { data: { reason } });
        removing.value = null;
        await load();
        emit('changed');
    } catch (error) {
        const errors = error.response?.data?.errors;
        removeError.value = errors?.reason?.[0] ?? error.response?.data?.message ?? props.t.plans_failed;
    } finally {
        removeSaving.value = false;
    }
}

function statusClass(plan) {
    if (!plan.active) return 'badge-soft-secondary';
    return plan.ans_status === 'suspended' ? 'badge-soft-warning' : 'badge-soft-success';
}

function statusText(plan) {
    return plan.status_label ?? (plan.active ? props.tp.status_active : props.tp.status_inactive);
}
</script>

<template>
    <div class="cps">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
            <span class="small text-muted" aria-live="polite">{{ countLabel }}</span>
            <button type="button" class="btn btn-sm btn-soft-primary" :disabled="!!form" @click="openCreate">
                <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ t.plans_new }}
            </button>
        </div>

        <!-- Cadastro / edição (plano manual) -->
        <form v-if="form" class="border rounded p-2 mb-2" novalidate @submit.prevent="save">
            <div class="mb-2">
                <label class="form-label small mb-1" for="cps-name">
                    {{ tp.field_name }} <span class="text-danger">*</span>
                </label>
                <input
                    id="cps-name"
                    v-model="form.name"
                    type="text"
                    class="form-control form-control-sm text-uppercase"
                    :class="{ 'is-invalid': formErrors.name }"
                    maxlength="255"
                />
                <div class="invalid-feedback">{{ formErrors.name }}</div>
            </div>
            <div class="mb-2">
                <label class="form-label small mb-1" for="cps-code">{{ tp.field_ans_code }}</label>
                <input
                    id="cps-code"
                    v-model="form.ans_code"
                    type="text"
                    inputmode="numeric"
                    class="form-control form-control-sm"
                    :class="{ 'is-invalid': formErrors.ans_code }"
                    maxlength="30"
                    aria-describedby="cps-code-hint"
                />
                <div id="cps-code-hint" class="form-text">{{ tp.field_ans_code_hint }}</div>
                <div class="invalid-feedback">{{ formErrors.ans_code }}</div>
            </div>
            <div class="form-check mb-2">
                <input id="cps-active" v-model="form.active" type="checkbox" class="form-check-input" />
                <label for="cps-active" class="form-check-label small">{{ tp.field_active }}</label>
            </div>
            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-sm btn-light" @click="closeForm">{{ t.cancel }}</button>
                <button type="submit" class="btn btn-sm btn-primary" :disabled="saving || !form.name.trim()">
                    <span v-if="saving" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                    {{ t.save }}
                </button>
            </div>
        </form>

        <div class="d-flex gap-2 mb-2">
            <input
                v-model="search"
                type="search"
                class="form-control form-control-sm"
                :placeholder="t.plans_search_placeholder"
                :aria-label="t.plans_search_placeholder"
            />
            <select v-model="status" class="form-select form-select-sm w-auto" :aria-label="t.filter_status">
                <option value="">{{ t.filter_status_all }}</option>
                <option value="active">{{ t.plans_selectable }}</option>
                <option value="inactive">{{ t.plans_unavailable }}</option>
            </select>
        </div>

        <div v-if="state === 'loading' && !rows.length" class="text-center py-3">
            <span class="spinner-border spinner-border-sm text-muted" role="status"></span>
        </div>
        <div v-else-if="state === 'error'" class="text-danger small" role="alert">{{ t.plans_failed }}</div>
        <div v-else-if="!rows.length" class="text-muted small text-center py-3">
            <i class="ti ti-list-search d-block mb-1 fs-4" aria-hidden="true"></i>{{ t.plans_empty }}
        </div>

        <ul v-else class="list-unstyled mb-2 cps-list" :aria-busy="state === 'loading'">
            <li v-for="plan in rows" :key="plan.id" class="cps-item">
                <div class="d-flex align-items-start gap-2">
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-medium small text-break">{{ plan.name }}</div>
                        <div class="text-muted" style="font-size: 0.75rem">
                            <span v-if="plan.ans_code" class="font-monospace">{{ plan.ans_code }}</span>
                            <span v-if="plan.ans_code && plan.sub_label"> · </span>{{ plan.sub_label }}
                        </div>
                        <div class="d-flex flex-wrap gap-1 mt-1">
                            <span class="badge rounded fs-11" :class="statusClass(plan)">{{ statusText(plan) }}</span>
                            <span v-if="plan.source === 'manual'" class="badge badge-soft-primary rounded fs-11">{{
                                plan.source_label
                            }}</span>
                        </div>
                    </div>
                    <div v-if="plan.source === 'manual'" class="d-flex gap-1 flex-shrink-0">
                        <button
                            type="button"
                            class="btn btn-sm btn-light"
                            :title="t.edit"
                            :aria-label="`${t.edit}: ${plan.name}`"
                            @click="openEdit(plan)"
                        >
                            <i class="ti ti-edit" aria-hidden="true"></i>
                        </button>
                        <button
                            type="button"
                            class="btn btn-sm btn-light"
                            :title="plan.active ? t.deactivate : t.activate"
                            :aria-label="`${plan.active ? t.deactivate : t.activate}: ${plan.name}`"
                            @click="toggleActive(plan)"
                        >
                            <i :class="`ti ${plan.active ? 'ti-lock-open' : 'ti-lock'}`" aria-hidden="true"></i>
                        </button>
                        <button
                            type="button"
                            class="btn btn-sm btn-light text-danger"
                            :title="t.delete"
                            :aria-label="`${t.delete}: ${plan.name}`"
                            @click="askRemove(plan)"
                        >
                            <i class="ti ti-trash" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
            </li>
        </ul>

        <div v-if="meta.last_page > 1" class="d-flex align-items-center justify-content-between gap-2">
            <span class="small text-muted">{{ showing }}</span>
            <div class="btn-group btn-group-sm">
                <button type="button" class="btn btn-light" :disabled="page <= 1" @click="goTo(page - 1)">
                    {{ t.previous }}
                </button>
                <button type="button" class="btn btn-light" :disabled="page >= meta.last_page" @click="goTo(page + 1)">
                    {{ t.next }}
                </button>
            </div>
        </div>

        <ConfirmationWithReasonModal
            :open="!!removing"
            :title="t.plans_confirm_delete_title"
            :message="(t.plans_confirm_delete_text ?? '').replace(':name', removing?.name ?? '')"
            :confirm-label="t.delete"
            confirm-variant="danger"
            :saving="removeSaving"
            :error="removeError"
            @close="removing = null"
            @confirm="confirmRemove"
        />
    </div>
</template>

<style scoped>
.cps-list {
    display: grid;
    gap: 0.5rem;
}
.cps-item {
    padding: 0.5rem;
    border: 1px solid var(--bs-border-color);
    border-radius: 0.375rem;
}
</style>
