<script setup>
import { computed, nextTick, ref, useId, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import SearchSelect from '@/Components/Panel/SearchSelect.vue';

/**
 * Cadastro/edição de credencial TISS. Segredos (senha, token, senha do certificado) são só de escrita: a tela
 * recebe apenas "está salvo" (has_*); campo vazio mantém o salvo e "apagar" remove. Operadora e ambiente só no
 * cadastro. Com arquivo, o envio vai como multipart (forceFormData). Textos: `t` de tiss_credentials.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    credential: { type: Object, default: null },
    environments: { type: Array, default: () => [] },
    routes: { type: Object, required: true },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close']);

const uid = useId();
const fid = (field) => `tiss-cred-${uid}-${field}`;
const describedBy = (field, hint = true) =>
    [hint ? fid(`${field}-hint`) : null, form.errors[field] ? fid(`${field}-error`) : null].filter(Boolean).join(' ') ||
    undefined;
const formRef = ref(null);
const certificateInput = ref(null);
const keyInput = ref(null);
const editing = computed(() => props.credential !== null);
const f = computed(() => props.t.form ?? {});
const tx = (template, params = {}) =>
    Object.entries(params).reduce((text, [k, v]) => text.replaceAll(`:${k}`, String(v ?? '')), template ?? '');

const blank = () => ({
    covenant_id: null,
    environment: 'sandbox',
    endpoint_submission: '',
    endpoint_status: '',
    username: '',
    password: '',
    remove_password: false,
    token: '',
    remove_token: false,
    certificate: null,
    private_key: null,
    certificate_password: '',
    remove_certificate: false,
    ssl_enabled: true,
    active: true,
});

const form = useForm(blank());

// Abrir carrega a credencial como "valores iniciais": o aviso de descartar só aparece se algo mudar.
watch(
    () => [props.open, props.credential],
    ([open]) => {
        if (!open) return;
        const c = props.credential;
        form.defaults({
            ...blank(),
            ...(c && {
                endpoint_submission: c.endpoint_submission ?? '',
                endpoint_status: c.endpoint_status ?? '',
                username: c.username ?? '',
                ssl_enabled: c.ssl_enabled,
                active: c.active,
            }),
        });
        form.reset();
        form.clearErrors();
    },
);

function done() {
    if (certificateInput.value) certificateInput.value.value = '';
    if (keyInput.value) keyInput.value.value = '';
    emit('close');
}

// Cancelar, X, Escape e fundo passam pela mesma proteção de alterações (MASTER.md §4).
function close() {
    if (form.isDirty && !window.confirm(f.value.discard_confirm ?? '')) return;
    done();
}

function submit() {
    form.post(editing.value ? props.credential.urls.update : props.routes.store, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: done,
        onError: () => nextTick(() => formRef.value?.querySelector('[aria-invalid="true"]')?.focus()),
    });
}

const textFields = [
    { key: 'endpoint_submission', type: 'url', hint: true, max: 255 },
    { key: 'endpoint_status', type: 'url', hint: true, max: 255 },
    { key: 'username', type: 'text', hint: false, max: 255 },
];

// Segredo salvo: placeholder diz que está salvo; a caixa "apagar" só aparece quando há o que apagar.
const secretFields = computed(() => [
    { key: 'password', remove: 'remove_password', saved: !!props.credential?.has_password, max: 1000 },
    { key: 'token', remove: 'remove_token', saved: !!props.credential?.has_token, max: 4000 },
]);
</script>

<template>
    <CenteredModal :open="open" size="lg" density="default" @close="close">
        <template #header>
            <h2 class="h5 mb-0">{{ editing ? f.title_edit : f.title_new }}</h2>
        </template>

        <form :id="fid('form')" ref="formRef" novalidate data-test="tiss-cred-form" @submit.prevent="submit">
            <p v-if="editing" class="mb-3" data-test="tiss-cred-identity">
                <span class="fw-semibold">{{ credential.operator }}</span>
                <span class="text-muted"> · {{ tx(t.status?.ans, { code: credential.ans_code }) }} · </span>
                <span>{{ t.environments?.[credential.environment] ?? credential.environment }}</span>
            </p>

            <div v-else class="row g-3 mb-3">
                <div class="col-md-8">
                    <label :id="fid('covenant-label')" :for="fid('covenant_id')" class="form-label">
                        {{ f.covenant }}
                    </label>
                    <SearchSelect
                        :id="fid('covenant_id')"
                        v-model="form.covenant_id"
                        :aria-labelledby="fid('covenant-label')"
                        :aria-describedby="describedBy('covenant_id')"
                        :remote-search-url="routes.operators"
                        :remote-min-chars="0"
                        value-key="id"
                        label-key="label"
                        :placeholder="f.covenant_placeholder"
                        :invalid="!!form.errors.covenant_id"
                    />
                    <div :id="fid('covenant_id-hint')" class="form-text">{{ f.covenant_hint }}</div>
                    <div v-if="form.errors.covenant_id" :id="fid('covenant_id-error')" class="invalid-feedback d-block">
                        {{ form.errors.covenant_id }}
                    </div>
                </div>
                <div class="col-md-4">
                    <label :for="fid('environment')" class="form-label">{{ f.environment }}</label>
                    <select
                        :id="fid('environment')"
                        v-model="form.environment"
                        class="form-select"
                        :class="{ 'is-invalid': form.errors.environment }"
                        :aria-invalid="!!form.errors.environment"
                        :aria-describedby="describedBy('environment')"
                        data-test="tiss-cred-environment"
                    >
                        <option v-for="env in environments" :key="env" :value="env">
                            {{ t.environments?.[env] ?? env }}
                        </option>
                    </select>
                    <div :id="fid('environment-hint')" class="form-text">{{ f.environment_hint }}</div>
                    <div v-if="form.errors.environment" :id="fid('environment-error')" class="invalid-feedback">
                        {{ form.errors.environment }}
                    </div>
                </div>
            </div>

            <div v-for="field in textFields" :key="field.key" class="mb-3">
                <label :for="fid(field.key)" class="form-label">{{ f[field.key] }}</label>
                <input
                    :id="fid(field.key)"
                    v-model="form[field.key]"
                    :type="field.type"
                    class="form-control"
                    :class="{ 'is-invalid': form.errors[field.key] }"
                    :aria-invalid="!!form.errors[field.key]"
                    :aria-describedby="describedBy(field.key, field.hint)"
                    :maxlength="field.max"
                    autocomplete="off"
                    :data-test="`tiss-cred-${field.key}`"
                />
                <div v-if="field.hint" :id="fid(`${field.key}-hint`)" class="form-text">
                    {{ f[`${field.key}_hint`] }}
                </div>
                <div v-if="form.errors[field.key]" :id="fid(`${field.key}-error`)" class="invalid-feedback">
                    {{ form.errors[field.key] }}
                </div>
            </div>

            <div v-for="field in secretFields" :key="field.key" class="mb-3">
                <label :for="fid(field.key)" class="form-label">{{ f[field.key] }}</label>
                <input
                    :id="fid(field.key)"
                    v-model="form[field.key]"
                    type="password"
                    class="form-control"
                    :class="{ 'is-invalid': form.errors[field.key] }"
                    :aria-invalid="!!form.errors[field.key]"
                    :aria-describedby="describedBy(field.key)"
                    :maxlength="field.max"
                    :disabled="form[field.remove]"
                    autocomplete="new-password"
                    :data-test="`tiss-cred-${field.key}`"
                />
                <div :id="fid(`${field.key}-hint`)" class="form-text">
                    {{ field.saved ? f.secret_saved : f.secret_not_saved }}
                </div>
                <div v-if="form.errors[field.key]" :id="fid(`${field.key}-error`)" class="invalid-feedback">
                    {{ form.errors[field.key] }}
                </div>
                <div v-if="field.saved" class="form-check mt-1">
                    <input
                        :id="fid(field.remove)"
                        v-model="form[field.remove]"
                        class="form-check-input"
                        type="checkbox"
                        :data-test="`tiss-cred-${field.remove}`"
                    />
                    <label :for="fid(field.remove)" class="form-check-label">{{ f[field.remove] }}</label>
                </div>
            </div>

            <fieldset class="border rounded p-3 mb-3">
                <legend class="float-none w-auto px-2 fs-6 mb-0">{{ f.certificate }}</legend>
                <p :id="fid('certificate-hint')" class="form-text mt-0">{{ f.certificate_hint }}</p>
                <p v-if="credential?.certificate" class="small mb-2" data-test="tiss-cred-certificate-saved">
                    <i class="ti ti-certificate me-1" aria-hidden="true"></i>
                    {{
                        tx(f.certificate_saved, {
                            subject: credential.certificate.subject ?? '—',
                            date: credential.certificate.valid_to ?? '—',
                        })
                    }}
                </p>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label :for="fid('certificate')" class="form-label">{{ f.certificate }}</label>
                        <input
                            :id="fid('certificate')"
                            ref="certificateInput"
                            type="file"
                            class="form-control"
                            :class="{ 'is-invalid': form.errors.certificate }"
                            accept=".pfx,.p12,.pem,.crt,.cer"
                            :aria-invalid="!!form.errors.certificate"
                            :aria-describedby="describedBy('certificate')"
                            :disabled="form.remove_certificate"
                            data-test="tiss-cred-certificate"
                            @input="form.certificate = $event.target.files[0] ?? null"
                        />
                        <div v-if="form.errors.certificate" :id="fid('certificate-error')" class="invalid-feedback">
                            {{ form.errors.certificate }}
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label :for="fid('private_key')" class="form-label">{{ f.private_key }}</label>
                        <input
                            :id="fid('private_key')"
                            ref="keyInput"
                            type="file"
                            class="form-control"
                            :class="{ 'is-invalid': form.errors.private_key }"
                            accept=".pem,.key"
                            :aria-invalid="!!form.errors.private_key"
                            :aria-describedby="describedBy('private_key')"
                            :disabled="form.remove_certificate"
                            data-test="tiss-cred-private-key"
                            @input="form.private_key = $event.target.files[0] ?? null"
                        />
                        <div :id="fid('private_key-hint')" class="form-text">{{ f.private_key_hint }}</div>
                        <div v-if="form.errors.private_key" :id="fid('private_key-error')" class="invalid-feedback">
                            {{ form.errors.private_key }}
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label :for="fid('certificate_password')" class="form-label">{{
                            f.certificate_password
                        }}</label>
                        <input
                            :id="fid('certificate_password')"
                            v-model="form.certificate_password"
                            type="password"
                            class="form-control"
                            :class="{ 'is-invalid': form.errors.certificate_password }"
                            :aria-invalid="!!form.errors.certificate_password"
                            :aria-describedby="describedBy('certificate_password')"
                            :disabled="form.remove_certificate"
                            maxlength="255"
                            autocomplete="new-password"
                            data-test="tiss-cred-certificate-password"
                        />
                        <div :id="fid('certificate_password-hint')" class="form-text">
                            {{ f.certificate_password_hint }}
                        </div>
                        <div
                            v-if="form.errors.certificate_password"
                            :id="fid('certificate_password-error')"
                            class="invalid-feedback"
                        >
                            {{ form.errors.certificate_password }}
                        </div>
                    </div>
                </div>

                <div v-if="credential?.has_certificate" class="form-check mt-2">
                    <input
                        :id="fid('remove_certificate')"
                        v-model="form.remove_certificate"
                        class="form-check-input"
                        type="checkbox"
                        data-test="tiss-cred-remove-certificate"
                    />
                    <label :for="fid('remove_certificate')" class="form-check-label">{{ f.remove_certificate }}</label>
                </div>
            </fieldset>

            <div v-for="key in ['ssl_enabled', 'active']" :key="key" class="form-check mb-2">
                <input
                    :id="fid(key)"
                    v-model="form[key]"
                    class="form-check-input"
                    type="checkbox"
                    :data-test="`tiss-cred-${key}`"
                />
                <label :for="fid(key)" class="form-check-label">{{ f[key] }}</label>
            </div>
        </form>

        <template #footer>
            <button type="button" class="btn btn-outline-secondary" @click="close">{{ f.cancel }}</button>
            <button
                type="submit"
                class="btn btn-primary"
                :form="fid('form')"
                :disabled="form.processing"
                :aria-busy="form.processing ? 'true' : undefined"
                data-test="tiss-cred-submit"
            >
                {{ form.processing ? f.saving : f.save }}
            </button>
        </template>
    </CenteredModal>
</template>
