<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import SearchInput from '@/Components/Panel/SearchInput.vue';
import ManagerBillingNav from '@/Components/Panel/ManagerBillingNav.vue';
import ConfirmationWithReasonModal from '@/Components/Panel/ConfirmationWithReasonModal.vue';
import SubscriptionTable from './SubscriptionTable.vue';
import SubscriptionCards from './SubscriptionCards.vue';
import SubscriptionDetailDrawer from './SubscriptionDetailDrawer.vue';
import SubscriptionCreateModal from './SubscriptionCreateModal.vue';
import SubscriptionExtendModal from './SubscriptionExtendModal.vue';
import SubscriptionTermsModal from './SubscriptionTermsModal.vue';

const props = defineProps({
    subscriptions: { type: Object, required: true },
    total: { type: Number, default: 0 },
    summary: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    plans: { type: Array, default: () => [] },
    billingCycles: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    gateways: { type: Array, default: () => [] },
    trialDays: { type: Number, default: 7 },
    canManagePlans: { type: Boolean, default: false },
    t: { type: Object, default: () => ({}) },
});

// ── View toggle ──────────────────────────────────────────────────────────────
function readView() {
    try {
        return localStorage.getItem('mgr_subscriptions_view') ?? 'table';
    } catch {
        return 'table';
    }
}

const view = ref(readView());
function setView(v) {
    view.value = v;
    try {
        localStorage.setItem('mgr_subscriptions_view', v);
    } catch {
        // armazenamento indisponível (modo privado): só não lembra a escolha
    }
}

// ── Filtros (na URL: dá para compartilhar e voltar) ─────────────────────────
const search = ref(props.filters.search ?? '');
const filterForm = ref({
    status: props.filters.status ?? '',
    plan: props.filters.plan ?? '',
    mode: props.filters.mode ?? '',
    scope: props.filters.scope ?? 'current',
});

const activeFilters = computed(() => ({
    search: search.value,
    ...filterForm.value,
    sort: props.filters.sort,
    direction: props.filters.direction,
}));

const hasFilters = computed(
    () => !!(search.value || filterForm.value.status || filterForm.value.plan || filterForm.value.mode),
);

function applyFilters(extra = {}) {
    const query = Object.fromEntries(
        Object.entries({ ...activeFilters.value, ...extra }).filter(
            ([key, value]) =>
                value !== '' && value !== null && value !== undefined && !(key === 'scope' && value === 'current'),
        ),
    );

    router.get(route('manager.subscriptions.index'), query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

let searchTimer = null;
watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => applyFilters(), 400);
});

watch(filterForm, () => applyFilters(), { deep: true });

function clearFilters() {
    search.value = '';
    filterForm.value = { status: '', plan: '', mode: '', scope: 'current' };
}

function onSort({ sort, direction }) {
    applyFilters({ sort, direction });
}

// Atalhos do resumo: filtram a lista pela situação/modalidade.
const summaryCards = computed(() => [
    { key: 'trial', mode: 'trial', icon: 'ti-clock-play', label: props.t.summary_trial },
    { key: 'gateway', mode: 'gateway', icon: 'ti-credit-card', label: props.t.summary_gateway },
    { key: 'complimentary', mode: 'complimentary', icon: 'ti-gift', label: props.t.summary_complimentary },
    { key: 'past_due', status: 'past_due', icon: 'ti-alert-triangle', label: props.t.summary_past_due },
    {
        key: 'awaiting_payment',
        status: 'awaiting_payment',
        icon: 'ti-hourglass',
        label: props.t.summary_awaiting_payment,
    },
    { key: 'without_access', status: 'no_access', icon: 'ti-lock', label: props.t.summary_without_access },
    // Cobrança automática do código anterior aguardando conciliação: só aparece quando há.
    ...(props.summary?.needs_review
        ? [{ key: 'needs_review', status: 'needs_review', icon: 'ti-file-search', label: props.t.summary_needs_review }]
        : []),
    // O gateway desativou a recorrência (cobrança passou para o sistema): só aparece quando há.
    ...(props.summary?.recurrence_alert
        ? [
              {
                  key: 'recurrence_alert',
                  status: 'recurrence_alert',
                  icon: 'ti-repeat-off',
                  label: props.t.summary_recurrence_alert,
              },
          ]
        : []),
]);

function applySummary(card) {
    filterForm.value = {
        ...filterForm.value,
        scope: 'current',
        status: card.status ?? (card.mode ? 'accessible' : ''),
        mode: card.mode ?? '',
    };
}

function isSummaryActive(card) {
    return card.mode
        ? filterForm.value.mode === card.mode && filterForm.value.status === 'accessible'
        : filterForm.value.status === card.status && !filterForm.value.mode;
}

const planFilterOptions = computed(() => props.plans.map((p) => ({ id: p.id, name: p.name })));

// ── Toast ────────────────────────────────────────────────────────────────────
function showToast(msg, type = 'success') {
    if (!msg) return;
    if (type === 'success' && window.showSuccessToast) return window.showSuccessToast(msg);
    if (type === 'error' && window.showErrorToast) return window.showErrorToast(msg);
}

// Depois de salvar: recarrega a lista/resumo e o drawer aberto.
const refreshKey = ref(0);

function afterSave(message) {
    showToast(message, 'success');
    refreshKey.value++;
    router.reload({ only: ['subscriptions', 'total', 'summary'] });
}

// ── Drawer ───────────────────────────────────────────────────────────────────
const detailOpen = ref(false);
const detailId = ref(null);

function openDetail(id) {
    detailId.value = id;
    detailOpen.value = true;
}

// ── Nova assinatura ──────────────────────────────────────────────────────────
const createOpen = ref(false);
const createPreset = ref({});

function openCreate(preset = {}) {
    createPreset.value = preset;
    createOpen.value = true;
}

function openCreateFor(s) {
    openCreate({ entity_id: s.entity_id, plan_id: s.plan_id });
}

// Vindo de Planos ("Nova assinatura neste plano"): ?new=1&new_plan=<id>.
onMounted(() => {
    const params = new URLSearchParams(window.location.search);
    if (params.get('new') !== '1') return;

    openCreate({ plan_id: params.get('new_plan') || undefined, entity_id: params.get('new_entity') || undefined });

    params.delete('new');
    params.delete('new_plan');
    params.delete('new_entity');
    const qs = params.toString();
    window.history.replaceState(window.history.state, '', `${window.location.pathname}${qs ? `?${qs}` : ''}`);
});

// ── Adicionar período / Alterar ─────────────────────────────────────────────
const extendOpen = ref(false);
const termsOpen = ref(false);
const target = ref(null);

function openExtend(s) {
    target.value = s;
    extendOpen.value = true;
}

function openTerms(s) {
    target.value = s;
    termsOpen.value = true;
}

function termsToCreate() {
    termsOpen.value = false;
    if (target.value) openCreateFor(target.value);
}

// ── Cancelar / bloquear (justificativa obrigatória) ─────────────────────────
const reasonModal = ref({
    open: false,
    saving: false,
    title: '',
    message: '',
    confirmVariant: 'danger',
    error: '',
    onConfirm: null,
});

function openReasonModal(config) {
    reasonModal.value = { open: true, saving: false, error: '', confirmVariant: 'danger', ...config };
}

function closeReasonModal() {
    if (!reasonModal.value.saving) reasonModal.value.open = false;
}

async function handleReasonConfirm(reason) {
    if (!reasonModal.value.onConfirm) return;
    reasonModal.value.saving = true;
    reasonModal.value.error = '';
    try {
        await reasonModal.value.onConfirm(reason);
        reasonModal.value.open = false;
    } catch (err) {
        reasonModal.value.error = err?.response?.data?.message ?? props.t.request_failed;
    } finally {
        reasonModal.value.saving = false;
    }
}

function onCancel(s) {
    openReasonModal({
        title: props.t.confirm_cancel_title,
        message: props.t.confirm_cancel_text,
        async onConfirm(reason) {
            const { data } = await window.axios.post(route('manager.subscriptions.cancel'), {
                entity_id: s.entity_id,
                reason,
            });
            afterSave(data.message);
        },
    });
}

function onBlock(s) {
    const blocking = s.entity_active;
    openReasonModal({
        title: blocking ? props.t.confirm_block_title : props.t.confirm_unblock_title,
        message: blocking ? props.t.confirm_block_text : props.t.confirm_unblock_text,
        confirmVariant: blocking ? 'danger' : 'warning',
        async onConfirm(reason) {
            const { data } = await window.axios.patch(route('manager.subscriptions.block-access'), {
                entity_id: s.entity_id,
                active: !blocking,
                reason,
            });
            afterSave(data.message);
        },
    });
}

// ── Breadcrumbs ──────────────────────────────────────────────────────────────
const breadcrumbs = [
    { label: props.t.breadcrumb_home ?? 'Dashboard', url: route('panel.dashboard'), active: false },
    { label: props.t.breadcrumb_current ?? 'Assinaturas', url: '#', active: true },
];
</script>

<template>
    <AppLayout :title="t.page_title" :breadcrumbs="breadcrumbs">
        <div>
            <PageHeader
                :title="t.page_title"
                :total="total"
                :view="view"
                :view-table-title="t.view_table"
                :view-cards-title="t.view_cards"
                :show-view-toggle="true"
                @set-view="setView"
            >
                <template #actions>
                    <button type="button" class="btn btn-primary fs-13" @click="openCreate()">
                        <i class="ti ti-plus me-1" aria-hidden="true"></i> {{ t.btn_new }}
                    </button>
                </template>
            </PageHeader>

            <!-- ── Planos ↔ Assinaturas ─────────────────────────────────── -->
            <ManagerBillingNav active="subscriptions" :can-manage-plans="canManagePlans" :labels="t" />

            <!-- ── Resumo (assinatura vigente de cada empresa) ──────────── -->
            <section class="sub-summary mb-3" :aria-label="t.summary_label">
                <button
                    v-for="card in summaryCards"
                    :key="card.key"
                    type="button"
                    class="sub-summary__item"
                    :class="{ 'sub-summary__item--active': isSummaryActive(card) }"
                    :aria-pressed="isSummaryActive(card)"
                    :data-summary="card.key"
                    @click="applySummary(card)"
                >
                    <i :class="['ti', card.icon]" aria-hidden="true"></i>
                    <span class="sub-summary__value">{{ summary[card.key] ?? 0 }}</span>
                    <span class="sub-summary__label">{{ card.label }}</span>
                </button>
                <button
                    type="button"
                    class="sub-summary__item"
                    :title="t.summary_no_sub_hint"
                    data-summary="no_subscription"
                    @click="openCreate()"
                >
                    <i class="ti ti-building-plus" aria-hidden="true"></i>
                    <span class="sub-summary__value">{{ summary.no_subscription ?? 0 }}</span>
                    <span class="sub-summary__label">{{ t.summary_no_subscription }}</span>
                </button>
            </section>

            <!-- ── Busca e filtros ─────────────────────────────────────── -->
            <div class="sub-filters mb-3" role="search">
                <SearchInput v-model="search" :placeholder="t.search_placeholder" max-width="320px" />
                <label class="visually-hidden" for="sub-filter-status">{{ t.filter_status }}</label>
                <select id="sub-filter-status" v-model="filterForm.status" class="form-select form-select-sm">
                    <option value="">{{ t.filter_status_all }}</option>
                    <option value="accessible">{{ t.filter_status_accessible }}</option>
                    <option value="no_access">{{ t.summary_without_access }}</option>
                    <option value="awaiting_payment">{{ t.summary_awaiting_payment }}</option>
                    <option value="needs_review">{{ t.summary_needs_review }}</option>
                    <option value="recurrence_alert">{{ t.summary_recurrence_alert }}</option>
                    <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                </select>
                <label class="visually-hidden" for="sub-filter-plan">{{ t.filter_plan }}</label>
                <select id="sub-filter-plan" v-model="filterForm.plan" class="form-select form-select-sm">
                    <option value="">{{ t.filter_plan_all }}</option>
                    <option v-for="p in planFilterOptions" :key="p.id" :value="p.id">{{ p.name }}</option>
                </select>
                <label class="visually-hidden" for="sub-filter-mode">{{ t.filter_mode }}</label>
                <select id="sub-filter-mode" v-model="filterForm.mode" class="form-select form-select-sm">
                    <option value="">{{ t.filter_mode_all }}</option>
                    <option v-for="(label, key) in t.modality" :key="key" :value="key">{{ label }}</option>
                </select>
                <label class="visually-hidden" for="sub-filter-scope">{{ t.filter_scope }}</label>
                <select id="sub-filter-scope" v-model="filterForm.scope" class="form-select form-select-sm">
                    <option value="current">{{ t.filter_scope_current }}</option>
                    <option value="all">{{ t.filter_scope_all }}</option>
                </select>
                <button v-if="hasFilters" type="button" class="btn btn-sm btn-link text-nowrap" @click="clearFilters">
                    <i class="ti ti-filter-off me-1" aria-hidden="true"></i>{{ t.filter_clear }}
                </button>
            </div>

            <!-- ── Tabela / Cards ──────────────────────────────────────── -->
            <SubscriptionTable
                v-if="view === 'table'"
                :subscriptions="subscriptions"
                :filters="filters"
                :billing-cycles="billingCycles"
                :can-manage-plans="canManagePlans"
                :has-filters="hasFilters"
                :t="t"
                @sort="onSort"
                @view="openDetail"
                @extend="openExtend"
                @change="openTerms"
                @new-for="openCreateFor"
                @cancel="onCancel"
                @block="onBlock"
            />
            <SubscriptionCards
                v-else
                :cards-url="route('manager.subscriptions.cards')"
                :filters="activeFilters"
                :billing-cycles="billingCycles"
                :has-filters="hasFilters"
                :t="t"
                @view="openDetail"
                @extend="openExtend"
                @change="openTerms"
                @new-for="openCreateFor"
                @cancel="onCancel"
                @block="onBlock"
            />
        </div>

        <SubscriptionDetailDrawer
            :open="detailOpen"
            :subscription-id="detailId"
            :billing-cycles="billingCycles"
            :statuses="statuses"
            :can-manage-plans="canManagePlans"
            :refresh-key="refreshKey"
            :t="t"
            @close="detailOpen = false"
            @updated="router.reload({ only: ['subscriptions', 'total', 'summary'] })"
            @extend="openExtend"
            @change="openTerms"
            @new-for="openCreateFor"
            @cancel="onCancel"
            @block="onBlock"
        />

        <SubscriptionCreateModal
            :open="createOpen"
            :plans="plans"
            :billing-cycles="billingCycles"
            :gateways="gateways"
            :trial-days="trialDays"
            :preset="createPreset"
            :t="t"
            @close="createOpen = false"
            @saved="afterSave"
        />

        <SubscriptionExtendModal
            :open="extendOpen"
            :subscription="target"
            :t="t"
            @close="extendOpen = false"
            @saved="afterSave"
        />

        <SubscriptionTermsModal
            :open="termsOpen"
            :subscription="target"
            :plans="plans"
            :t="t"
            @close="termsOpen = false"
            @saved="afterSave"
            @create-new="termsToCreate"
        />

        <ConfirmationWithReasonModal
            :open="reasonModal.open"
            :title="reasonModal.title"
            :message="reasonModal.message"
            :confirm-variant="reasonModal.confirmVariant"
            :saving="reasonModal.saving"
            :error="reasonModal.error"
            @close="closeReasonModal"
            @confirm="handleReasonConfirm"
        />
    </AppLayout>
</template>

<style scoped>
.sub-summary {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 0.5rem;
}
.sub-summary__item {
    display: grid;
    grid-template-columns: auto 1fr;
    grid-template-rows: auto auto;
    column-gap: 0.5rem;
    align-items: center;
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--bs-border-color);
    border-radius: var(--bs-border-radius);
    background: var(--bs-body-bg);
    color: var(--bs-body-color);
    text-align: left;
}
.sub-summary__item:hover,
.sub-summary__item--active {
    border-color: var(--bs-primary);
}
.sub-summary__item--active {
    background: var(--bs-primary-bg-subtle);
}
.sub-summary__item .ti {
    grid-row: 1 / span 2;
    font-size: 1.25rem;
    color: var(--bs-secondary-color);
}
.sub-summary__value {
    font-size: 1.125rem;
    font-weight: 700;
    line-height: 1.2;
    font-variant-numeric: tabular-nums;
}
.sub-summary__label {
    font-size: 0.75rem;
    color: var(--bs-secondary-color);
    line-height: 1.2;
}
.sub-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    align-items: center;
}
.sub-filters .form-select {
    width: auto;
    min-width: 160px;
    max-width: 100%;
}
@media (max-width: 575.98px) {
    .sub-filters .form-select {
        flex: 1 1 100%;
    }
}
</style>
