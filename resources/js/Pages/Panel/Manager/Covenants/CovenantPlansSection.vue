<script setup>
import { computed, nextTick, ref, watch, onBeforeUnmount } from 'vue';
import axios from 'axios';
import ActionDropdown from '@/Components/Panel/ActionDropdown.vue';
import ConfirmationWithReasonModal from '@/Components/Panel/ConfirmationWithReasonModal.vue';
import SearchInput from '@/Components/Panel/SearchInput.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';

/**
 * Planos do convênio na gaveta de detalhes (manager). Planos da ANS são só
 * leitura (vêm da sincronização); planos manuais — convênio fora da ANS ou
 * plano que ainda não está nos dados abertos — têm cadastro, edição,
 * ativação e exclusão (sem uso, com justificativa).
 *
 * Filtro em chips com a contagem de cada situação (a mesma do ponto/selo da
 * linha); cada plano abre os dados da ANS e quantos pacientes o usam.
 */
const props = defineProps({
    covenant: { type: Object, required: true },
    t: { type: Object, default: () => ({}) }, // manager_covenants
    tp: { type: Object, default: () => ({}) }, // covenant_plans
});

const emit = defineEmits(['changed']);

const { number } = useLocaleFormat();

// Situações na ordem do filtro. Tom: disponível (verde/amarelo) ou não (cinza).
const SITUATIONS = ['active', 'suspended', 'cancelled', 'transferred', 'inactive'];
const TONES = { active: 'success', suspended: 'warning' };

const root = ref(null);
const search = ref('');
const status = ref('');
const page = ref(1);
const rows = ref([]);
const meta = ref({ total: 0, from: 0, to: 0, last_page: 1 });
const counts = ref({ active: 0, total: 0, situations: {} });
const state = ref('idle'); // idle | loading | done | error
const expanded = ref(new Set());
let request = 0;

// Cadastro / edição de plano manual (antes do watch imediato abaixo, que fecha o formulário).
const form = ref(null); // { id?, name, ans_code, active }
const formErrors = ref({});
const saving = ref(false);
const nameInput = ref(null);

async function load({ scrollTop = false } = {}) {
    const current = ++request;
    state.value = 'loading';

    try {
        const { data } = await axios.get(route('manager.covenants.plans.index', props.covenant.id), {
            params: { search: search.value || undefined, status: status.value || undefined, page: page.value },
        });
        if (current !== request) return;
        rows.value = data.data ?? [];
        meta.value = { total: data.total ?? 0, from: data.from ?? 0, to: data.to ?? 0, last_page: data.last_page ?? 1 };
        counts.value = data.counts ?? { active: 0, total: 0, situations: {} };
        state.value = 'done';
        // Paginação no rodapé: a nova página começa do topo da lista.
        if (scrollTop) root.value?.scrollIntoView?.({ block: 'start' });
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
        expanded.value = new Set();
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
    load({ scrollTop: true });
}

function clearFilters() {
    search.value = '';
    status.value = '';
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

// Só as situações que existem no convênio (a escolhida fica, mesmo zerada).
const situationChips = computed(() =>
    SITUATIONS.filter((key) => (counts.value.situations?.[key] ?? 0) > 0 || status.value === key).map((key) => ({
        key,
        label: props.tp[`status_${key}`] ?? key,
        count: counts.value.situations?.[key] ?? 0,
        tone: tone(key),
    })),
);

// ── Linha do plano ───────────────────────────────────────────────────────
function situationOf(plan) {
    return plan.ans_status ?? (plan.active ? 'active' : 'inactive');
}

function tone(situation) {
    return TONES[situation] ?? 'secondary';
}

function statusText(plan) {
    return plan.status_label ?? props.tp[`status_${situationOf(plan)}`];
}

// Selo só na exceção: "Ativo" é o normal e, com filtro, a situação já está no chip.
function showBadge(plan) {
    const situation = situationOf(plan);
    return situation !== 'active' && situation !== status.value;
}

function patientsLabel(count) {
    return count === 1
        ? props.t.plans_patients_one
        : (props.t.plans_patients_other ?? '').replace(':count', number(count));
}

function isOpen(plan) {
    return expanded.value.has(plan.id);
}

function toggleDetails(plan) {
    const next = new Set(expanded.value);
    next.has(plan.id) ? next.delete(plan.id) : next.add(plan.id);
    expanded.value = next;
}

function details(plan) {
    return [
        [props.tp.field_ans_code, plan.ans_code, true],
        [props.tp.field_contracting, plan.contracting],
        [props.tp.field_segmentation, plan.segmentation],
        [props.tp.field_coverage_area, plan.coverage_area],
        [props.tp.field_accommodation, plan.accommodation],
        [props.tp.field_moderating_factor, plan.moderating_factor],
        [props.tp.field_regulation, plan.regulation_label],
        // Plano manual não tem situação na ANS: é o ativo/inativo do EasyEye.
        [plan.ans_status ? props.tp.field_status : props.t.filter_status, statusText(plan)],
        [props.tp.field_status_at, plan.ans_status_at],
        [props.tp.field_registered_at, plan.ans_registered_at],
        [props.t.col_source, plan.source_label],
        [props.t.usage_patients, number(plan.patients ?? 0)],
    ].filter(([, value]) => value !== null && value !== undefined && value !== '');
}

// ── Cadastro / edição de plano manual ─────────────────────────────────────
function focusName() {
    // O formulário fica no topo da seção: o foco leva a tela até ele.
    nextTick(() => nameInput.value?.focus());
}

function openCreate() {
    formErrors.value = {};
    form.value = { id: null, name: '', ans_code: '', active: true };
    focusName();
}

function openEdit(plan) {
    formErrors.value = {};
    form.value = { id: plan.id, name: plan.name, ans_code: plan.ans_code ?? '', active: plan.active };
    focusName();
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
</script>

<template>
    <div ref="root" class="cps">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
            <div class="d-flex align-items-center gap-1 small text-muted">
                <span aria-live="polite">{{ countLabel }}</span>
                <i
                    class="ti ti-info-circle cps-hint"
                    role="img"
                    tabindex="0"
                    :title="t.plans_available_hint"
                    :aria-label="t.plans_available_hint"
                ></i>
                <span
                    v-if="state === 'loading' && rows.length"
                    class="spinner-border spinner-border-sm cps-spinner ms-1"
                    aria-hidden="true"
                ></span>
            </div>
            <button type="button" class="btn btn-sm btn-soft-primary" :disabled="!!form" @click="openCreate">
                <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ t.plans_new }}
            </button>
        </div>

        <!-- Cadastro / edição (plano manual) -->
        <form v-if="form" class="cps-form" novalidate @submit.prevent="save">
            <div class="cps-form__title">{{ form.id ? t.plans_edit : t.plans_new }}</div>
            <div class="mb-2">
                <label class="form-label small mb-1" for="cps-name">
                    {{ tp.field_name }} <span class="text-danger">*</span>
                </label>
                <input
                    id="cps-name"
                    ref="nameInput"
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

        <SearchInput
            v-model="search"
            :placeholder="t.plans_search_placeholder"
            :clear-label="t.plans_search_clear"
            max-width="100%"
            wrapper-class="mb-2"
        />

        <div class="d-flex flex-wrap gap-1 mb-2" role="group" :aria-label="t.filter_status">
            <button
                type="button"
                class="btn btn-sm rounded-pill cps-chip"
                :class="status === '' ? 'btn-primary' : 'btn-outline-secondary'"
                :aria-pressed="status === '' ? 'true' : 'false'"
                data-situation=""
                @click="status = ''"
            >
                {{ t.plans_filter_all }} <span class="cps-chip__count">{{ number(counts.total ?? 0) }}</span>
            </button>
            <button
                v-for="chip in situationChips"
                :key="chip.key"
                type="button"
                class="btn btn-sm rounded-pill cps-chip"
                :class="status === chip.key ? 'btn-primary' : 'btn-outline-secondary'"
                :aria-pressed="status === chip.key ? 'true' : 'false'"
                :data-situation="chip.key"
                @click="status = chip.key"
            >
                <span class="cps-dot" :class="`bg-${chip.tone}`" aria-hidden="true"></span>{{ chip.label }}
                <span class="cps-chip__count">{{ number(chip.count) }}</span>
            </button>
        </div>

        <div v-if="state === 'loading' && !rows.length" class="text-center py-3">
            <span class="spinner-border spinner-border-sm text-muted" role="status"></span>
        </div>
        <div v-else-if="state === 'error'" class="text-danger small" role="alert">
            {{ t.plans_failed }}
            <button type="button" class="btn btn-link btn-sm p-0 align-baseline" @click="load()">
                {{ t.plans_retry }}
            </button>
        </div>
        <div v-else-if="!rows.length" class="text-muted small text-center py-3">
            <i class="ti ti-list-search d-block mb-1 fs-4" aria-hidden="true"></i>{{ t.plans_empty }}
            <div v-if="search || status">
                <button type="button" class="btn btn-link btn-sm p-0 mt-1" @click="clearFilters">
                    {{ t.plans_clear_filters }}
                </button>
            </div>
        </div>

        <ul v-else class="list-unstyled mb-2 cps-list" :aria-busy="state === 'loading' ? 'true' : 'false'">
            <li v-for="plan in rows" :key="plan.id" class="cps-item" :class="{ 'cps-item--open': isOpen(plan) }">
                <div class="d-flex align-items-start">
                    <button
                        type="button"
                        class="cps-summary"
                        :aria-expanded="isOpen(plan) ? 'true' : 'false'"
                        :aria-controls="`cps-details-${plan.id}`"
                        @click="toggleDetails(plan)"
                    >
                        <span class="cps-name">
                            <span
                                class="cps-dot"
                                :class="`bg-${tone(situationOf(plan))}`"
                                :title="statusText(plan)"
                                aria-hidden="true"
                            ></span>
                            <span class="text-break">{{ plan.name }}</span>
                            <span v-if="!showBadge(plan)" class="visually-hidden">{{ statusText(plan) }}</span>
                        </span>
                        <span v-if="plan.ans_code || plan.sub_label" class="cps-meta">
                            <span v-if="plan.ans_code" class="font-monospace">{{ plan.ans_code }}</span>
                            <template v-if="plan.ans_code && plan.sub_label"> · </template>{{ plan.sub_label }}
                        </span>
                        <span v-if="showBadge(plan) || plan.source === 'manual' || plan.patients" class="cps-tags">
                            <span
                                v-if="showBadge(plan)"
                                class="badge rounded fs-11"
                                :class="`badge-soft-${tone(situationOf(plan))}`"
                                >{{ statusText(plan) }}</span
                            >
                            <span v-if="plan.source === 'manual'" class="badge badge-soft-primary rounded fs-11">{{
                                plan.source_label
                            }}</span>
                            <span v-if="plan.patients" class="cps-patients">
                                <i class="ti ti-users" aria-hidden="true"></i>{{ patientsLabel(plan.patients) }}
                            </span>
                        </span>
                        <i class="ti ti-chevron-down cps-chevron" aria-hidden="true"></i>
                    </button>
                    <div v-if="plan.source === 'manual'" class="cps-actions">
                        <ActionDropdown
                            btn-class="ee-action-icon ee-action-icon--default"
                            icon="ti ti-dots-vertical"
                            :title="`${t.more_actions}: ${plan.name}`"
                            :min-width="220"
                        >
                            <li>
                                <button type="button" class="dropdown-item rounded-1" @click="openEdit(plan)">
                                    <i class="ti ti-edit me-1" aria-hidden="true"></i> {{ t.edit }}
                                </button>
                            </li>
                            <li>
                                <button type="button" class="dropdown-item rounded-1" @click="toggleActive(plan)">
                                    <i
                                        :class="`ti me-1 ${plan.active ? 'ti-lock-open' : 'ti-lock'}`"
                                        aria-hidden="true"
                                    ></i>
                                    {{ plan.active ? t.deactivate : t.activate }}
                                </button>
                            </li>
                            <li><hr class="dropdown-divider" /></li>
                            <li>
                                <!-- Em uso: o servidor recusaria depois da justificativa; avisa antes. -->
                                <button
                                    type="button"
                                    class="dropdown-item rounded-1 cps-delete"
                                    :class="{ 'text-danger': !plan.in_use }"
                                    :disabled="plan.in_use"
                                    :title="plan.in_use ? t.plans_in_use_hint : null"
                                    @click="askRemove(plan)"
                                >
                                    <i class="ti ti-trash me-1" aria-hidden="true"></i> {{ t.delete }}
                                    <span v-if="plan.in_use" class="d-block small text-muted">{{
                                        t.plans_in_use_hint
                                    }}</span>
                                </button>
                            </li>
                        </ActionDropdown>
                    </div>
                </div>
                <dl v-show="isOpen(plan)" :id="`cps-details-${plan.id}`" class="cps-details">
                    <template v-for="[label, value, mono] in details(plan)" :key="label">
                        <dt>{{ label }}</dt>
                        <dd :class="{ 'font-monospace': mono }">{{ value }}</dd>
                    </template>
                </dl>
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
.cps {
    scroll-margin-top: 1rem;
}
.cps-hint {
    cursor: help;
}
.cps-spinner {
    width: 0.75rem;
    height: 0.75rem;
    border-width: 0.15em;
}
.cps-form {
    padding: 0.5rem;
    margin-bottom: 0.5rem;
    border: 1px solid var(--bs-border-color);
    border-radius: 0.375rem;
}
.cps-form__title {
    font-size: 0.8rem;
    font-weight: 600;
    margin-bottom: 0.5rem;
}
.cps-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
}
.cps-chip__count {
    font-variant-numeric: tabular-nums;
    opacity: 0.8;
}
.cps-chip:focus-visible {
    outline: 2px solid var(--bs-primary);
    outline-offset: 2px;
}
.cps-dot {
    display: inline-block;
    flex-shrink: 0;
    width: 0.5rem;
    height: 0.5rem;
    border-radius: 50%;
}
.cps-chip .cps-dot {
    box-shadow: 0 0 0 1px rgba(255, 255, 255, 0.6);
}
.cps-list {
    display: grid;
    gap: 0.5rem;
    transition: opacity 0.15s ease;
}
.cps-list[aria-busy='true'] {
    opacity: 0.55;
}
.cps-item {
    border: 1px solid var(--bs-border-color);
    border-radius: 0.375rem;
}
.cps-summary {
    position: relative;
    flex: 1 1 auto;
    min-width: 0;
    display: grid;
    gap: 0.125rem;
    padding: 0.5rem 1.75rem 0.5rem 0.625rem;
    color: inherit;
    text-align: start;
    background: none;
    border: 0;
    border-radius: 0.375rem;
}
.cps-summary:hover {
    background: var(--bs-tertiary-bg);
}
.cps-summary:focus-visible {
    outline: 2px solid var(--bs-primary);
    outline-offset: -2px;
}
.cps-name {
    display: flex;
    align-items: baseline;
    gap: 0.375rem;
    font-size: 0.875rem;
    font-weight: 500;
}
.cps-name .cps-dot {
    transform: translateY(-1px);
}
.cps-meta,
.cps-tags {
    padding-left: 0.875rem;
}
.cps-meta {
    display: -webkit-box;
    overflow: hidden;
    font-size: 0.75rem;
    color: var(--bs-secondary-color);
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
    line-clamp: 2;
}
.cps-item--open .cps-meta {
    display: block;
}
.cps-tags {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.25rem;
    margin-top: 0.125rem;
}
.cps-patients {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    font-size: 0.75rem;
    color: var(--bs-secondary-color);
}
.cps-chevron {
    position: absolute;
    top: 0.55rem;
    right: 0.5rem;
    color: var(--bs-secondary-color);
    transition: transform 0.15s ease;
}
.cps-item--open .cps-chevron {
    transform: rotate(180deg);
}
.cps-actions {
    padding: 0.375rem 0.375rem 0 0;
}
.cps-delete {
    white-space: normal;
}
.cps-details {
    display: grid;
    grid-template-columns: minmax(7rem, 42%) 1fr;
    gap: 0.25rem 0.75rem;
    margin: 0;
    padding: 0.5rem 0.75rem 0.625rem 1.5rem;
    font-size: 0.8rem;
    border-top: 1px dashed var(--bs-border-color);
}
.cps-details dt {
    font-weight: 600;
}
.cps-details dd {
    margin: 0;
    color: var(--bs-secondary-color);
    word-break: break-word;
}
@media (prefers-reduced-motion: reduce) {
    .cps-list,
    .cps-chevron {
        transition: none;
    }
}
@media (max-width: 575.98px) {
    .cps-details {
        grid-template-columns: 1fr;
        gap: 0;
    }
    .cps-details dd {
        margin-bottom: 0.25rem;
    }
}
</style>
