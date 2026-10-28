<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import SurgeryDocumentIssueModal from '@/Components/Surgery/SurgeryDocumentIssueModal.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

/**
 * Aba "Documentos": TODOS os documentos congelados do caso (qualquer etapa),
 * a situação da assinatura do paciente (cópia digitalizada anexada ou falta) e
 * a emissão de TCLE e orientações pré-operatórias. Documento emitido não se
 * edita: reemitir cria uma nova versão e a anterior vai para o histórico.
 */
const props = defineProps({
    section: { type: Object, required: true },
    surgicalCase: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
    urls: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
    t: { type: Object, required: true },
});

const emit = defineEmits(['reload']);

const { dateTime } = useLocaleFormat();
const docs = computed(() => props.t.documents ?? {});
const ui = computed(() => docs.value.ui ?? {});

function tx(text, params = {}) {
    return Object.entries(params).reduce((acc, [name, value]) => acc.replace(`:${name}`, String(value)), text ?? '');
}

const showHistory = ref(false);
const current = computed(() => props.section.documents.filter((d) => d.current));
const history = computed(() => props.section.documents.filter((d) => !d.current));
const rows = computed(() => (showHistory.value ? props.section.documents : current.value));

const notice = ref('');

// ── Emissão / reemissão ─────────────────────────────────────────────────
const issuing = ref(null); // { issuable, reissue }

function openIssue(issuable) {
    issuing.value = { issuable, reissue: null };
}

function openReissue(doc) {
    const issuable = props.section.issuable.find((i) => i.type === doc.type);
    if (!issuable) return;
    // Termo desatualizado (o pedido mudou depois de emitido): o texto antigo fala do pedido velho, então a
    // reemissão parte de uma prévia nova do modelo, não dele.
    if (doc.outdated_fields?.length) {
        issuing.value = { issuable, reissue: null };
        return;
    }
    // Reemissão parte do texto JÁ emitido (o servidor o entrega só nos vigentes reemissíveis).
    issuing.value = { issuable, reissue: { ...doc, content: doc.content ?? '' } };
}

function onIssued(result) {
    notice.value = result?.message ?? '';
    emit('reload');
}

// ── Cópia assinada ──────────────────────────────────────────────────────
const fileInput = ref(null);
const uploadTarget = ref(null);
const uploading = ref(null);
const uploadError = ref('');

function pickCopy(doc) {
    uploadTarget.value = doc;
    uploadError.value = '';
    fileInput.value?.click();
}

function onFile(event) {
    const file = event.target.files?.[0];
    const doc = uploadTarget.value;
    event.target.value = '';
    if (!file || !doc) return;

    if (file.size > props.section.signed_copy_max_mb * 1024 * 1024) {
        uploadError.value = tx(ui.value.copy_hint, { max: props.section.signed_copy_max_mb });
        return;
    }

    uploading.value = doc.id;
    router.post(
        doc.urls.signed_copy_store,
        { file },
        {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => emit('reload'),
            onError: (errors) => {
                uploadError.value = errors.file ?? ui.value.upload_failed;
            },
            onFinish: () => {
                uploading.value = null;
            },
        },
    );
}

const signatureState = (doc) => {
    if (!doc.requires_signature) return 'optional';
    // Termo que já não cobre o pedido (olho, procedimento... mudaram): a cópia assinada não vale mais.
    if (doc.outdated_fields?.length) return 'outdated';
    return doc.has_signed_copy ? 'signed' : 'unsigned';
};
</script>

<template>
    <div class="d-grid gap-3" data-testid="documents-tab">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <div class="me-auto">
                <h2 class="h6 mb-0">{{ ui.title }}</h2>
                <p class="text-muted small mb-0">{{ ui.subtitle }}</p>
            </div>
            <template v-if="section.can_issue">
                <button
                    v-for="issuable in section.issuable"
                    :key="issuable.type"
                    type="button"
                    class="btn btn-sm btn-primary"
                    :data-testid="`doc-issue-${issuable.type}`"
                    @click="openIssue(issuable)"
                >
                    <i class="ti ti-file-plus me-1" aria-hidden="true"></i
                    >{{ ui[`issue_${issuable.type}`] ?? ui.issue }}
                </button>
            </template>
            <a
                v-if="section.urls.templates"
                :href="section.urls.templates"
                class="btn btn-sm btn-outline-secondary"
                data-testid="doc-manage-templates"
                >{{ ui.manage_templates }}</a
            >
        </div>

        <div v-if="notice" class="alert alert-success py-2 mb-0" role="status" data-testid="doc-notice">
            {{ notice }}
        </div>

        <div
            v-if="section.pending_signature.length"
            class="alert alert-warning mb-0"
            role="alert"
            data-testid="doc-pending-alert"
        >
            <strong>{{ ui.pending_title }}</strong>
            <div>
                {{ tx(ui.pending_text, { list: section.pending_signature.map((d) => d.title).join('; ') }) }}
            </div>
        </div>

        <div
            v-if="section.outdated_consent"
            class="alert alert-danger mb-0"
            role="alert"
            data-testid="doc-outdated-alert"
        >
            <strong>{{ ui.outdated_title }}</strong>
            <div>{{ tx(ui.outdated_text, { fields: section.outdated_consent.fields }) }}</div>
        </div>

        <div v-if="uploadError" class="alert alert-danger py-2 mb-0" role="alert" data-testid="doc-upload-error">
            {{ uploadError }}
        </div>

        <p v-if="!section.documents.length" class="text-muted mb-0" data-testid="doc-empty">{{ ui.empty }}</p>

        <div v-else class="table-responsive">
            <table class="table table-sm align-middle mb-0" :aria-label="ui.title">
                <thead>
                    <tr>
                        <th scope="col">{{ ui.col_document }}</th>
                        <th scope="col">{{ ui.col_issued }}</th>
                        <th scope="col">{{ ui.col_signature }}</th>
                        <th scope="col" class="text-end">{{ ui.col_actions }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="doc in rows"
                        :key="doc.id"
                        :class="{ 'text-muted': !doc.current }"
                        data-testid="doc-row"
                        :data-current="doc.current ? '1' : '0'"
                    >
                        <td>
                            <div class="fw-medium">{{ doc.title }}</div>
                            <div class="small">
                                <span class="badge text-bg-light border">{{ doc.type_label }}</span>
                                <span v-if="!doc.current" class="badge text-bg-secondary ms-1">{{
                                    ui.superseded
                                }}</span>
                                <span v-else class="badge text-bg-success ms-1">{{ ui.current }}</span>
                            </div>
                        </td>
                        <td class="small">
                            <div>{{ dateTime(doc.issued_at) }}</div>
                            <div class="text-muted">
                                <template v-if="doc.doctor || doc.issuer">{{
                                    tx(ui.issued_by, { name: doc.doctor ?? doc.issuer })
                                }}</template>
                                <template v-if="doc.template_version">
                                    · {{ tx(ui.version, { version: doc.template_version }) }}</template
                                >
                            </div>
                        </td>
                        <td>
                            <span
                                class="badge"
                                :class="{
                                    'text-bg-success': signatureState(doc) === 'signed',
                                    'text-bg-warning': signatureState(doc) === 'unsigned',
                                    'text-bg-danger': signatureState(doc) === 'outdated',
                                    'text-bg-light border': signatureState(doc) === 'optional',
                                }"
                                :data-testid="`doc-signature-${signatureState(doc)}`"
                            >
                                {{
                                    signatureState(doc) === 'signed'
                                        ? ui.signed
                                        : signatureState(doc) === 'unsigned'
                                          ? ui.unsigned
                                          : signatureState(doc) === 'outdated'
                                            ? ui.outdated
                                            : ui.signature_optional
                                }}
                            </span>
                            <div v-if="doc.signed_copy" class="small text-muted text-truncate" style="max-width: 16rem">
                                {{ doc.signed_copy.name }} · {{ dateTime(doc.signed_copy.uploaded_at) }}
                            </div>
                        </td>
                        <td class="text-end text-nowrap">
                            <a
                                :href="doc.urls.pdf"
                                target="_blank"
                                rel="noopener"
                                class="btn btn-sm btn-outline-secondary me-1"
                                data-testid="doc-pdf"
                                ><i class="ti ti-file-type-pdf me-1" aria-hidden="true"></i>{{ ui.open_pdf }}</a
                            >
                            <a
                                v-if="doc.has_signed_copy"
                                :href="doc.urls.signed_copy"
                                target="_blank"
                                rel="noopener"
                                class="btn btn-sm btn-outline-secondary me-1"
                                data-testid="doc-view-copy"
                                >{{ ui.view_copy }}</a
                            >
                            <button
                                v-if="section.can_attach && doc.current && !doc.copy_locked"
                                type="button"
                                class="btn btn-sm btn-outline-primary me-1"
                                :disabled="uploading === doc.id"
                                data-testid="doc-attach"
                                @click="pickCopy(doc)"
                            >
                                {{
                                    uploading === doc.id
                                        ? ui.uploading
                                        : doc.has_signed_copy
                                          ? ui.replace_copy
                                          : ui.attach_copy
                                }}
                            </button>
                            <button
                                v-if="section.can_issue && doc.can_reissue"
                                type="button"
                                class="btn btn-sm btn-outline-warning"
                                data-testid="doc-reissue"
                                @click="openReissue(doc)"
                            >
                                {{ ui.reissue }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <button
            v-if="history.length"
            type="button"
            class="btn btn-link btn-sm align-self-start p-0"
            data-testid="doc-history-toggle"
            @click="showHistory = !showHistory"
        >
            {{ showHistory ? ui.hide_history : tx(ui.show_history, { count: history.length }) }}
        </button>

        <p class="text-muted small mb-0">{{ tx(ui.copy_hint, { max: section.signed_copy_max_mb }) }}</p>

        <input
            ref="fileInput"
            type="file"
            class="d-none"
            accept="application/pdf,image/jpeg,image/png"
            data-testid="doc-file-input"
            @change="onFile"
        />

        <SurgeryDocumentIssueModal
            :open="issuing !== null"
            :issuable="issuing?.issuable ?? null"
            :reissue="issuing?.reissue ?? null"
            :urls="section.urls"
            :t="docs"
            @close="issuing = null"
            @issued="onIssued"
        />
    </div>
</template>
