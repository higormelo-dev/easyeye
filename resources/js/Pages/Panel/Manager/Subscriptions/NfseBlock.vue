<script setup>
import { computed, ref } from 'vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';

const props = defineProps({ nfse: { type: Object, default: null }, t: { type: Object, default: () => ({}) } });
const emit = defineEmits(['updated']);
const modal = ref(null);
const value = ref('');
const error = ref('');
const saving = ref(false);
const actions = computed(() => props.nfse?.actions ?? {});
const lastAttempt = computed(() => props.nfse?.last_attempt ?? null);
const title = computed(
    () => props.t?.[modal.value?.kind === 'mark_cancelled_externally' ? 'mark_cancelled' : modal.value?.kind] ?? '',
);

function formatErrors(errors) {
    if (!errors) return '';
    if (Array.isArray(errors)) {
        return errors
            .map((item) => (typeof item === 'object' ? (item.mensagem ?? item.codigo) : item))
            .filter(Boolean)
            .join(' ');
    }
    if (typeof errors === 'object') return formatErrors(Object.values(errors).flat());
    return String(errors);
}

function open(kind) {
    modal.value = { kind };
    value.value = '';
    error.value = '';
}
function payload(kind) {
    if (['issue', 'sync'].includes(kind)) return {};
    if (kind === 'resend_email')
        return {
            emails: value.value
                .split(',')
                .map((email) => email.trim())
                .filter(Boolean),
        };
    return kind === 'cancel' ? { justification: value.value.trim() } : { reason: value.value.trim() };
}
function valid(kind) {
    const length = value.value.trim().length;
    if (kind === 'cancel') return length >= 15 && length <= 255;
    if (kind === 'mark_cancelled_externally') return length >= 20 && length <= 255;
    return kind === 'resend_email' ? payload(kind).emails.length > 0 : true;
}
async function run(kind) {
    const url = actions.value[kind];
    if (!url) return;
    if (['cancel', 'mark_cancelled_externally', 'resend_email'].includes(kind)) return open(kind);
    await send(kind);
}
async function send(kind = modal.value?.kind) {
    if (!valid(kind) || saving.value) {
        error.value = kind === 'cancel' ? props.t.cancel_validation : props.t.reason_validation;
        return;
    }
    saving.value = true;
    error.value = '';
    try {
        const { data } = await window.axios.post(actions.value[kind], payload(kind), {
            headers: { Accept: 'application/json' },
        });
        window.showSuccessToast?.(data?.message);
        modal.value = null;
        emit('updated');
    } catch (requestError) {
        const data = requestError?.response?.data ?? {};
        error.value = formatErrors(data?.errors) || data?.message;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <section v-if="nfse?.status === null" class="mt-2" data-test="manager-nfse-block">
        <button v-if="actions.issue" type="button" class="btn btn-sm btn-outline-primary" @click="run('issue')">
            {{ t.issue }}
        </button>
        <div v-if="lastAttempt" class="border rounded p-3 mt-2">
            <div class="d-flex justify-content-between gap-2 align-items-start">
                <span class="badge" :class="lastAttempt.status_badge">{{ lastAttempt.status_label }}</span>
                <div class="btn-group">
                    <a
                        v-if="lastAttempt.urls?.pdf"
                        :href="lastAttempt.urls.pdf"
                        target="_blank"
                        rel="noopener"
                        class="btn btn-sm btn-outline-secondary"
                        >{{ t.download_pdf }}</a
                    ><a
                        v-if="lastAttempt.urls?.xml"
                        :href="lastAttempt.urls.xml"
                        target="_blank"
                        rel="noopener"
                        class="btn btn-sm btn-outline-secondary"
                        >{{ t.download_xml }}</a
                    ><a
                        v-if="lastAttempt.urls?.cancel_xml"
                        :href="lastAttempt.urls.cancel_xml"
                        target="_blank"
                        rel="noopener"
                        class="btn btn-sm btn-outline-secondary"
                        >{{ t.download_cancel_xml }}</a
                    ><a
                        v-if="lastAttempt.urls?.mirror"
                        :href="lastAttempt.urls.mirror"
                        target="_blank"
                        rel="noopener"
                        class="btn btn-sm btn-outline-secondary"
                        >{{ t.view_mirror }}</a
                    >
                </div>
            </div>
            <dl class="row small mb-0 mt-3">
                <dt class="col-sm-4">{{ t.number }}</dt>
                <dd class="col-sm-8">{{ lastAttempt.numero ?? '—' }}</dd>
            </dl>
            <p v-if="lastAttempt.errors" class="small text-danger mt-2 mb-0">
                {{ formatErrors(lastAttempt.errors) }}
            </p>
        </div>
    </section>
    <section v-else-if="nfse" class="border rounded p-3 mt-3" data-test="manager-nfse-block">
        <div class="d-flex justify-content-between gap-2 align-items-start">
            <div>
                <h3 class="h6 mb-1">{{ t.title }}</h3>
                <span class="badge" :class="nfse.status_badge">{{ nfse.status_label }}</span>
            </div>
            <div class="btn-group">
                <a
                    v-if="nfse.urls?.pdf"
                    :href="nfse.urls.pdf"
                    target="_blank"
                    rel="noopener"
                    class="btn btn-sm btn-outline-secondary"
                    >{{ t.download_pdf }}</a
                ><a
                    v-if="nfse.urls?.xml"
                    :href="nfse.urls.xml"
                    target="_blank"
                    rel="noopener"
                    class="btn btn-sm btn-outline-secondary"
                    >{{ t.download_xml }}</a
                >
            </div>
        </div>
        <dl class="row small mb-0 mt-3">
            <dt class="col-sm-4">{{ t.number }}</dt>
            <dd class="col-sm-8">{{ nfse.numero ?? '—' }}</dd>
            <dt class="col-sm-4">{{ t.status }}</dt>
            <dd class="col-sm-8">{{ nfse.status_label }}</dd>
        </dl>
        <div v-if="nfse.partial_refund_warning" class="alert alert-warning small py-2 mb-2">
            {{ t.partial_refund_warning }}
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button v-if="actions.issue" type="button" class="btn btn-sm btn-outline-primary" @click="run('issue')">
                {{ t.issue }}</button
            ><button v-if="actions.sync" type="button" class="btn btn-sm btn-outline-secondary" @click="run('sync')">
                {{ t.sync }}</button
            ><button
                v-if="actions.resend_email"
                type="button"
                class="btn btn-sm btn-outline-secondary"
                @click="run('resend_email')"
            >
                {{ t.resend_email }}</button
            ><button v-if="actions.cancel" type="button" class="btn btn-sm btn-outline-danger" @click="run('cancel')">
                {{ t.cancel }}</button
            ><button
                v-if="actions.mark_cancelled_externally"
                type="button"
                class="btn btn-sm btn-outline-danger"
                @click="run('mark_cancelled_externally')"
            >
                {{ t.mark_cancelled }}
            </button>
        </div>
        <p v-if="nfse.cancel_required_reason" class="small text-warning-emphasis mt-2 mb-0">
            {{ nfse.cancel_required_reason }}
        </p>
        <p v-if="nfse.errors" class="small text-danger mt-2 mb-0">{{ formatErrors(nfse.errors) }}</p>
    </section>
    <p v-if="error && !modal" class="small text-danger mt-2 mb-0" role="alert">{{ error }}</p>
    <CenteredModal :open="!!modal" :close-label="t.close" @close="modal = null"
        ><template #header
            ><h3 class="h5 mb-0">{{ title }}</h3></template
        >
        <form id="nfse-block-action" @submit.prevent="send()">
            <label for="nfse-block-value" class="form-label">{{
                modal?.kind === 'resend_email' ? t.emails : modal?.kind === 'cancel' ? t.justification : t.reason
            }}</label
            ><input
                v-if="modal?.kind === 'resend_email'"
                id="nfse-block-value"
                v-model="value"
                class="form-control"
                type="email"
                multiple
            /><textarea
                v-else
                id="nfse-block-value"
                v-model="value"
                class="form-control"
                rows="3"
                :minlength="modal?.kind === 'mark_cancelled_externally' ? 20 : 15"
                maxlength="255"
                required
            ></textarea>
            <p v-if="modal?.kind === 'resend_email'" class="form-text">{{ t.emails_hint }}</p>
            <p v-if="error" class="invalid-feedback d-block">{{ error }}</p>
        </form>
        <template #footer
            ><button type="button" class="btn btn-outline-secondary" @click="modal = null">{{ t.close }}</button
            ><button
                type="submit"
                form="nfse-block-action"
                class="btn btn-primary"
                :disabled="saving || !valid(modal?.kind)"
            >
                {{ t.save }}
            </button></template
        ></CenteredModal
    >
</template>
