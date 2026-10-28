<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import ConfirmationWithReasonModal from '@/Components/Panel/ConfirmationWithReasonModal.vue';
import ReviewerFormModal from '@/Components/ClinicalReview/ReviewerFormModal.vue';
import ReviewInvitationFormModal from '@/Components/ClinicalReview/ReviewInvitationFormModal.vue';
import ReviewOpinionModal from '@/Components/ClinicalReview/ReviewOpinionModal.vue';
import { makeTx, NEUTRAL_BADGE } from '@/Support/recallRule.js';
import { groupedHash } from '@/Support/clinicalReview.js';
import { useLocaleFormat } from '@/composables/useLocaleFormat';

/**
 * Manager → Revisões clínicas: os Revisores (cadastro) e os convites de revisão dos
 * protocolos de retorno (ADR 0002). O link do convite NUNCA aparece aqui — vai por e-mail
 * ao Revisor; o código de acesso gerado pelo sistema aparece UMA vez, depois do envio, para
 * ser passado por outro canal.
 */
defineOptions({ name: 'ManagerClinicalReviewsIndex' });

const props = defineProps({
    reviewers: { type: Array, default: () => [] },
    invitations: { type: Array, default: () => [] },
    reviewableVersions: { type: Array, default: () => [] },
    issuedAccessCode: { type: String, default: null },
    options: { type: Object, required: true },
    t: { type: Object, default: () => ({}) },
});

const tx = makeTx(() => props.t);
const { dateTime } = useLocaleFormat();

const TABS = ['invitations', 'reviewers'];
const tab = ref('invitations');

const STATUS_CLASS = {
    pending: 'text-bg-info',
    submitted: 'text-bg-primary',
    accepted: 'text-bg-success',
    refused: 'text-bg-danger',
    expired: NEUTRAL_BADGE,
    revoked: 'text-bg-dark',
};
const DECISION_CLASS = {
    approved: 'text-bg-success',
    changes_requested: 'text-bg-warning',
    rejected: 'text-bg-danger',
};

const statusClass = (row) => (row.is_expired ? 'text-bg-warning' : (STATUS_CLASS[row.status] ?? NEUTRAL_BADGE));
const statusText = (row) => (row.is_expired ? tx('invitations.expired') : row.status_label);

function onTabKey(event, index) {
    const delta = { ArrowRight: 1, ArrowLeft: -1 }[event.key];
    if (!delta) return;

    event.preventDefault();
    const next = TABS[(index + delta + TABS.length) % TABS.length];
    tab.value = next;
    document.getElementById(`reviews-tab-${next}`)?.focus();
}

// ── modais ──────────────────────────────────────────────────────────────────
const reviewerModal = ref({ open: false, reviewer: null });
const invitationModalOpen = ref(false);
const resending = ref({ open: false, invitation: null, busy: false });
const revoking = ref({ open: false, invitation: null, busy: false });
const toggling = ref({ open: false, reviewer: null, busy: false });
// O parecer em conferência: guarda só o id e relê a linha — depois do aceite a página recarrega e a linha traz o resultado.
const opinionId = ref(null);
const opinion = computed(() => props.invitations.find((row) => row.id === opinionId.value) ?? null);

function resend() {
    const { invitation } = resending.value;
    resending.value.busy = true;

    router.post(
        route('manager.clinical-reviews.resend', invitation.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => (resending.value = { open: false, invitation: null, busy: false }),
        },
    );
}

function revoke(reason) {
    const { invitation } = revoking.value;
    revoking.value.busy = true;

    router.post(
        route('manager.clinical-reviews.revoke', invitation.id),
        { reason },
        {
            preserveScroll: true,
            onFinish: () => (revoking.value = { open: false, invitation: null, busy: false }),
        },
    );
}

function toggleActive() {
    const { reviewer } = toggling.value;
    toggling.value.busy = true;

    router.post(
        route('manager.clinical-reviewers.active', reviewer.id),
        { active: !reviewer.active },
        {
            preserveScroll: true,
            onFinish: () => (toggling.value = { open: false, reviewer: null, busy: false }),
        },
    );
}

// ── código de acesso gerado (aparece uma vez) ───────────────────────────────
const pinOpen = ref(Boolean(props.issuedAccessCode));
const pinCopied = ref(false);

watch(
    () => props.issuedAccessCode,
    (code) => {
        if (code) pinOpen.value = true;
    },
);

async function copyPin() {
    try {
        await navigator.clipboard.writeText(props.issuedAccessCode ?? '');
        pinCopied.value = true;
        setTimeout(() => (pinCopied.value = false), 2000);
    } catch {
        pinCopied.value = false;
    }
}

const canInvite = computed(() => props.reviewers.some((reviewer) => reviewer.active));
</script>

<template>
    <AppLayout :title="tx('title')" :breadcrumbs="[]">
        <div class="container-fluid py-3">
            <PageHeader :title="tx('title')" :subtitle="tx('subtitle')">
                <template #actions>
                    <button
                        type="button"
                        class="btn btn-outline-secondary btn-sm"
                        data-testid="new-reviewer"
                        @click="reviewerModal = { open: true, reviewer: null }"
                    >
                        <i class="ti ti-user-plus me-1" aria-hidden="true"></i>{{ tx('reviewers.new') }}
                    </button>
                    <button
                        type="button"
                        class="btn btn-primary btn-sm"
                        :disabled="!canInvite"
                        data-testid="new-invitation"
                        @click="invitationModalOpen = true"
                    >
                        <i class="ti ti-send me-1" aria-hidden="true"></i>{{ tx('invitations.new') }}
                    </button>
                </template>
            </PageHeader>

            <div class="alert alert-info small" role="note">
                <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ tx('intro') }}
            </div>

            <div class="nav nav-tabs mb-3" role="tablist" :aria-label="tx('title')">
                <button
                    v-for="(name, index) in TABS"
                    :id="`reviews-tab-${name}`"
                    :key="name"
                    type="button"
                    class="nav-link"
                    :class="{ active: tab === name }"
                    role="tab"
                    :aria-selected="tab === name ? 'true' : 'false'"
                    :aria-controls="`reviews-panel-${name}`"
                    :tabindex="tab === name ? 0 : -1"
                    :data-testid="`tab-${name}`"
                    @click="tab = name"
                    @keydown="onTabKey($event, index)"
                >
                    {{ tx(`tabs.${name}`) }}
                </button>
            </div>

            <!-- Convites -->
            <div
                v-show="tab === 'invitations'"
                id="reviews-panel-invitations"
                role="tabpanel"
                aria-labelledby="reviews-tab-invitations"
                tabindex="0"
            >
                <p v-if="!reviewableVersions.length" class="small text-warning-emphasis" data-testid="no-versions">
                    <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>{{ tx('invitations.no_versions') }}
                </p>

                <p v-if="!invitations.length" class="text-body-secondary" data-testid="invitations-empty">
                    {{ tx('invitations.empty') }}
                </p>

                <div v-else class="table-responsive">
                    <table class="table align-middle" data-testid="invitations-table">
                        <thead>
                            <tr>
                                <th scope="col">{{ tx('invitations.columns.reviewer') }}</th>
                                <th scope="col">{{ tx('invitations.columns.versions') }}</th>
                                <th scope="col">{{ tx('invitations.columns.status') }}</th>
                                <th scope="col">{{ tx('invitations.columns.sent') }}</th>
                                <th scope="col">{{ tx('invitations.columns.expires') }}</th>
                                <th scope="col">{{ tx('invitations.columns.opened') }}</th>
                                <th scope="col" class="text-end">{{ tx('invitations.columns.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in invitations" :key="row.id" :data-testid="`invitation-${row.id}`">
                                <th scope="row" class="fw-medium">
                                    {{ row.reviewer.name }}
                                    <small
                                        v-if="row.reviewer.crm_label"
                                        class="d-block fw-normal text-body-secondary"
                                        >{{ row.reviewer.crm_label }}</small
                                    >
                                    <small v-if="row.pin_required" class="d-block fw-normal text-body-secondary">
                                        <i class="ti ti-lock me-1" aria-hidden="true"></i>{{ tx('invitations.pin') }}
                                    </small>
                                </th>
                                <td>
                                    <ul class="list-unstyled mb-0">
                                        <li v-for="item in row.items" :key="item.id" class="mb-1">
                                            {{ item.title }}
                                            <span class="text-body-secondary">v{{ item.version }}</span>
                                            <span
                                                v-if="item.decision"
                                                class="badge fw-normal ms-1"
                                                :class="DECISION_CLASS[item.decision]"
                                                >{{ item.decision_label }}</span
                                            >
                                        </li>
                                    </ul>
                                </td>
                                <td>
                                    <span class="badge fw-normal" :class="statusClass(row)">{{ statusText(row) }}</span>
                                    <small
                                        v-if="row.revoked_reason"
                                        class="d-block text-body-secondary text-break mt-1"
                                        >{{ row.revoked_reason }}</small
                                    >
                                </td>
                                <td>
                                    <template v-if="row.sent_at">{{ dateTime(row.sent_at) }}</template>
                                    <template v-else>
                                        <span class="text-body-secondary">{{ tx('invitations.not_sent') }}</span>
                                        <small v-if="row.last_send_error" class="d-block text-danger" role="status">
                                            <i class="ti ti-mail-x me-1" aria-hidden="true"></i
                                            >{{ tx('invitations.send_failed') }}
                                        </small>
                                    </template>
                                </td>
                                <td>{{ dateTime(row.expires_at) }}</td>
                                <td>
                                    <template v-if="row.open_count">
                                        {{ tx('invitations.opened_times', { count: row.open_count }) }}
                                        <small class="d-block text-body-secondary">{{
                                            dateTime(row.last_opened_at)
                                        }}</small>
                                    </template>
                                    <span v-else class="text-body-secondary">{{ tx('invitations.never_opened') }}</span>
                                </td>
                                <td class="text-end text-nowrap">
                                    <button
                                        v-if="row.attestation"
                                        type="button"
                                        class="btn btn-sm me-1"
                                        :class="row.can_accept ? 'btn-success' : 'btn-outline-primary'"
                                        :data-testid="`opinion-${row.id}`"
                                        @click="opinionId = row.id"
                                    >
                                        <i class="ti ti-file-search me-1" aria-hidden="true"></i
                                        >{{ row.can_accept ? tx('acceptance.open') : tx('acceptance.view') }}
                                    </button>
                                    <button
                                        v-if="row.can_resend"
                                        type="button"
                                        class="btn btn-outline-secondary btn-sm me-1"
                                        :data-testid="`resend-${row.id}`"
                                        @click="resending = { open: true, invitation: row, busy: false }"
                                    >
                                        <i class="ti ti-refresh me-1" aria-hidden="true"></i
                                        >{{ tx('invitations.resend') }}
                                    </button>
                                    <button
                                        v-if="row.can_revoke"
                                        type="button"
                                        class="btn btn-outline-danger btn-sm"
                                        :data-testid="`revoke-${row.id}`"
                                        @click="revoking = { open: true, invitation: row, busy: false }"
                                    >
                                        <i class="ti ti-ban me-1" aria-hidden="true"></i>{{ tx('invitations.revoke') }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Revisores -->
            <div
                v-show="tab === 'reviewers'"
                id="reviews-panel-reviewers"
                role="tabpanel"
                aria-labelledby="reviews-tab-reviewers"
                tabindex="0"
            >
                <p v-if="!reviewers.length" class="text-body-secondary" data-testid="reviewers-empty">
                    {{ tx('reviewers.empty') }}
                </p>

                <div v-else class="table-responsive">
                    <table class="table align-middle" data-testid="reviewers-table">
                        <thead>
                            <tr>
                                <th scope="col">{{ tx('reviewers.columns.name') }}</th>
                                <th scope="col">{{ tx('reviewers.columns.crm') }}</th>
                                <th scope="col">{{ tx('reviewers.columns.rqe') }}</th>
                                <th scope="col">{{ tx('reviewers.columns.email') }}</th>
                                <th scope="col" class="text-end">{{ tx('reviewers.columns.invitations') }}</th>
                                <th scope="col">{{ tx('reviewers.columns.status') }}</th>
                                <th scope="col" class="text-end">{{ tx('reviewers.columns.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="reviewer in reviewers"
                                :key="reviewer.id"
                                :data-testid="`reviewer-${reviewer.id}`"
                            >
                                <th scope="row" class="fw-medium">
                                    {{ reviewer.name }}
                                    <small v-if="reviewer.specialty" class="d-block fw-normal text-body-secondary">{{
                                        reviewer.specialty
                                    }}</small>
                                </th>
                                <td>{{ reviewer.crm_number }}/{{ reviewer.crm_uf }}</td>
                                <td>{{ reviewer.rqe }}</td>
                                <td class="text-break">{{ reviewer.email }}</td>
                                <td class="text-end">{{ reviewer.invitations_count }}</td>
                                <td>
                                    <span
                                        class="badge fw-normal"
                                        :class="reviewer.active ? 'text-bg-success' : NEUTRAL_BADGE"
                                        >{{ reviewer.active ? tx('reviewers.active') : tx('reviewers.inactive') }}</span
                                    >
                                </td>
                                <td class="text-end text-nowrap">
                                    <button
                                        type="button"
                                        class="btn btn-outline-secondary btn-sm me-1"
                                        :data-testid="`edit-${reviewer.id}`"
                                        @click="reviewerModal = { open: true, reviewer }"
                                    >
                                        <i class="ti ti-pencil me-1" aria-hidden="true"></i>{{ tx('reviewers.edit') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-sm"
                                        :class="reviewer.active ? 'btn-outline-danger' : 'btn-outline-success'"
                                        :data-testid="`toggle-${reviewer.id}`"
                                        @click="toggling = { open: true, reviewer, busy: false }"
                                    >
                                        {{ reviewer.active ? tx('reviewers.deactivate') : tx('reviewers.activate') }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <ReviewerFormModal
            :open="reviewerModal.open"
            :reviewer="reviewerModal.reviewer"
            :ufs="options.ufs"
            :t="t"
            @close="reviewerModal.open = false"
        />

        <ReviewInvitationFormModal
            :open="invitationModalOpen"
            :reviewers="reviewers"
            :versions="reviewableVersions"
            :expiry-days="options.expiry_days"
            :default-expiry="options.default_expiry"
            :t="t"
            @close="invitationModalOpen = false"
        />

        <!-- o parecer: conferir e registrar o aceite -->
        <ReviewOpinionModal :open="opinionId !== null" :invitation="opinion" :t="t" @close="opinionId = null" />

        <!-- reenviar -->
        <CenteredModal :open="resending.open" size="md" @close="resending.open = false">
            <template #header>
                <h5 class="mb-0 fw-semibold">{{ tx('resend_modal.title') }}</h5>
            </template>
            <p class="mb-0">
                {{ tx('resend_modal.body', { name: resending.invitation?.reviewer?.name ?? '' }) }}
            </p>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="resending.busy" @click="resending.open = false">
                    {{ tx('resend_modal.cancel') }}
                </button>
                <button
                    type="button"
                    class="btn btn-primary"
                    :disabled="resending.busy"
                    data-testid="resend-confirm"
                    @click="resend"
                >
                    <span v-if="resending.busy" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                    {{ tx('resend_modal.confirm') }}
                </button>
            </template>
        </CenteredModal>

        <!-- revogar (exige o motivo) -->
        <ConfirmationWithReasonModal
            :open="revoking.open"
            :title="tx('revoke_modal.title')"
            :message="tx('revoke_modal.body')"
            :confirm-label="tx('revoke_modal.confirm')"
            :min-length="10"
            :saving="revoking.busy"
            @close="revoking.open = false"
            @confirm="revoke"
        />

        <!-- ativar / desativar Revisor -->
        <CenteredModal :open="toggling.open" size="md" @close="toggling.open = false">
            <template #header>
                <h5 class="mb-0 fw-semibold">
                    {{ toggling.reviewer?.active ? tx('reviewers.deactivate') : tx('reviewers.activate') }}
                </h5>
            </template>
            <p class="mb-0">
                {{ toggling.reviewer?.active ? tx('reviewers.deactivate_confirm') : tx('reviewers.activate_confirm') }}
            </p>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="toggling.busy" @click="toggling.open = false">
                    {{ tx('reviewer_form.cancel') }}
                </button>
                <button
                    type="button"
                    class="btn"
                    :class="toggling.reviewer?.active ? 'btn-danger' : 'btn-success'"
                    :disabled="toggling.busy"
                    data-testid="toggle-confirm"
                    @click="toggleActive"
                >
                    {{ toggling.reviewer?.active ? tx('reviewers.deactivate') : tx('reviewers.activate') }}
                </button>
            </template>
        </CenteredModal>

        <!-- código de acesso gerado: aparece uma vez -->
        <CenteredModal :open="pinOpen && Boolean(issuedAccessCode)" size="sm" @close="pinOpen = false">
            <template #header>
                <h5 class="mb-0 fw-semibold">{{ tx('issued_pin.title') }}</h5>
            </template>
            <p>{{ tx('issued_pin.body') }}</p>
            <p class="text-center mb-2">
                <code class="fs-3 user-select-all" data-testid="issued-pin">{{ groupedHash(issuedAccessCode) }}</code>
            </p>
            <div class="text-center">
                <button type="button" class="btn btn-outline-secondary btn-sm" @click="copyPin">
                    {{ pinCopied ? tx('issued_pin.copied') : tx('issued_pin.copy') }}
                </button>
            </div>
            <template #footer>
                <button type="button" class="btn btn-primary" data-testid="issued-pin-close" @click="pinOpen = false">
                    {{ tx('issued_pin.close') }}
                </button>
            </template>
        </CenteredModal>
    </AppLayout>
</template>
