<script setup>
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import CareGuidanceEditor from '@/Components/Panel/CareGuidance/CareGuidanceEditor.vue';
import { makeTx } from '@/Support/recallRule.js';

/**
 * Configurações › Orientações pós-procedimento (Fase 6 / S14): modelos da clínica (texto, local, colírios e PDFs).
 * O médico entrega pelo prontuário (modal de procedimentos); o que é entregue fica congelado.
 */
defineOptions({ name: 'SettingsCareGuidanceIndex' });

const props = defineProps({
    templates: { type: Array, default: () => [] },
    maxAttachments: { type: Number, default: 5 },
    maxDrops: { type: Number, default: 10 },
    t: { type: Object, required: true },
});

const tx = makeTx(() => props.t);
const procedureSearchUrl = route('panel.procedures.search');

const list = ref([...props.templates]);
const editor = ref({ open: false, template: null, busy: false, attachBusy: false, errors: {} });
const confirming = ref(null);
const busyId = ref('');
const flash = ref('');
const error = ref('');

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

async function send(method, url, body = null, isForm = false) {
    const res = await fetch(url, {
        method,
        headers: {
            ...(isForm ? {} : { 'Content-Type': 'application/json' }),
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrf(),
        },
        body: isForm ? body : body ? JSON.stringify(body) : undefined,
    });

    return { ok: res.ok, json: await res.json().catch(() => ({})) };
}

function notify(message) {
    flash.value = message;
    error.value = '';
    window.showSuccessToast?.(message);
}

function upsert(template) {
    const index = list.value.findIndex((item) => item.id === template.id);
    if (index === -1) list.value.unshift(template);
    else list.value[index] = template;
    if (editor.value.template?.id === template.id) editor.value.template = template;
}

function openEditor(template = null) {
    editor.value = { open: true, template, busy: false, attachBusy: false, errors: {} };
}

const errorsOf = (json) => json.errors ?? { title: [json.message ?? props.t.errors.generic] };

async function save(payload) {
    editor.value.busy = true;
    editor.value.errors = {};
    const template = editor.value.template;
    const { ok, json } = template
        ? await send('PUT', route('panel.setting.care-guidance.update', template.id), payload)
        : await send('POST', route('panel.setting.care-guidance.store'), payload);
    editor.value.busy = false;
    if (!ok) {
        editor.value.errors = errorsOf(json);

        return;
    }
    upsert(json.data);
    // Modelo novo continua aberto para anexar os PDFs; edição fecha.
    if (template) editor.value.open = false;
    else editor.value.template = json.data;
    notify(json.message);
}

async function attach(file) {
    const template = editor.value.template;
    if (!template) return;
    editor.value.attachBusy = true;
    editor.value.errors = {};
    const form = new FormData();
    form.append('file', file);
    const { ok, json } = await send(
        'POST',
        route('panel.setting.care-guidance.attachments.store', template.id),
        form,
        true,
    );
    editor.value.attachBusy = false;
    if (!ok) {
        editor.value.errors = json.errors ?? { file: [json.message ?? props.t.errors.generic] };

        return;
    }
    upsert(json.data);
    notify(json.message);
}

async function detach(file) {
    const template = editor.value.template;
    editor.value.attachBusy = true;
    const { ok, json } = await send(
        'DELETE',
        route('panel.setting.care-guidance.attachments.destroy', [template.id, file.id]),
    );
    editor.value.attachBusy = false;
    if (!ok) {
        editor.value.errors = { file: [json.message ?? props.t.errors.generic] };

        return;
    }
    upsert(json.data);
    notify(json.message);
}

async function destroy(template) {
    busyId.value = template.id;
    const { ok, json } = await send('DELETE', route('panel.setting.care-guidance.destroy', template.id));
    busyId.value = '';
    confirming.value = null;
    if (!ok) {
        error.value = json.message ?? props.t.errors.generic;

        return;
    }
    list.value = list.value.filter((item) => item.id !== template.id);
    notify(json.message);
}

const empty = computed(() => !list.value.length);
</script>

<template>
    <AppLayout :title="t.settings.title" :breadcrumbs="[]">
        <div class="container-fluid py-3">
            <PageHeader :title="t.settings.title" :subtitle="t.settings.subtitle">
                <template #actions>
                    <button
                        type="button"
                        class="btn btn-primary btn-sm"
                        data-testid="guidance-new"
                        @click="openEditor()"
                    >
                        <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ t.settings.new }}
                    </button>
                </template>
            </PageHeader>

            <div v-if="error" class="alert alert-danger py-2" role="alert">{{ error }}</div>
            <div class="visually-hidden" role="status" aria-live="polite">{{ flash }}</div>

            <p v-if="empty" class="text-body-secondary small" data-testid="guidance-empty">{{ t.settings.empty }}</p>

            <div v-else class="row g-2">
                <div v-for="template in list" :key="template.id" class="col-12 col-md-6 col-xl-4">
                    <article class="card h-100" :data-testid="`guidance-${template.id}`">
                        <div class="card-body p-3">
                            <div class="d-flex align-items-start gap-2 mb-1">
                                <h3 class="h6 mb-0 flex-grow-1 text-break">{{ template.title }}</h3>
                                <span
                                    class="badge"
                                    :class="
                                        template.active
                                            ? 'bg-success-subtle text-success-emphasis'
                                            : 'bg-secondary-subtle text-secondary-emphasis'
                                    "
                                    >{{ template.active ? t.settings.active : t.settings.inactive }}</span
                                >
                            </div>
                            <p class="small text-body-secondary mb-1">
                                {{ template.procedure_name ?? t.settings.any_procedure }}
                            </p>
                            <p v-if="template.location_label" class="small mb-1">
                                <i class="ti ti-map-pin me-1" aria-hidden="true"></i>{{ template.location_label }}
                            </p>
                            <p class="small text-body-secondary mb-1 guidance-excerpt">{{ template.body }}</p>
                            <p v-if="template.eye_drops.length" class="small mb-1">
                                <i class="ti ti-droplet me-1" aria-hidden="true"></i
                                >{{ template.eye_drops.map((d) => d.name).join(', ') }}
                            </p>
                            <p v-if="template.attachments.length" class="small mb-0">
                                <i class="ti ti-paperclip me-1" aria-hidden="true"></i
                                >{{ template.attachments.map((a) => a.name).join(', ') }}
                            </p>
                        </div>
                        <div class="card-footer d-flex gap-2 flex-wrap align-items-center">
                            <template v-if="confirming === template.id">
                                <span class="small flex-grow-1">{{
                                    tx('settings.delete_confirm', { title: template.title })
                                }}</span>
                                <button
                                    type="button"
                                    class="btn btn-danger btn-sm"
                                    :disabled="busyId === template.id"
                                    data-testid="guidance-delete-confirm"
                                    @click="destroy(template)"
                                >
                                    {{ t.settings.delete }}
                                </button>
                                <button type="button" class="btn btn-light btn-sm" @click="confirming = null">
                                    {{ t.settings.cancel }}
                                </button>
                            </template>
                            <template v-else>
                                <button
                                    type="button"
                                    class="btn btn-outline-primary btn-sm"
                                    data-testid="guidance-edit"
                                    @click="openEditor(template)"
                                >
                                    <i class="ti ti-pencil me-1" aria-hidden="true"></i>{{ t.settings.edit }}
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-outline-danger btn-sm ms-auto"
                                    :aria-label="`${t.settings.delete}: ${template.title}`"
                                    data-testid="guidance-delete"
                                    @click="confirming = template.id"
                                >
                                    <i class="ti ti-trash" aria-hidden="true"></i>
                                </button>
                            </template>
                        </div>
                    </article>
                </div>
            </div>
        </div>

        <CareGuidanceEditor
            :open="editor.open"
            :template="editor.template"
            :procedure-search-url="procedureSearchUrl"
            :max-drops="maxDrops"
            :max-attachments="maxAttachments"
            :busy="editor.busy"
            :attach-busy="editor.attachBusy"
            :errors="editor.errors"
            :t="t"
            @close="editor.open = false"
            @save="save"
            @attach="attach"
            @detach="detach"
        />
    </AppLayout>
</template>

<style scoped>
.guidance-excerpt {
    display: -webkit-box;
    -webkit-line-clamp: 3;
    line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
    white-space: pre-line;
}
</style>
