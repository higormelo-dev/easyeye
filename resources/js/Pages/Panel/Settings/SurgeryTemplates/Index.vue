<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import TinyMceEditor from '@/Components/Panel/TinyMceEditor.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

/**
 * Configurações > Modelos cirúrgicos: TCLE e orientações pré-operatórias da
 * clínica (criar, editar, duplicar, ativar/inativar, excluir, pré-visualizar),
 * adoção dos modelos padrão e o aviso de autorização por WhatsApp. Editar um
 * modelo não muda documento já emitido (a emissão congela o texto).
 */
const props = defineProps({
    breadcrumbs: { type: Array, default: () => [] },
    templates: { type: Array, default: () => [] },
    types: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    variables: { type: Array, default: () => [] },
    missingDefaults: { type: Number, default: 0 },
    notices: { type: Object, default: () => ({ authorization_enabled: false }) },
    urls: { type: Object, default: () => ({}) },
    t: { type: Object, required: true },
});

const { dateTime } = useLocaleFormat();
const ui = computed(() => props.t.documents?.templates ?? {});
const notice = computed(() => props.t.documents?.notices ?? {});
const withId = (url, id) => url.replace('__ID__', id);
const tx = (text, params = {}) =>
    Object.entries(params).reduce((acc, [name, value]) => acc.replace(`:${name}`, String(value)), text ?? '');

// ── Lista ────────────────────────────────────────────────────────────────
const busy = ref(false);

function adopt() {
    busy.value = true;
    router.post(props.urls.adopt, {}, { preserveScroll: true, onFinish: () => (busy.value = false) });
}

function setActive(template, active) {
    router.patch(withId(props.urls.active, template.id), { active }, { preserveScroll: true });
}

function duplicate(template) {
    router.post(withId(props.urls.duplicate, template.id), {}, { preserveScroll: true });
}

async function remove(template) {
    const result = await window.Swal?.fire({
        icon: 'warning',
        title: ui.value.confirm_delete_title,
        text: tx(ui.value.confirm_delete_text, { name: template.name }),
        showCancelButton: true,
        confirmButtonText: ui.value.confirm,
        cancelButtonText: ui.value.cancel,
    });
    if (result?.isConfirmed) router.delete(withId(props.urls.destroy, template.id), { preserveScroll: true });
}

// ── Formulário (criar / editar) ──────────────────────────────────────────
const editing = ref(null); // null | 'new' | template
const loadingBody = ref(false);
const editor = ref(null);
const copiedKey = ref('');

const form = useForm({ type: 'consent', category: '', name: '', body: '', active: true });

async function openForm(template = null) {
    form.clearErrors();
    form.defaults({
        type: template?.type ?? props.types[0]?.value ?? 'consent',
        category: template?.category ?? '',
        name: template?.name ?? '',
        body: '',
        active: template?.active ?? true,
    });
    form.reset();
    editing.value = template ?? 'new';

    if (template) {
        loadingBody.value = true;
        try {
            const { data } = await window.axios.get(withId(props.urls.show, template.id));
            form.body = data.body ?? '';
        } finally {
            loadingBody.value = false;
        }
    }
}

function submit() {
    const options = { preserveScroll: true, onSuccess: () => (editing.value = null) };
    form.transform((data) => ({
        ...data,
        category: data.category === '' ? null : Number(data.category),
        ...(editing.value === 'new' ? {} : { type: undefined }),
    }));

    if (editing.value === 'new') form.post(props.urls.store, options);
    else form.put(withId(props.urls.update, editing.value.id), options);
}

function insertVariable(variable) {
    if (editor.value?.insertContent) {
        editor.value.insertContent(variable.token);
    } else {
        form.body += variable.token;
    }
    copiedKey.value = variable.key;
    setTimeout(() => (copiedKey.value = ''), 1200);
}

// ── Pré-visualização ─────────────────────────────────────────────────────
const preview = ref(null); // { title, html, unknown }
const previewLoading = ref(false);

async function openPreview(body, title) {
    preview.value = { title, html: '', unknown: [], pending: [] };
    previewLoading.value = true;
    try {
        const { data } = await window.axios.post(props.urls.preview, { body });
        preview.value = { title, html: data.html, unknown: data.unknown ?? [], pending: data.pending ?? [] };
    } catch {
        preview.value = null;
    } finally {
        previewLoading.value = false;
    }
}

async function previewRow(template) {
    const { data } = await window.axios.get(withId(props.urls.show, template.id));
    await openPreview(data.body ?? '', template.name);
}

// ── Aviso por WhatsApp ───────────────────────────────────────────────────
const noticeForm = useForm({ authorization_enabled: props.notices.authorization_enabled });

function saveNotice() {
    noticeForm.patch(props.urls.notices, { preserveScroll: true });
}

const err = (path) => form.errors[path] ?? '';
const tokens = (keys) => keys.map((key) => '{{' + key + '}}').join(', ');
</script>

<template>
    <Head :title="ui.title" />

    <AppLayout :title="ui.title" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader :title="ui.title" :subtitle="ui.subtitle">
                <template #actions>
                    <button
                        type="button"
                        class="btn btn-outline-secondary btn-sm"
                        :disabled="busy || missingDefaults === 0"
                        :title="missingDefaults === 0 ? ui.adopt_done : ui.adopt_hint"
                        data-testid="tpl-adopt"
                        @click="adopt"
                    >
                        <i class="ti ti-download me-1" aria-hidden="true"></i>{{ ui.adopt }}
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" data-testid="tpl-add" @click="openForm()">
                        <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ ui.add }}
                    </button>
                </template>
            </PageHeader>

            <div class="alert alert-info py-2" role="note" data-testid="tpl-review-notice">{{ ui.review_notice }}</div>

            <section class="card mb-3" aria-labelledby="tpl-notice-title" data-testid="tpl-notices">
                <div class="card-body">
                    <h2 id="tpl-notice-title" class="h6">{{ notice.title }}</h2>
                    <p class="text-muted small">{{ notice.description }}</p>
                    <div class="form-check form-switch">
                        <input
                            id="tpl-notice-toggle"
                            v-model="noticeForm.authorization_enabled"
                            class="form-check-input"
                            type="checkbox"
                            role="switch"
                            data-testid="tpl-notice-toggle"
                            @change="saveNotice"
                        />
                        <label class="form-check-label" for="tpl-notice-toggle">{{ notice.toggle_label }}</label>
                    </div>
                    <p class="form-text mb-0">{{ notice.requirements }}</p>
                </div>
            </section>

            <div v-if="!templates.length" class="text-center text-muted py-5" data-testid="tpl-empty">
                {{ ui.empty }}
            </div>

            <div v-else class="table-responsive">
                <table class="table align-middle" :aria-label="ui.title">
                    <thead>
                        <tr>
                            <th scope="col">{{ ui.col_name }}</th>
                            <th scope="col">{{ ui.col_type }}</th>
                            <th scope="col">{{ ui.col_category }}</th>
                            <th scope="col" class="text-end">{{ ui.col_version }}</th>
                            <th scope="col">{{ ui.col_status }}</th>
                            <th scope="col" class="text-end">{{ ui.col_actions }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="tpl in templates" :key="tpl.id" data-testid="tpl-row">
                            <td>
                                {{ tpl.name }}
                                <span v-if="tpl.is_default" class="badge text-bg-light border ms-1">{{
                                    ui.default_badge
                                }}</span>
                                <div class="small text-muted">{{ dateTime(tpl.updated_at) }}</div>
                            </td>
                            <td>{{ tpl.type_label }}</td>
                            <td>{{ tpl.category_label ?? ui.any_category }}</td>
                            <td class="text-end">v{{ tpl.version }}</td>
                            <td>
                                <span class="badge" :class="tpl.active ? 'text-bg-success' : 'text-bg-secondary'">{{
                                    tpl.active ? ui.active : ui.inactive
                                }}</span>
                            </td>
                            <td class="text-end text-nowrap">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-secondary me-1"
                                    data-testid="tpl-preview"
                                    @click="previewRow(tpl)"
                                >
                                    {{ ui.preview }}
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-primary me-1"
                                    data-testid="tpl-edit"
                                    @click="openForm(tpl)"
                                >
                                    {{ ui.edit }}
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-secondary me-1"
                                    data-testid="tpl-duplicate"
                                    @click="duplicate(tpl)"
                                >
                                    {{ ui.duplicate }}
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-secondary me-1"
                                    data-testid="tpl-toggle"
                                    @click="setActive(tpl, !tpl.active)"
                                >
                                    {{ tpl.active ? ui.deactivate : ui.activate }}
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-danger"
                                    :aria-label="ui.delete"
                                    data-testid="tpl-delete"
                                    @click="remove(tpl)"
                                >
                                    <i class="ti ti-trash" aria-hidden="true"></i>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <CenteredModal :open="editing !== null" size="xl" @close="!form.processing && (editing = null)">
            <template #header>{{ editing === 'new' ? ui.form_create : ui.form_edit }}</template>

            <form class="p-2" novalidate @submit.prevent="submit">
                <!-- Trava TODO controle do payload enquanto salva: o que fosse digitado depois do POST ficaria de fora. -->
                <fieldset :disabled="form.processing" class="row g-3 border-0 p-0 m-0" data-testid="tpl-fieldset">
                    <div class="col-md-6">
                        <label class="form-label fw-medium" for="tpl-name">{{ ui.name_label }}</label>
                        <input
                            id="tpl-name"
                            v-model="form.name"
                            type="text"
                            maxlength="150"
                            class="form-control"
                            :class="{ 'is-invalid': err('name') }"
                            data-testid="tpl-name"
                        />
                        <div class="invalid-feedback">{{ err('name') }}</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-medium" for="tpl-type">{{ ui.type_label }}</label>
                        <select
                            id="tpl-type"
                            v-model="form.type"
                            class="form-select"
                            :disabled="editing !== 'new'"
                            data-testid="tpl-type"
                        >
                            <option v-for="type in types" :key="type.value" :value="type.value">
                                {{ type.label }}
                            </option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-medium" for="tpl-category">{{ ui.category_label }}</label>
                        <select
                            id="tpl-category"
                            v-model="form.category"
                            class="form-select"
                            data-testid="tpl-category"
                        >
                            <option value="">{{ ui.any_category }}</option>
                            <option v-for="c in categories" :key="c.value" :value="c.value">{{ c.label }}</option>
                        </select>
                        <div v-if="err('category')" class="text-danger small">{{ err('category') }}</div>
                    </div>

                    <div class="col-12">
                        <div class="d-flex flex-wrap align-items-center gap-1 mb-2" aria-live="polite">
                            <span class="fw-medium me-1">{{ ui.variables_title }}:</span>
                            <button
                                v-for="variable in variables"
                                :key="variable.key"
                                type="button"
                                class="btn btn-sm btn-outline-secondary py-0"
                                :title="variable.label"
                                :data-testid="`tpl-var-${variable.key}`"
                                @click="insertVariable(variable)"
                            >
                                {{ copiedKey === variable.key ? ui.variable_copied : variable.token }}
                            </button>
                        </div>
                        <p class="form-text mt-0">{{ ui.variables_hint }}</p>
                        <label class="form-label fw-medium" for="tpl-body">{{ ui.body_label }}</label>
                        <div v-if="loadingBody" class="text-muted small py-4 text-center">{{ ui.preview_loading }}</div>
                        <div v-show="!loadingBody" id="tpl-body">
                            <TinyMceEditor ref="editor" v-model="form.body" :height="420" :disabled="form.processing" />
                        </div>
                        <div v-if="err('body')" class="text-danger small mt-1" data-testid="tpl-body-error">
                            {{ err('body') }}
                        </div>
                    </div>
                </fieldset>

                <div class="d-flex justify-content-between gap-2 mt-3">
                    <button
                        type="button"
                        class="btn btn-outline-secondary"
                        :disabled="form.processing || !form.body"
                        data-testid="tpl-form-preview"
                        @click="openPreview(form.body, form.name)"
                    >
                        {{ ui.preview }}
                    </button>
                    <div class="d-flex gap-2">
                        <button
                            type="button"
                            class="btn btn-outline-secondary"
                            :disabled="form.processing"
                            @click="editing = null"
                        >
                            {{ ui.cancel }}
                        </button>
                        <button
                            type="submit"
                            class="btn btn-primary"
                            :disabled="form.processing"
                            data-testid="tpl-save"
                        >
                            {{ form.processing ? ui.saving : ui.save }}
                        </button>
                    </div>
                </div>
            </form>
        </CenteredModal>

        <CenteredModal :open="preview !== null" size="xl" @close="preview = null">
            <template #header
                >{{ ui.preview_title }}<span v-if="preview?.title"> — {{ preview.title }}</span></template
            >

            <div class="p-3">
                <p class="text-muted small">{{ ui.preview_note }}</p>
                <div
                    v-if="preview?.unknown?.length"
                    class="alert alert-danger py-2"
                    role="alert"
                    data-testid="tpl-preview-unknown"
                >
                    {{ tx(ui.unknown_variables, { list: tokens(preview.unknown) }) }}
                </div>
                <div v-if="previewLoading" class="text-center text-muted py-4">{{ ui.preview_loading }}</div>
                <!-- HTML sanitizado no servidor (Purifier 'medical'), valores das variáveis com escape. -->
                <!-- eslint-disable vue/no-v-html -->
                <div
                    v-else
                    class="border rounded p-3 tpl-preview"
                    data-testid="tpl-preview-html"
                    v-html="preview?.html"
                ></div>
                <!-- eslint-enable vue/no-v-html -->
            </div>
            <template #footer>
                <button type="button" class="btn btn-outline-secondary" @click="preview = null">{{ ui.close }}</button>
            </template>
        </CenteredModal>
    </AppLayout>
</template>
