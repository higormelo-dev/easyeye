<script setup>
import { ref, watch } from 'vue';
import { router }              from '@inertiajs/vue3';
import AppLayout               from '@/Layouts/AppLayout.vue';
import PageHeader              from '@/Components/Panel/PageHeader.vue';
import SearchInput             from '@/Components/Panel/SearchInput.vue';
import SubscriptionTable       from './SubscriptionTable.vue';
import SubscriptionCards       from './SubscriptionCards.vue';
import SubscriptionDetailDrawer from './SubscriptionDetailDrawer.vue';
import SubscriptionFormModal   from './SubscriptionFormModal.vue';
import SubscriptionActivateModal from './SubscriptionActivateModal.vue';
import SubscriptionTrialModal  from './SubscriptionTrialModal.vue';

const props = defineProps({
    subscriptions: { type: Object, required: true },
    total:         { type: Number, default: 0 },
    filters:       { type: Object, default: () => ({}) },
    plans:         { type: Array,  default: () => [] },
    billingCycles: { type: Array,  default: () => [] },
    statuses:      { type: Array,  default: () => [] },
    gateways:      { type: Array,  default: () => [] },
    trialDays:     { type: Number, default: 14 },
    graceDays:     { type: Number, default: 3 },
    t:             { type: Object, default: () => ({}) },
});

// ── View toggle ──────────────────────────────────────────────────────────────
const view = ref(localStorage.getItem('mgr_subscriptions_view') ?? 'table');
function setView(v) { view.value = v; localStorage.setItem('mgr_subscriptions_view', v); }

// ── Search / sort ─────────────────────────────────────────────────────────────
const search = ref(props.filters.search ?? '');
let searchTimer = null;

watch(search, (val) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        router.get(
            route('manager.subscriptions.index'),
            { search: val, sort: props.filters.sort, direction: props.filters.direction },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, 400);
});

function onSort({ sort, direction }) {
    router.get(
        route('manager.subscriptions.index'),
        { search: search.value, sort, direction },
        { preserveState: true, preserveScroll: true },
    );
}

// ── Detail drawer ─────────────────────────────────────────────────────────────
const detailOpen = ref(false);
const detailId   = ref(null);

function openDetail(id) { detailId.value = id; detailOpen.value = true; }
function closeDetail()  { detailOpen.value = false; detailId.value = null; }

// ── Edit form ─────────────────────────────────────────────────────────────────
const formOpen = ref(false);
const formId   = ref(null);

function openEdit(id) { formId.value = id; formOpen.value = true; }
function closeForm()  { formOpen.value = false; formId.value = null; }

// ── Activate modal ────────────────────────────────────────────────────────────
const activateOpen         = ref(false);
const activateSubscription = ref(null);

function openActivate(s) { activateSubscription.value = s; activateOpen.value = true; }
function closeActivate()  { activateOpen.value = false; activateSubscription.value = null; }

// ── Trial modal ───────────────────────────────────────────────────────────────
const trialOpen         = ref(false);
const trialSubscription = ref(null);

function openTrial(s) { trialSubscription.value = s; trialOpen.value = true; }
function closeTrial()  { trialOpen.value = false; trialSubscription.value = null; }

// ── Cancel ────────────────────────────────────────────────────────────────────
async function onCancel(s) {
    if (!confirm(
        `${props.t.confirm_cancel_title}\n\n${props.t.confirm_cancel_text}`
    )) return;

    const res = await fetch(route('manager.subscriptions.cancel'), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            'Accept': 'application/json',
        },
        body: JSON.stringify({ entity_id: s.entity_id }),
    });

    const json = await res.json();
    if (res.ok) {
        showToast(json.message, 'success');
        router.reload({ only: ['subscriptions', 'total'] });
    } else {
        showToast(json.message ?? 'Erro', 'error');
    }
}

// ── Block / Unblock ───────────────────────────────────────────────────────────
async function onBlock(s) {
    const blocking = s.entity_active;
    const msg      = blocking
        ? `${props.t.confirm_block_title}\n\n${props.t.confirm_block_text}`
        : `${props.t.confirm_unblock_title}\n\n${props.t.confirm_unblock_text}`;

    if (!confirm(msg)) return;

    const res = await fetch(route('manager.subscriptions.block-access'), {
        method: 'PATCH',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            'Accept': 'application/json',
        },
        body: JSON.stringify({ entity_id: s.entity_id, active: !blocking }),
    });

    const json = await res.json();
    if (res.ok) {
        showToast(json.message, 'success');
        router.reload({ only: ['subscriptions', 'total'] });
    } else {
        showToast(json.message ?? 'Erro', 'error');
    }
}

// ── Settings ──────────────────────────────────────────────────────────────────
const settingsForm     = ref({ trial_days: props.trialDays, grace_period_days: props.graceDays });
const settingsSaving   = ref(false);

async function saveSettings() {
    settingsSaving.value = true;
    try {
        const res = await fetch(route('manager.subscriptions.settings'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                'Accept': 'application/json',
            },
            body: JSON.stringify(settingsForm.value),
        });
        const json = await res.json();
        showToast(json.message ?? props.t.settings_saved, res.ok ? 'success' : 'error');
        if (res.ok) router.reload({ only: ['trialDays', 'graceDays'] });
    } finally {
        settingsSaving.value = false;
    }
}

// ── Toast helper ──────────────────────────────────────────────────────────────
function showToast(msg, type = 'success') {
    if (type === 'success' && window.showSuccessToast) return window.showSuccessToast(msg);
    if (type === 'error'   && window.showErrorToast)   return window.showErrorToast(msg);
    alert(msg);
}

// ── Breadcrumbs ───────────────────────────────────────────────────────────────
const breadcrumbs = [
    { label: props.t.breadcrumb_home    ?? 'Dashboard',    url: route('panel.dashboard'), active: false },
    { label: props.t.breadcrumb_current ?? 'Assinaturas',  url: '#', active: true },
];
</script>

<template>
    <AppLayout :title="t.page_title" :breadcrumbs="breadcrumbs">
        <div>

            <!-- ── Page Header ─────────────────────────────────────────────── -->
            <PageHeader
                :title="t.page_title"
                :total="total"
                :total-label="t.total_label"
                :view="view"
                :view-table-title="t.view_table"
                :view-cards-title="t.view_cards"
                @set-view="setView"
            />

            <!-- ── Search ──────────────────────────────────────────────────── -->
            <SearchInput
                v-model="search"
                :placeholder="t.search_placeholder"
                max-width="380px"
            />

            <!-- ── Settings card ────────────────────────────────────────────── -->
            <div class="card mb-3">
                <div class="card-header fw-semibold py-2">
                    <i class="ti ti-settings me-1 text-muted"></i>{{ t.settings_title }}
                </div>
                <div class="card-body py-3">
                    <form class="row g-3 align-items-end" @submit.prevent="saveSettings">
                        <div class="col-md-3 col-sm-6">
                            <label class="form-label small">{{ t.settings_trial_days }}</label>
                            <input
                                v-model.number="settingsForm.trial_days"
                                type="number" min="1" max="365"
                                class="form-control form-control-sm"
                            >
                        </div>
                        <div class="col-md-3 col-sm-6">
                            <label class="form-label small">{{ t.settings_grace_days }}</label>
                            <input
                                v-model.number="settingsForm.grace_period_days"
                                type="number" min="0" max="30"
                                class="form-control form-control-sm"
                            >
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-primary btn-sm" :disabled="settingsSaving">
                                <span v-if="settingsSaving" class="spinner-border spinner-border-sm me-1"></span>
                                {{ t.settings_save }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ── Table / Cards ─────────────────────────────────────────────── -->
            <SubscriptionTable
                v-if="view === 'table'"
                :subscriptions="subscriptions"
                :filters="filters"
                :t="t"
                @sort="onSort"
                @view="openDetail"
                @edit="openEdit"
                @activate="openActivate"
                @trial="openTrial"
                @cancel="onCancel"
                @block="onBlock"
            />
            <SubscriptionCards
                v-else
                :cards-url="route('manager.subscriptions.cards')"
                :initial-search="search"
                :t="t"
                @view="openDetail"
                @edit="openEdit"
                @activate="openActivate"
                @trial="openTrial"
                @cancel="onCancel"
                @block="onBlock"
            />

        </div>

        <!-- Detail drawer -->
        <SubscriptionDetailDrawer
            :open="detailOpen"
            :subscription-id="detailId"
            :t="t"
            @close="closeDetail"
            @edit="(id) => { closeDetail(); openEdit(id); }"
        />

        <!-- Edit form -->
        <SubscriptionFormModal
            :open="formOpen"
            :subscription-id="formId"
            :plans="plans"
            :statuses="statuses"
            :t="t"
            @close="closeForm"
            @saved="closeForm"
        />

        <!-- Activate modal -->
        <SubscriptionActivateModal
            :open="activateOpen"
            :subscription="activateSubscription"
            :plans="plans"
            :billing-cycles="billingCycles"
            :gateways="gateways"
            :t="t"
            @close="closeActivate"
            @saved="closeActivate"
        />

        <!-- Trial modal -->
        <SubscriptionTrialModal
            :open="trialOpen"
            :subscription="trialSubscription"
            :plans="plans"
            :trial-days="trialDays"
            :t="t"
            @close="closeTrial"
            @saved="closeTrial"
        />

    </AppLayout>
</template>
