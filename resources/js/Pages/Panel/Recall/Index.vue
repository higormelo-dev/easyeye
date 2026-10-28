<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import KpiCard from '@/Components/Panel/KpiCard.vue';
import SearchInput from '@/Components/Panel/SearchInput.vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import RecallInviteStatus from '@/Components/Panel/Recall/RecallInviteStatus.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { makeTx } from '@/Support/recallRule.js';

/**
 * Retornos (Fase 6) — quem precisa voltar à clínica: contatar, agendar, adiar, dispensar ou marcar "feito
 * fora". O servidor decide o que cada perfil vê (condição clínica só para médico; telefone só com acesso a
 * Pacientes) e o estado do prazo sai das datas — nada aqui calcula regra clínica.
 */
const props = defineProps({
    breadcrumbs: { type: Array, default: () => [] },
    view: { type: String, default: 'to_contact' },
    source: { type: String, default: null },
    search: { type: String, default: '' },
    counts: { type: Object, required: true },
    rows: { type: Object, required: true },
    today: { type: String, required: true },
    snoozeMaxDays: { type: Number, default: 365 },
    contactOutcomes: { type: Array, default: () => [] },
    isDoctor: { type: Boolean, default: false },
    phonesVisible: { type: Boolean, default: false },
    setup: { type: Object, default: () => ({ doctor_order_enabled: true, protocols: 0 }) },
    // O canal de convite por WhatsApp da clínica: aberto (os três níveis de chave ligados) ou o motivo.
    invites: { type: Object, default: () => ({ available: false, blocker: null, label: null }) },
    t: { type: Object, required: true },
});

const tx = makeTx(() => props.t);
const { number, date } = useLocaleFormat();

// ── Visões e filtros ─────────────────────────────────────────────────────────
const MAIN_VIEWS = [
    { key: 'to_contact', icon: 'ti ti-phone-call', tone: 'primary' },
    { key: 'lost', icon: 'ti ti-user-question', tone: 'danger' },
    { key: 'scheduled', icon: 'ti ti-calendar-check', tone: 'success' },
    { key: 'snoozed', icon: 'ti ti-clock-pause', tone: 'warning' },
];

const EXTRA_VIEWS = computed(() => [
    { key: 'history', show: true },
    { key: 'needs_info', show: props.counts.needs_info > 0 || props.view === 'needs_info' },
    { key: 'suspended', show: props.counts.suspended > 0 || props.view === 'suspended' },
]);

const mainCount = (key) => (key === 'to_contact' ? props.counts.to_contact : (props.counts[key] ?? 0));

const searchText = ref(props.search);
const sourceFilter = ref(props.source ?? '');

const hasFilters = computed(() => searchText.value.trim() !== '' || sourceFilter.value !== '');

function visit(overrides = {}) {
    const query = {
        view: props.view,
        source: sourceFilter.value || undefined,
        search: searchText.value.trim() || undefined,
        ...overrides,
    };

    router.get(route('panel.recall.index'), query, { preserveState: true, preserveScroll: true, replace: true });
}

function goTo(view) {
    visit({ view, page: undefined });
}

let searchTimer = null;
watch(searchText, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => visit({ page: undefined }), 350);
});

watch(sourceFilter, () => visit({ page: undefined }));

function clearFilters() {
    searchText.value = '';
    sourceFilter.value = '';
}

// ── Apresentação ─────────────────────────────────────────────────────────────
// Cores próprias (contraste AA nos dois temas — as cores "suaves" do template ficam abaixo de 4,5:1).
const STATE_CLASS = {
    due_soon: 'recall-state recall-state--warning',
    due: 'recall-state recall-state--danger',
    overdue: 'recall-state recall-state--danger',
    lost: 'recall-state recall-state--muted',
};

function dueText(row) {
    if (row.days_to_due === null || row.days_to_due === undefined) return '';
    if (row.days_to_due === 0) return tx('due.today');
    if (row.days_to_due > 0) return tx('due.in', { count: number(row.days_to_due) });

    return tx('due.overdue_by', { count: number(Math.abs(row.days_to_due)) });
}

function contactText(row) {
    if (!row.contact.count) return tx('row.contact_never');

    const last = row.contact.last_at ? date(row.contact.last_at.substring(0, 10)) : '';

    return `${tx('row.contact_count', { count: row.contact.count })} — ${tx('row.contact_last', {
        outcome: row.contact.outcome_label ?? '',
        date: last,
    })}`;
}

/** Oferece "Enviar convite": canal aberto, retorno em aberto e paciente que pode, em princípio, receber. */
const canInvite = (row) =>
    props.invites.available && row.lifecycle === 'open' && !row.rule_suspended && row.invite?.can_try === true;

const isWorkable = (row) => !row.rule_suspended && (row.lifecycle === 'open' || row.lifecycle === 'snoozed');
const canReopen = (row) => !row.rule_suspended && (row.lifecycle === 'snoozed' || row.lifecycle === 'dismissed');

function scheduleUrl(row) {
    return `${route('panel.schedules.index')}?new=1&recall=${encodeURIComponent(row.id)}`;
}

// ── Modais de ação ───────────────────────────────────────────────────────────
const dialog = reactive({ kind: null, row: null });

const contactForm = useForm({ outcome: '', note: '' });
const snoozeForm = useForm({ until: '', reason: '' });
const dismissForm = useForm({ reason: '' });
const elsewhereForm = useForm({ date: '' });
const reopenForm = useForm({});
const inviteForm = useForm({});

// Prévia do convite (o servidor diz o que sairia e, se não sai, por quê). Só a última consulta vale.
const invitePreview = ref(null);
const inviteState = ref('idle'); // idle | loading | ready | error
let inviteRequest = 0;

async function loadInvitePreview(row) {
    const request = ++inviteRequest;
    invitePreview.value = null;
    inviteState.value = 'loading';

    try {
        const response = await fetch(route('panel.recall.invite-preview', row.id), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });

        if (!response.ok) throw new Error(String(response.status));

        const data = await response.json();

        if (request !== inviteRequest) return;

        invitePreview.value = data;
        inviteState.value = 'ready';
    } catch {
        if (request !== inviteRequest) return;

        inviteState.value = 'error';
    }
}

const tomorrow = computed(() => addDays(props.today, 1));
const snoozeLimit = computed(() => addDays(props.today, props.snoozeMaxDays));

function addDays(isoDate, days) {
    const [year, month, day] = isoDate.split('-').map(Number);
    const value = new Date(Date.UTC(year, month - 1, day + days));

    return value.toISOString().substring(0, 10);
}

function openDialog(kind, row) {
    dialog.kind = kind;
    dialog.row = row;

    inviteRequest++;
    invitePreview.value = null;
    inviteState.value = 'idle';

    if (kind === 'invite') loadInvitePreview(row);

    contactForm.reset();
    contactForm.clearErrors();
    snoozeForm.reset();
    snoozeForm.clearErrors();
    dismissForm.reset();
    dismissForm.clearErrors();
    elsewhereForm.reset();
    elsewhereForm.clearErrors();
}

function closeDialog() {
    dialog.kind = null;
    dialog.row = null;
    inviteRequest++;
}

const FORMS = {
    contact: { form: contactForm, route: 'panel.recall.contact' },
    snooze: { form: snoozeForm, route: 'panel.recall.snooze' },
    dismiss: { form: dismissForm, route: 'panel.recall.dismiss' },
    elsewhere: { form: elsewhereForm, route: 'panel.recall.done-elsewhere' },
    reopen: { form: reopenForm, route: 'panel.recall.reopen' },
    invite: { form: inviteForm, route: 'panel.recall.invite' },
};

const busy = computed(() => Object.values(FORMS).some(({ form }) => form.processing));

// O envio só sai com a prévia pronta e liberada pelo servidor (que reconfere tudo ao enviar).
const submitBlocked = computed(
    () => dialog.kind === 'invite' && !(inviteState.value === 'ready' && invitePreview.value?.can_send),
);

function submit() {
    const current = FORMS[dialog.kind];

    if (!current || !dialog.row || busy.value || submitBlocked.value) return;

    current.form.post(route(current.route, dialog.row.id), {
        preserveScroll: true,
        onSuccess: closeDialog,
        // O servidor recusa quando a linha mudou (outra pessoa tratou): fecha e a lista atualizada explica.
        onError: (errors) => {
            if (!Object.keys(errors).length) closeDialog();
        },
    });
}

const dialogTitle = computed(() => {
    const keys = {
        contact: 'contact',
        snooze: 'snooze',
        dismiss: 'dismiss',
        elsewhere: 'done_elsewhere',
        reopen: 'reopen',
        invite: 'invite',
    };

    return dialog.kind ? tx(`modal.${keys[dialog.kind]}.title`) : '';
});

const submitLabel = computed(() => {
    const keys = {
        contact: 'contact',
        snooze: 'snooze',
        dismiss: 'dismiss',
        elsewhere: 'done_elsewhere',
        reopen: 'reopen',
        invite: 'invite',
    };

    return dialog.kind ? tx(`modal.${keys[dialog.kind]}.submit`) : '';
});

// ── Estado vazio ─────────────────────────────────────────────────────────────
const emptyKind = computed(() => {
    if (hasFilters.value) return 'filtered';
    if (!props.setup.protocols && !props.setup.doctor_order_enabled) return 'doctor_order_off';
    if (props.view === 'to_contact' && (props.setup.protocols || props.setup.doctor_order_enabled)) return 'to_contact';

    return 'none';
});

const viewTitle = computed(() => tx(`tabs.${props.view}`));
</script>

<template>
    <Head :title="t.title" />

    <AppLayout :title="t.title" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3 recall-form" data-testid="recall-page">
            <PageHeader :title="t.title" :subtitle="t.subtitle">
                <template #actions>
                    <Link
                        :href="route('panel.recall.settings.index')"
                        class="btn btn-sm recall-btn"
                        data-testid="recall-settings-link"
                    >
                        <i class="ti ti-settings me-1" aria-hidden="true"></i>{{ t.settings }}
                    </Link>
                </template>
            </PageHeader>

            <!-- Números da clínica: cada cartão é uma visão da lista -->
            <div class="row g-3 mb-3" role="group" :aria-label="t.tabs.label">
                <div
                    v-for="item in MAIN_VIEWS"
                    :key="item.key"
                    class="col-6 col-lg-3"
                    :data-testid="`recall-view-${item.key}`"
                >
                    <KpiCard
                        toggle
                        tinted
                        :label="tx(`tabs.${item.key}`)"
                        :value="number(mainCount(item.key))"
                        :icon="item.icon"
                        :tone="item.tone"
                        :active="view === item.key"
                        :subtitle="
                            item.key === 'to_contact'
                                ? tx('kpi.breakdown', {
                                      soon: number(counts.due_soon),
                                      overdue: number(counts.overdue),
                                  })
                                : ''
                        "
                        :test-id="`recall-kpi-${item.key}`"
                        @click="goTo(item.key)"
                    />
                </div>
            </div>

            <!-- Outras visões -->
            <div class="d-flex flex-wrap gap-2 mb-3" role="group" :aria-label="t.tabs.label">
                <button
                    v-for="extra in EXTRA_VIEWS.filter((entry) => entry.show)"
                    :key="extra.key"
                    type="button"
                    class="btn btn-sm recall-btn"
                    :class="{ 'recall-btn--active': view === extra.key }"
                    :aria-pressed="view === extra.key ? 'true' : 'false'"
                    :data-testid="`recall-tab-${extra.key}`"
                    @click="goTo(extra.key)"
                >
                    {{ tx(`tabs.${extra.key}`) }}
                    <span v-if="extra.key !== 'history'" class="badge bg-body-secondary text-body ms-1">{{
                        number(counts[extra.key])
                    }}</span>
                </button>
            </div>

            <!-- Filtros -->
            <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                <SearchInput
                    v-model="searchText"
                    :placeholder="t.filters.search_placeholder"
                    :clear-label="t.filters.clear"
                    wrapper-class=""
                    max-width="320px"
                />
                <label class="visually-hidden" for="recall-source">{{ t.filters.source }}</label>
                <select id="recall-source" v-model="sourceFilter" class="form-select form-select-sm w-auto">
                    <option value="">{{ t.filters.source }}: {{ t.filters.source_all }}</option>
                    <option value="doctor_order">{{ t.filters.source_doctor_order }}</option>
                    <option value="protocol">{{ t.filters.source_protocol }}</option>
                    <option value="course">{{ t.filters.source_course }}</option>
                </select>
                <button v-if="hasFilters" type="button" class="btn btn-link btn-sm" @click="clearFilters">
                    {{ t.filters.clear }}
                </button>
            </div>

            <div
                v-if="view === 'suspended'"
                class="alert alert-warning small"
                role="note"
                data-testid="recall-suspended"
            >
                <i class="ti ti-player-pause me-1" aria-hidden="true"></i>{{ t.suspended_banner }}
            </div>
            <div v-if="view === 'needs_info'" class="alert alert-info small" role="note">
                <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ t.needs_info_banner }}
            </div>

            <!-- Vazio -->
            <section v-if="!rows.data.length" class="card text-center py-5 px-3" data-testid="recall-empty">
                <i class="ti ti-circle-check fs-1 text-muted d-block mb-2" aria-hidden="true"></i>
                <h2 class="h6 mb-1">{{ t.empty[`${emptyKind}_title`] ?? t.empty.none_title }}</h2>
                <p class="text-muted small mb-1">{{ t.empty[emptyKind] }}</p>
                <p v-if="emptyKind === 'to_contact'" class="text-muted small mb-0">{{ t.empty.other_views }}</p>
                <div v-if="emptyKind === 'filtered'" class="mt-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" @click="clearFilters">
                        {{ t.filters.clear }}
                    </button>
                </div>
            </section>

            <template v-else>
                <!-- Tabela (telas largas) -->
                <div class="card d-none d-lg-block">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0" :aria-label="viewTitle" data-testid="recall-table">
                            <thead>
                                <tr>
                                    <th scope="col">{{ t.columns.patient }}</th>
                                    <th scope="col">{{ t.columns.task }}</th>
                                    <th scope="col">{{ t.columns.due }}</th>
                                    <th scope="col">{{ t.columns.contact }}</th>
                                    <th scope="col" class="text-end">{{ t.columns.actions }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in rows.data"
                                    :key="row.id"
                                    data-testid="recall-row"
                                    :data-state="row.state"
                                >
                                    <td>
                                        <div class="fw-semibold">{{ row.patient.name }}</div>
                                        <div class="small text-muted">
                                            <span v-if="row.patient.age !== null">{{
                                                tx('row.age', { age: row.patient.age })
                                            }}</span>
                                            <span v-if="row.patient.phone">
                                                <span v-if="row.patient.age !== null"> · </span
                                                ><i class="ti ti-phone me-1" aria-hidden="true"></i
                                                ><span class="visually-hidden">{{ t.columns.contact }}: </span
                                                >{{ row.patient.phone }}
                                                <i
                                                    v-if="row.patient.whatsapp"
                                                    class="ti ti-brand-whatsapp ms-1"
                                                    :title="t.row.whatsapp"
                                                    :aria-label="t.row.whatsapp"
                                                ></i>
                                            </span>
                                        </div>
                                        <div v-for="block in row.blocks" :key="block.reason" class="small text-danger">
                                            <i class="ti ti-ban me-1" aria-hidden="true"></i
                                            >{{ tx('row.block_warning', { label: block.label }) }}
                                        </div>
                                    </td>
                                    <td>
                                        <div>{{ row.task_label }}</div>
                                        <div v-if="isDoctor && row.condition" class="small text-muted">
                                            {{ tx('row.condition', { name: row.condition }) }}
                                            <span v-if="row.eye">· {{ t.row.eye[row.eye] }}</span>
                                        </div>
                                        <div class="small text-muted">
                                            {{
                                                row.last_done_at
                                                    ? tx('row.last_done', { date: date(row.last_done_at) })
                                                    : t.row.never_done
                                            }}
                                        </div>
                                        <div v-if="row.capped" class="small text-warning-emphasis">
                                            <i class="ti ti-ruler me-1" aria-hidden="true"></i>{{ t.row.capped }}
                                        </div>
                                    </td>
                                    <td>
                                        <template v-if="row.due_at">
                                            <div>{{ date(row.due_at) }}</div>
                                            <div class="small text-muted">{{ dueText(row) }}</div>
                                            <span v-if="row.state" class="badge mt-1" :class="STATE_CLASS[row.state]">{{
                                                row.state_label
                                            }}</span>
                                        </template>
                                        <span v-else class="small text-muted">{{ t.row.needs_info }}</span>
                                    </td>
                                    <td>
                                        <div class="small">{{ contactText(row) }}</div>
                                        <div v-if="row.schedule" class="small text-success-emphasis">
                                            <i class="ti ti-calendar-check me-1" aria-hidden="true"></i
                                            >{{
                                                tx('row.scheduled_for', {
                                                    date: row.schedule.date_at
                                                        ? date(row.schedule.date_at.substring(0, 10))
                                                        : '',
                                                })
                                            }}
                                        </div>
                                        <div v-if="row.snoozed_until" class="small text-muted">
                                            {{ tx('row.snoozed_until', { date: date(row.snoozed_until) }) }}
                                        </div>
                                        <div v-if="row.lifecycle === 'dismissed'" class="small text-muted">
                                            {{ tx('row.dismissed', { reason: row.dismissed_reason ?? '' }) }}
                                        </div>
                                        <div v-if="row.lifecycle === 'closed'" class="small text-muted">
                                            {{ tx('row.closed', { reason: row.closed_reason_label ?? '' }) }}
                                        </div>
                                        <div v-if="row.rule_suspended" class="small text-warning-emphasis">
                                            {{ t.row.suspended }}
                                        </div>
                                        <RecallInviteStatus
                                            :invite="row.invite"
                                            :channel-open="invites.available"
                                            :whatsapp="row.patient.whatsapp"
                                            :t="t"
                                        />
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <template v-if="isWorkable(row)">
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-primary me-1"
                                                :aria-label="`${t.actions.contact}: ${row.patient.name}`"
                                                data-testid="recall-contact"
                                                @click="openDialog('contact', row)"
                                            >
                                                <i class="ti ti-phone me-1" aria-hidden="true"></i
                                                >{{ t.actions.contact }}
                                            </button>
                                            <a
                                                v-if="!row.schedule"
                                                :href="scheduleUrl(row)"
                                                class="btn btn-sm btn-outline-primary me-1"
                                                :aria-label="`${t.actions.schedule}: ${row.patient.name}`"
                                                data-testid="recall-schedule"
                                            >
                                                <i class="ti ti-calendar-plus me-1" aria-hidden="true"></i
                                                >{{ t.actions.schedule }}
                                            </a>
                                            <button
                                                v-if="canInvite(row)"
                                                type="button"
                                                class="btn btn-sm btn-outline-success me-1"
                                                :aria-label="`${t.actions.invite}: ${row.patient.name}`"
                                                data-testid="recall-invite"
                                                @click="openDialog('invite', row)"
                                            >
                                                <i class="ti ti-brand-whatsapp me-1" aria-hidden="true"></i
                                                >{{ t.actions.invite }}
                                            </button>
                                            <div class="dropdown d-inline-block">
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-light"
                                                    data-bs-toggle="dropdown"
                                                    aria-expanded="false"
                                                    :aria-label="`${t.actions.more}: ${row.patient.name}`"
                                                    data-tooltip="off"
                                                    data-testid="recall-more"
                                                >
                                                    <i class="ti ti-dots-vertical" aria-hidden="true"></i>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end">
                                                    <li v-if="row.lifecycle === 'open'">
                                                        <button
                                                            type="button"
                                                            class="dropdown-item"
                                                            data-testid="recall-snooze"
                                                            @click="openDialog('snooze', row)"
                                                        >
                                                            <i class="ti ti-clock-pause me-2" aria-hidden="true"></i
                                                            >{{ t.actions.snooze }}
                                                        </button>
                                                    </li>
                                                    <li v-if="row.source !== 'course'">
                                                        <button
                                                            type="button"
                                                            class="dropdown-item"
                                                            data-testid="recall-elsewhere"
                                                            @click="openDialog('elsewhere', row)"
                                                        >
                                                            <i
                                                                class="ti ti-building-hospital me-2"
                                                                aria-hidden="true"
                                                            ></i
                                                            >{{ t.actions.done_elsewhere }}
                                                        </button>
                                                    </li>
                                                    <li>
                                                        <button
                                                            type="button"
                                                            class="dropdown-item text-danger"
                                                            data-testid="recall-dismiss"
                                                            @click="openDialog('dismiss', row)"
                                                        >
                                                            <i class="ti ti-circle-x me-2" aria-hidden="true"></i
                                                            >{{ t.actions.dismiss }}
                                                        </button>
                                                    </li>
                                                </ul>
                                            </div>
                                        </template>
                                        <button
                                            v-if="canReopen(row)"
                                            type="button"
                                            class="btn btn-sm btn-outline-secondary"
                                            :aria-label="`${t.actions.reopen}: ${row.patient.name}`"
                                            data-testid="recall-reopen"
                                            @click="openDialog('reopen', row)"
                                        >
                                            <i class="ti ti-rotate me-1" aria-hidden="true"></i>{{ t.actions.reopen }}
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Cartões (celular e tablet) -->
                <ul
                    class="list-unstyled d-lg-none mb-0 d-grid gap-2"
                    :aria-label="viewTitle"
                    data-testid="recall-cards"
                >
                    <li v-for="row in rows.data" :key="row.id" class="card p-3" data-testid="recall-card">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <div class="fw-semibold">{{ row.patient.name }}</div>
                                <div class="small text-muted">
                                    <span v-if="row.patient.age !== null">{{
                                        tx('row.age', { age: row.patient.age })
                                    }}</span>
                                    <span v-if="row.patient.phone"> · {{ row.patient.phone }}</span>
                                </div>
                            </div>
                            <span v-if="row.state" class="badge" :class="STATE_CLASS[row.state]">{{
                                row.state_label
                            }}</span>
                        </div>
                        <div class="mt-2">{{ row.task_label }}</div>
                        <div v-if="isDoctor && row.condition" class="small text-muted">
                            {{ tx('row.condition', { name: row.condition }) }}
                        </div>
                        <div v-if="row.due_at" class="small text-muted">
                            {{ date(row.due_at) }} · {{ dueText(row) }}
                        </div>
                        <div v-else class="small text-muted">{{ t.row.needs_info }}</div>
                        <div class="small mt-1">{{ contactText(row) }}</div>
                        <div v-if="row.schedule" class="small text-success-emphasis">
                            {{
                                tx('row.scheduled_for', {
                                    date: row.schedule.date_at ? date(row.schedule.date_at.substring(0, 10)) : '',
                                })
                            }}
                        </div>
                        <div v-for="block in row.blocks" :key="block.reason" class="small text-danger">
                            {{ tx('row.block_warning', { label: block.label }) }}
                        </div>
                        <div v-if="row.rule_suspended" class="small text-warning-emphasis">{{ t.row.suspended }}</div>
                        <RecallInviteStatus
                            :invite="row.invite"
                            :channel-open="invites.available"
                            :whatsapp="row.patient.whatsapp"
                            :t="t"
                        />
                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <template v-if="isWorkable(row)">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-primary"
                                    @click="openDialog('contact', row)"
                                >
                                    {{ t.actions.contact }}
                                </button>
                                <a
                                    v-if="!row.schedule"
                                    :href="scheduleUrl(row)"
                                    class="btn btn-sm btn-outline-primary"
                                    >{{ t.actions.schedule }}</a
                                >
                                <button
                                    v-if="canInvite(row)"
                                    type="button"
                                    class="btn btn-sm btn-outline-success"
                                    data-testid="recall-invite"
                                    @click="openDialog('invite', row)"
                                >
                                    {{ t.actions.invite }}
                                </button>
                                <button
                                    v-if="row.lifecycle === 'open'"
                                    type="button"
                                    class="btn btn-sm btn-outline-secondary"
                                    @click="openDialog('snooze', row)"
                                >
                                    {{ t.actions.snooze }}
                                </button>
                                <button
                                    v-if="row.source !== 'course'"
                                    type="button"
                                    class="btn btn-sm btn-outline-secondary"
                                    @click="openDialog('elsewhere', row)"
                                >
                                    {{ t.actions.done_elsewhere }}
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-danger"
                                    @click="openDialog('dismiss', row)"
                                >
                                    {{ t.actions.dismiss }}
                                </button>
                            </template>
                            <button
                                v-if="canReopen(row)"
                                type="button"
                                class="btn btn-sm btn-outline-secondary"
                                @click="openDialog('reopen', row)"
                            >
                                {{ t.actions.reopen }}
                            </button>
                        </div>
                    </li>
                </ul>

                <TablePagination :data="rows" class="mt-3" />
            </template>
        </div>

        <!-- Ações -->
        <CenteredModal :open="dialog.kind !== null" size="md" @close="closeDialog">
            <template #header>
                <h5 class="mb-0">{{ dialogTitle }}</h5>
            </template>

            <form v-if="dialog.kind" id="recall-dialog-form" class="recall-form" novalidate @submit.prevent="submit">
                <p class="fw-semibold mb-3">{{ tx('modal.for', { name: dialog.row?.patient.name ?? '' }) }}</p>

                <template v-if="dialog.kind === 'contact'">
                    <label class="form-label small mb-1" for="recall-outcome">{{ t.modal.contact.outcome }} *</label>
                    <select
                        id="recall-outcome"
                        v-model="contactForm.outcome"
                        class="form-select mb-2"
                        :class="{ 'is-invalid': contactForm.errors.outcome }"
                        required
                    >
                        <option value="" disabled>—</option>
                        <option v-for="outcome in contactOutcomes" :key="outcome.value" :value="outcome.value">
                            {{ outcome.label }}
                        </option>
                    </select>
                    <div v-if="contactForm.errors.outcome" class="invalid-feedback d-block mb-2" role="alert">
                        {{ contactForm.errors.outcome }}
                    </div>
                    <label class="form-label small mb-1" for="recall-note">{{ t.modal.contact.note }}</label>
                    <textarea
                        id="recall-note"
                        v-model="contactForm.note"
                        class="form-control"
                        rows="2"
                        maxlength="200"
                        aria-describedby="recall-note-hint"
                    ></textarea>
                    <div id="recall-note-hint" class="form-text">{{ tx('modal.contact.note_hint', { max: 200 }) }}</div>
                </template>

                <template v-else-if="dialog.kind === 'snooze'">
                    <label class="form-label small mb-1" for="recall-until">{{ t.modal.snooze.until }} *</label>
                    <input
                        id="recall-until"
                        v-model="snoozeForm.until"
                        type="date"
                        class="form-control"
                        :class="{ 'is-invalid': snoozeForm.errors.until }"
                        :min="tomorrow"
                        :max="snoozeLimit"
                        aria-describedby="recall-until-hint"
                        required
                    />
                    <div v-if="snoozeForm.errors.until" class="invalid-feedback d-block" role="alert">
                        {{ snoozeForm.errors.until }}
                    </div>
                    <div id="recall-until-hint" class="form-text mb-2">
                        {{ tx('modal.snooze.until_hint', { days: snoozeMaxDays }) }}
                    </div>
                    <label class="form-label small mb-1" for="recall-snooze-reason">{{ t.modal.snooze.reason }}</label>
                    <input
                        id="recall-snooze-reason"
                        v-model="snoozeForm.reason"
                        type="text"
                        class="form-control"
                        maxlength="200"
                    />
                </template>

                <template v-else-if="dialog.kind === 'dismiss'">
                    <p class="small text-muted">{{ t.modal.dismiss.warning }}</p>
                    <label class="form-label small mb-1" for="recall-dismiss-reason"
                        >{{ t.modal.dismiss.reason }} *</label
                    >
                    <textarea
                        id="recall-dismiss-reason"
                        v-model="dismissForm.reason"
                        class="form-control"
                        :class="{ 'is-invalid': dismissForm.errors.reason }"
                        rows="3"
                        maxlength="200"
                        aria-describedby="recall-dismiss-hint"
                        required
                    ></textarea>
                    <div v-if="dismissForm.errors.reason" class="invalid-feedback d-block" role="alert">
                        {{ dismissForm.errors.reason }}
                    </div>
                    <div id="recall-dismiss-hint" class="form-text">
                        {{ tx('modal.dismiss.reason_hint', { max: 200 }) }}
                    </div>
                </template>

                <template v-else-if="dialog.kind === 'elsewhere'">
                    <p class="small text-muted">{{ t.modal.done_elsewhere.text }}</p>
                    <label class="form-label small mb-1" for="recall-elsewhere-date"
                        >{{ t.modal.done_elsewhere.date }} *</label
                    >
                    <input
                        id="recall-elsewhere-date"
                        v-model="elsewhereForm.date"
                        type="date"
                        class="form-control"
                        :class="{ 'is-invalid': elsewhereForm.errors.date }"
                        :max="today"
                        aria-describedby="recall-elsewhere-hint"
                        required
                    />
                    <div v-if="elsewhereForm.errors.date" class="invalid-feedback d-block" role="alert">
                        {{ elsewhereForm.errors.date }}
                    </div>
                    <div id="recall-elsewhere-hint" class="form-text">{{ t.modal.done_elsewhere.date_hint }}</div>
                </template>

                <template v-else-if="dialog.kind === 'reopen'">
                    <p class="mb-0">{{ t.modal.reopen.text }}</p>
                </template>

                <div v-else-if="dialog.kind === 'invite'" aria-live="polite" data-testid="invite-dialog">
                    <p v-if="inviteState === 'loading'" class="text-muted small mb-0" data-testid="invite-loading">
                        {{ t.modal.invite.loading }}
                    </p>
                    <p v-else-if="inviteState === 'error'" class="text-danger small mb-0" role="alert">
                        {{ t.modal.invite.load_error }}
                    </p>
                    <template v-else-if="invitePreview">
                        <p v-if="invitePreview.phone" class="small mb-2" data-testid="invite-phone">
                            {{ tx('modal.invite.to', { phone: invitePreview.phone }) }} ·
                            {{ tx('modal.invite.covers', { count: invitePreview.recalls }) }}
                        </p>

                        <div
                            v-if="invitePreview.blockers.length"
                            class="recall-notice recall-notice--warning"
                            role="alert"
                            data-testid="invite-blockers"
                        >
                            <p class="fw-semibold mb-1">{{ t.modal.invite.blocked }}</p>
                            <ul class="mb-2 ps-3">
                                <li v-for="blocker in invitePreview.blockers" :key="blocker.code">
                                    {{ blocker.label }}
                                </li>
                            </ul>
                            <p class="small mb-0">{{ t.modal.invite.blocked_hint }}</p>
                        </div>

                        <template v-if="invitePreview.message">
                            <h6 class="small text-uppercase text-muted mb-1">{{ t.modal.invite.message_title }}</h6>
                            <blockquote class="border-start border-3 ps-3 mb-2" data-testid="invite-message">
                                <p class="mb-1" style="white-space: pre-line">{{ invitePreview.message.body }}</p>
                                <p v-if="invitePreview.message.footer" class="small text-muted mb-0">
                                    {{ invitePreview.message.footer }}
                                </p>
                            </blockquote>
                            <p class="small mb-1">
                                {{ t.modal.invite.buttons }}:
                                <span
                                    v-for="label in invitePreview.message.buttons"
                                    :key="label"
                                    class="recall-tone recall-tone--info me-1"
                                    >{{ label }}</span
                                >
                            </p>
                            <p class="form-text mb-2">{{ t.modal.invite.neutral }}</p>
                        </template>

                        <p class="form-text mb-1" data-testid="invite-pacing">
                            {{
                                invitePreview.daily.cap === null
                                    ? tx('modal.invite.daily_open', { used: invitePreview.daily.used })
                                    : tx('modal.invite.daily', {
                                          used: invitePreview.daily.used,
                                          cap: invitePreview.daily.cap,
                                      })
                            }}
                            <template v-if="invitePreview.last_invite_at">
                                {{
                                    tx('modal.invite.last', {
                                        date: date(invitePreview.last_invite_at.substring(0, 10)),
                                    })
                                }}
                            </template>
                            <template v-if="invitePreview.next_allowed_on">
                                {{ tx('modal.invite.next', { date: date(invitePreview.next_allowed_on) }) }}
                            </template>
                        </p>
                        <p v-if="invitePreview.can_send" class="form-text mb-0">{{ t.modal.invite.sent_note }}</p>
                    </template>
                </div>
            </form>

            <template #footer>
                <button type="button" class="btn btn-light" :disabled="busy" @click="closeDialog">
                    {{ t.modal.cancel }}
                </button>
                <button
                    type="submit"
                    form="recall-dialog-form"
                    class="btn"
                    :class="dialog.kind === 'dismiss' ? 'btn-danger' : 'btn-primary'"
                    :disabled="busy || submitBlocked"
                    data-testid="recall-dialog-submit"
                >
                    {{ submitLabel }}
                </button>
            </template>
        </CenteredModal>
    </AppLayout>
</template>
