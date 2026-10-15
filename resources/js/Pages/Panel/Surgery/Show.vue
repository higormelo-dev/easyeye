<script setup>
import { computed, nextTick, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import CaseFormModal from '@/Components/Surgery/CaseFormModal.vue';
import ReasonModal from '@/Components/Surgery/ReasonModal.vue';
import SurgeryStatusBadge from '@/Components/Surgery/SurgeryStatusBadge.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

/**
 * Tela do caso cirúrgico. O cabeçalho e as ações de estado são daqui; cada
 * etapa do módulo é uma ABA entregue pelo servidor (config/surgery.php →
 * show_sections) cujo componente fica em Components/Surgery/Tabs/<Key>Tab.vue
 * e é achado por nome (import.meta.glob) — acrescentar uma etapa não mexe
 * nesta página. Aba ainda não montada nunca renderiza; depois de visitada
 * fica montada (v-show) para não perder o que estava sendo digitado.
 */
const props = defineProps({
    breadcrumbs: { type: Array, default: () => [] },
    surgicalCase: { type: Object, required: true },
    tabs: { type: Array, default: () => [] },
    sections: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
    urls: { type: Object, default: () => ({}) },
    t: { type: Object, required: true },
});

const { date, dateTime } = useLocaleFormat();
const ui = computed(() => props.t.ui?.show ?? {});

// Aba → componente: RequestTab.vue ↔ "request"; PostOpTab.vue ↔ "postop".
const tabModules = import.meta.glob('@/Components/Surgery/Tabs/*Tab.vue', { eager: true });
const tabComponents = Object.fromEntries(
    Object.entries(tabModules).map(([path, mod]) => {
        const file = path
            .split('/')
            .pop()
            .replace(/Tab\.vue$/, '');
        return [file.toLowerCase(), mod.default];
    }),
);
const componentFor = (key) => tabComponents[key.replace(/_/g, '')] ?? null;

function initialTab() {
    const fromHash = typeof window !== 'undefined' ? window.location.hash.replace(/^#tab=/, '') : '';
    return props.tabs.some((tab) => tab.key === fromHash) ? fromHash : (props.tabs[0]?.key ?? 'request');
}

const active = ref(initialTab());
const visited = ref(new Set([active.value]));

function select(key) {
    active.value = key;
    visited.value = new Set([...visited.value, key]);
    if (typeof window !== 'undefined') window.history.replaceState(null, '', `#tab=${key}`);
}

function onTabKeydown(event, index) {
    const keys = props.tabs.map((tab) => tab.key);
    const move = { ArrowRight: 1, ArrowLeft: -1 }[event.key];
    if (!move && !['Home', 'End'].includes(event.key)) return;
    event.preventDefault();
    const next =
        event.key === 'Home'
            ? keys[0]
            : event.key === 'End'
              ? keys[keys.length - 1]
              : keys[(index + move + keys.length) % keys.length];
    select(next);
    nextTick(() => document.getElementById(`surgery-tab-${next}`)?.focus());
}

function reload() {
    router.reload({ only: ['surgicalCase', 'tabs', 'sections', 'can'], preserveScroll: true });
}

// ── Ações de estado ───────────────────────────────────────────────────────
const editing = ref(false);
const reasonAction = ref(null); // 'cancel' | 'suspend'
const busy = ref(false);
const reasonError = ref('');

const reasonConfig = computed(
    () =>
        ({
            cancel: {
                title: ui.value.cancel_title,
                label: ui.value.cancel_reason,
                confirm: ui.value.cancel_confirm,
                tone: 'danger',
                url: props.urls.cancel,
            },
            suspend: {
                title: ui.value.suspend_title,
                label: ui.value.suspend_reason,
                confirm: ui.value.suspend_confirm,
                tone: 'warning',
                url: props.urls.suspend,
            },
        })[reasonAction.value] ?? {},
);

function submitReason(reason) {
    busy.value = true;
    reasonError.value = '';
    router.post(
        reasonConfig.value.url,
        { reason, expected_revision: props.surgicalCase.revision },
        {
            preserveScroll: true,
            onSuccess: () => {
                reasonAction.value = null;
            },
            onError: (errors) => {
                reasonError.value = errors.reason ?? '';
            },
            onFinish: () => {
                busy.value = false;
            },
        },
    );
}

function resume() {
    busy.value = true;
    router.post(
        props.urls.resume,
        { expected_revision: props.surgicalCase.revision },
        { preserveScroll: true, onFinish: () => (busy.value = false) },
    );
}

async function destroyDraft() {
    const result = await window.Swal?.fire({
        icon: 'warning',
        title: ui.value.delete,
        text: ui.value.delete_confirm,
        showCancelButton: true,
        confirmButtonText: ui.value.delete,
        cancelButtonText: ui.value.back_modal,
    });

    if (result?.isConfirmed) router.delete(props.urls.destroy);
}

const request = computed(() => props.sections.request ?? null);
const authExpired = computed(() => props.surgicalCase.authorization.expired);
</script>

<template>
    <Head :title="`${surgicalCase.code} — ${surgicalCase.title}`" />

    <AppLayout :title="surgicalCase.code" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <header class="d-flex flex-wrap align-items-start gap-3 pb-3 mb-3 border-bottom">
                <div class="me-auto">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                        <h1 class="h4 mb-0">{{ surgicalCase.title }}</h1>
                        <span class="badge text-bg-light">{{ surgicalCase.eye.label }}</span>
                        <SurgeryStatusBadge :status="surgicalCase.status" />
                        <span v-if="surgicalCase.urgent" class="badge text-bg-danger">{{ ui.urgent }}</span>
                    </div>
                    <div class="text-muted small">
                        <span class="me-3"><i class="ti ti-hash" aria-hidden="true"></i> {{ surgicalCase.code }}</span>
                        <span class="me-3"
                            ><i class="ti ti-user" aria-hidden="true"></i> {{ surgicalCase.patient.name }}</span
                        >
                        <span class="me-3"
                            ><i class="ti ti-stethoscope" aria-hidden="true"></i> {{ surgicalCase.surgeon.name }}</span
                        >
                        <span v-if="surgicalCase.covenant" class="me-3"
                            ><i class="ti ti-id" aria-hidden="true"></i> {{ surgicalCase.covenant }}</span
                        >
                        <span v-if="surgicalCase.scheduled_start"
                            ><i class="ti ti-calendar" aria-hidden="true"></i>
                            {{ dateTime(surgicalCase.scheduled_start)
                            }}<template v-if="surgicalCase.room">
                                · {{ surgicalCase.facility }} · {{ surgicalCase.room }}</template
                            ></span
                        >
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2">
                    <Link :href="urls.index" class="btn btn-outline-secondary btn-sm">
                        <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>{{ ui.back }}
                    </Link>
                    <button
                        v-if="can.edit"
                        type="button"
                        class="btn btn-outline-primary btn-sm"
                        data-testid="surgery-edit"
                        @click="editing = true"
                    >
                        <i class="ti ti-edit me-1" aria-hidden="true"></i>{{ ui.edit }}
                    </button>
                    <button
                        v-if="can.resume"
                        type="button"
                        class="btn btn-success btn-sm"
                        :disabled="busy"
                        data-testid="surgery-resume"
                        @click="resume"
                    >
                        <i class="ti ti-player-play me-1" aria-hidden="true"></i>{{ ui.resume }}
                    </button>
                    <button
                        v-if="can.suspend"
                        type="button"
                        class="btn btn-outline-warning btn-sm"
                        data-testid="surgery-suspend"
                        @click="reasonAction = 'suspend'"
                    >
                        <i class="ti ti-player-pause me-1" aria-hidden="true"></i>{{ ui.suspend }}
                    </button>
                    <button
                        v-if="can.cancel"
                        type="button"
                        class="btn btn-outline-danger btn-sm"
                        data-testid="surgery-cancel"
                        @click="reasonAction = 'cancel'"
                    >
                        <i class="ti ti-x me-1" aria-hidden="true"></i>{{ ui.cancel }}
                    </button>
                    <button
                        v-if="can.delete"
                        type="button"
                        class="btn btn-outline-danger btn-sm"
                        data-testid="surgery-delete"
                        @click="destroyDraft"
                    >
                        <i class="ti ti-trash me-1" aria-hidden="true"></i>{{ ui.delete }}
                    </button>
                </div>
            </header>

            <div v-if="surgicalCase.status.value === 'cancelled'" class="alert alert-danger" role="status">
                <strong>{{ ui.cancelled_banner }}</strong> {{ surgicalCase.cancellation_reason }}
            </div>
            <div v-else-if="surgicalCase.status.value === 'suspended'" class="alert alert-warning" role="status">
                <strong>{{ ui.suspended_banner }}</strong> {{ surgicalCase.suspension_reason }}
            </div>
            <div
                v-if="surgicalCase.severe_complication"
                class="alert alert-danger"
                role="alert"
                data-testid="surgery-severe"
            >
                <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>{{ ui.severe_banner }}
            </div>
            <div
                v-if="authExpired && !['performed', 'discharged', 'cancelled'].includes(surgicalCase.status.value)"
                class="alert alert-danger"
                role="alert"
                data-testid="surgery-auth-expired"
            >
                {{ ui.authorization_expired }}
            </div>

            <div class="row g-3 mb-3">
                <div class="col-6 col-md-3">
                    <div class="small text-muted">{{ ui.summary_authorization }}</div>
                    <div class="fw-medium">{{ surgicalCase.authorization.label }}</div>
                    <div v-if="surgicalCase.authorization.number" class="small text-muted">
                        {{ surgicalCase.authorization.number }}
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="small text-muted">{{ ui.summary_valid_until }}</div>
                    <div class="fw-medium">
                        {{
                            surgicalCase.authorization.valid_until ? date(surgicalCase.authorization.valid_until) : '—'
                        }}
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="small text-muted">{{ ui.summary_regime }}</div>
                    <div class="fw-medium">{{ surgicalCase.regime }}</div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="small text-muted">{{ ui.summary_anesthesia }}</div>
                    <div class="fw-medium">{{ surgicalCase.anesthesia?.label ?? '—' }}</div>
                </div>
            </div>

            <ul class="nav nav-tabs" role="tablist" :aria-label="ui.tabs_label">
                <li v-for="(tab, index) in tabs" :key="tab.key" class="nav-item" role="presentation">
                    <button
                        :id="`surgery-tab-${tab.key}`"
                        type="button"
                        class="nav-link"
                        :class="{ active: active === tab.key }"
                        role="tab"
                        :aria-selected="active === tab.key"
                        :aria-controls="`surgery-panel-${tab.key}`"
                        :tabindex="active === tab.key ? 0 : -1"
                        :data-testid="`surgery-tab-${tab.key}`"
                        @click="select(tab.key)"
                        @keydown="onTabKeydown($event, index)"
                    >
                        {{ tab.label }}
                        <span v-if="tab.badge" class="badge ms-1" :class="`text-bg-${tab.badge.tone}`">{{
                            tab.badge.label
                        }}</span>
                    </button>
                </li>
            </ul>

            <div class="border border-top-0 rounded-bottom p-3">
                <template v-for="tab in tabs" :key="tab.key">
                    <section
                        v-if="visited.has(tab.key)"
                        v-show="active === tab.key"
                        :id="`surgery-panel-${tab.key}`"
                        role="tabpanel"
                        :aria-labelledby="`surgery-tab-${tab.key}`"
                    >
                        <component
                            :is="componentFor(tab.key)"
                            v-if="componentFor(tab.key)"
                            :section="sections[tab.key]"
                            :surgical-case="surgicalCase"
                            :can="can"
                            :urls="urls"
                            :options="options"
                            :t="t"
                            @reload="reload"
                            @edit="editing = true"
                        />
                        <div v-else class="alert alert-secondary mb-0">{{ ui.tab_unavailable }}</div>
                    </section>
                </template>
            </div>
        </div>

        <CaseFormModal
            v-if="can.edit"
            :open="editing"
            :case-data="surgicalCase"
            :request="request"
            :options="options"
            :can="can"
            :urls="urls"
            :t="t"
            @close="editing = false"
        />

        <ReasonModal
            :open="reasonAction !== null"
            :title="reasonConfig.title ?? ''"
            :label="reasonConfig.label ?? ''"
            :confirm-text="reasonConfig.confirm ?? ''"
            :cancel-text="ui.back_modal"
            :tone="reasonConfig.tone ?? 'danger'"
            :busy="busy"
            :error="reasonError"
            @close="reasonAction = null"
            @confirm="submitReason"
        />
    </AppLayout>
</template>
