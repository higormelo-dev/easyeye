<script setup>
import { computed, ref, watch } from 'vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

const props = defineProps({
    documents: { type: Array, default: () => [] },
    t: { type: Object, default: () => ({}) },
    busyId: { type: [String, Number], default: null },
    actionSuccess: { type: Number, default: 0 },
});
const emit = defineEmits(['action']);
const { money, dateTime } = useLocaleFormat();
const modal = ref(null);
const reason = ref('');
const emails = ref('');
const modalError = ref('');
const modalPending = ref(false);
const title = computed(() =>
    modal.value?.kind === 'cancel'
        ? props.t.documents?.cancel
        : modal.value?.kind === 'resend'
          ? props.t.documents?.resend_email
          : props.t.documents?.mark_cancelled_externally,
);
function documentError(document) {
    if (Array.isArray(document.errors)) return document.errors.map((error) => error.mensagem ?? error.codigo).join(' ');
    return typeof document.errors === 'object' ? Object.values(document.errors).flat().join(' ') : document.errors;
}
function open(kind, document) {
    modal.value = { kind, document };
    reason.value = '';
    emails.value = '';
    modalError.value = '';
    modalPending.value = false;
}
function submitModal() {
    const { kind, document } = modal.value;
    const value = reason.value.trim();
    if (kind === 'cancel' && (value.length < 15 || value.length > 255)) {
        modalError.value = props.t.documents?.cancel_validation;
        return;
    }
    if (kind === 'mark_cancelled_externally' && (value.length < 20 || value.length > 255)) {
        modalError.value = props.t.documents?.reason_validation;
        return;
    }
    const payload =
        kind === 'resend'
            ? {
                  emails: emails.value
                      .split(',')
                      .map((item) => item.trim())
                      .filter(Boolean),
              }
            : kind === 'cancel'
              ? { justification: value }
              : { reason: value };
    modalPending.value = true;
    emit('action', { document, kind, payload });
}
watch(
    () => props.actionSuccess,
    () => {
        if (modalPending.value) modal.value = null;
    },
);
watch(
    () => props.busyId,
    (id) => {
        if (modalPending.value && id === null) modalPending.value = false;
    },
);
function run(document, kind) {
    if (['cancel', 'mark_cancelled_externally', 'resend'].includes(kind)) return open(kind, document);
    emit('action', { document, kind, payload: {} });
}
</script>

<template>
    <div class="card">
        <div class="card-header bg-transparent">
            <h2 class="h6 mb-0"><i class="ti ti-file-invoice me-2" aria-hidden="true"></i>{{ t.page?.documents }}</h2>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>{{ t.documents?.reference }}</th>
                        <th>{{ t.documents?.entity }}</th>
                        <th>{{ t.documents?.number }}</th>
                        <th>{{ t.documents?.amount }}</th>
                        <th>{{ t.documents?.status }}</th>
                        <th class="text-end">
                            <span class="visually-hidden">{{ t.actions?.save }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="document in documents" :key="document.id">
                        <td>
                            <span class="d-block">{{ document.invoice_reference ?? document.ref }}</span
                            ><small class="text-muted">{{
                                document.standard && t.standard?.[document.standard]
                            }}</small>
                        </td>
                        <td>{{ document.entity?.name }}</td>
                        <td>
                            <span class="d-block">{{ document.numero || '—' }}</span
                            ><small v-if="document.codigo_verificacao" class="text-muted">{{
                                document.codigo_verificacao
                            }}</small>
                        </td>
                        <td>
                            <span class="d-block">{{ money(document.amount, document.currency ?? 'BRL') }}</span
                            ><small v-if="document.issued_at" class="text-muted">{{
                                dateTime(document.issued_at)
                            }}</small>
                        </td>
                        <td>
                            <span class="badge" :class="document.status_badge">{{
                                document.status_label ?? t.status?.[document.status]
                            }}</span
                            ><span v-if="document.cancel_required" class="badge badge-soft-warning ms-1">{{
                                t.documents?.cancel_required
                            }}</span>
                            <small v-if="document.cancel_required_reason" class="d-block text-warning-emphasis mt-1">{{
                                document.cancel_required_reason
                            }}</small>
                            <small v-if="document.errors" class="d-block text-danger mt-1">{{
                                documentError(document)
                            }}</small>
                        </td>
                        <td>
                            <div class="dropdown text-end">
                                <button
                                    :id="`nfse-actions-${document.id}`"
                                    class="btn btn-sm btn-outline-secondary dropdown-toggle"
                                    type="button"
                                    data-bs-toggle="dropdown"
                                    :aria-label="`${t.documents?.reference}: ${document.ref}`"
                                >
                                    <i class="ti ti-dots-vertical" aria-hidden="true"></i>
                                </button>
                                <ul
                                    class="dropdown-menu dropdown-menu-end"
                                    :aria-labelledby="`nfse-actions-${document.id}`"
                                >
                                    <li v-if="document.urls?.pdf">
                                        <a
                                            class="dropdown-item"
                                            :href="document.urls.pdf"
                                            target="_blank"
                                            rel="noopener"
                                            ><i class="ti ti-file-type-pdf me-2" aria-hidden="true"></i
                                            >{{ t.documents?.download_pdf }}</a
                                        >
                                    </li>
                                    <li v-if="document.urls?.xml">
                                        <a
                                            class="dropdown-item"
                                            :href="document.urls.xml"
                                            target="_blank"
                                            rel="noopener"
                                            ><i class="ti ti-file-code me-2" aria-hidden="true"></i
                                            >{{ t.documents?.download_xml }}</a
                                        >
                                    </li>
                                    <li v-if="document.urls?.cancel_xml">
                                        <a
                                            class="dropdown-item"
                                            :href="document.urls.cancel_xml"
                                            target="_blank"
                                            rel="noopener"
                                            >{{ t.documents?.download_cancel_xml }}</a
                                        >
                                    </li>
                                    <li v-if="document.urls?.mirror">
                                        <a
                                            class="dropdown-item"
                                            :href="document.urls.mirror"
                                            target="_blank"
                                            rel="noopener"
                                            >{{ t.documents?.mirror }}</a
                                        >
                                    </li>
                                    <li v-if="document.actions?.issue">
                                        <button
                                            class="dropdown-item"
                                            type="button"
                                            :disabled="busyId === document.id"
                                            @click="run(document, 'issue')"
                                        >
                                            {{ t.documents?.issue }}
                                        </button>
                                    </li>
                                    <li v-if="document.actions?.sync">
                                        <button
                                            class="dropdown-item"
                                            type="button"
                                            :disabled="busyId === document.id"
                                            @click="run(document, 'sync')"
                                        >
                                            {{ t.documents?.sync }}
                                        </button>
                                    </li>
                                    <li v-if="document.actions?.resend_email">
                                        <button
                                            class="dropdown-item"
                                            type="button"
                                            :disabled="busyId === document.id"
                                            @click="run(document, 'resend')"
                                        >
                                            {{ t.documents?.resend_email }}
                                        </button>
                                    </li>
                                    <li v-if="document.actions?.cancel">
                                        <button
                                            class="dropdown-item text-danger"
                                            type="button"
                                            :disabled="busyId === document.id"
                                            @click="run(document, 'cancel')"
                                        >
                                            {{ t.documents?.cancel }}
                                        </button>
                                    </li>
                                    <li v-if="document.actions?.mark_cancelled_externally">
                                        <button
                                            class="dropdown-item text-danger"
                                            type="button"
                                            :disabled="busyId === document.id"
                                            @click="run(document, 'mark_cancelled_externally')"
                                        >
                                            {{ t.documents?.mark_cancelled_externally }}
                                        </button>
                                    </li>
                                </ul>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!documents.length">
                        <td colspan="6" class="text-center text-muted py-4">{{ t.page?.empty_documents }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <CenteredModal :open="!!modal" :close-label="t.actions?.close" @close="modal = null"
        ><template #header
            ><h2 id="nfse-document-action-title" class="h5 mb-0">{{ title }}</h2></template
        >
        <form id="nfse-document-action-form" @submit.prevent="submitModal">
            <template v-if="modal?.kind === 'resend'">
                <label for="nfse-emails" class="form-label">{{ t.documents?.emails }}</label>
                <input id="nfse-emails" v-model="emails" class="form-control" type="email" multiple />
                <div class="form-text">{{ t.documents?.emails_hint }}</div>
            </template>
            <template v-else>
                <label for="nfse-action-reason" class="form-label">{{
                    modal?.kind === 'cancel' ? t.documents?.justification : t.documents?.reason
                }}</label>
                <textarea
                    id="nfse-action-reason"
                    v-model="reason"
                    class="form-control"
                    rows="3"
                    :minlength="modal?.kind === 'mark_cancelled_externally' ? 20 : 15"
                    maxlength="255"
                    required
                ></textarea>
            </template>
            <div v-if="modalError" class="invalid-feedback d-block mt-2">{{ modalError }}</div>
        </form>
        <template #footer
            ><button type="button" class="btn btn-outline-secondary" @click="modal = null">
                {{ t.actions?.cancel }}</button
            ><button type="submit" form="nfse-document-action-form" class="btn btn-primary" :disabled="modalPending">
                {{ t.actions?.save }}
            </button></template
        ></CenteredModal
    >
</template>
