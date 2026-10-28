<script setup>
import { computed, ref, watch } from 'vue';

/**
 * Proteção de dados (LGPD) de um provedor no drawer: onde processa, base da
 * transferência internacional, se pode receber dados de pacientes e o
 * mecanismo registrado (LGPD art. 33) — com o formulário de registro. O
 * sistema guarda só a referência ao documento, nunca o documento.
 */
const props = defineProps({
    provider: { type: Object, required: true }, // card de providerCards()
    lgpd: { type: Object, default: () => ({ checked_at: null, mechanisms: [] }) },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['saved']);

const l = computed(() => props.provider.lgpd ?? {});
const record = computed(() => l.value.record ?? null);
const canRegister = computed(() => !!l.value.patients && !!l.value.can_record);

function tr(key, fallback, replace = {}) {
    let text = props.t[key] ?? fallback;
    for (const [k, v] of Object.entries(replace)) text = text.replace(`:${k}`, v);
    return text;
}

// Data local (não UTC): à noite o toISOString() já virou o dia.
function today() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

const form = ref({});
const errors = ref({});
const saving = ref(false);
const removing = ref(false);

watch(
    () => [props.provider.code, record.value?.reference, record.value?.signed_at, record.value?.mechanism],
    () => {
        form.value = {
            mechanism: record.value?.mechanism ?? props.lgpd.mechanisms?.[0] ?? 'standard_clauses',
            reference: record.value?.reference ?? '',
            signed_at: record.value?.signed_at ?? '',
        };
        errors.value = {};
    },
    { immediate: true },
);

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function send(method) {
    const res = await fetch(
        route(`manager.ai-providers.transfer.${method === 'PATCH' ? 'update' : 'destroy'}`, props.provider.code),
        {
            method,
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: method === 'PATCH' ? JSON.stringify(form.value) : undefined,
        },
    );
    return { res, json: await res.json().catch(() => ({})) };
}

async function save() {
    if (saving.value) return;
    saving.value = true;
    errors.value = {};
    try {
        const { res, json } = await send('PATCH');
        if (!res.ok) {
            errors.value = json.errors ?? {};
            if (!json.errors) window.showErrorToast?.(json.message ?? '');
            return;
        }
        window.showSuccessToast?.(json.message);
        emit('saved');
    } finally {
        saving.value = false;
    }
}

async function remove() {
    if (removing.value || !window.confirm(tr('transfer_remove_confirm', '', { provider: props.provider.label })))
        return;
    removing.value = true;
    try {
        const { res, json } = await send('DELETE');
        if (!res.ok) {
            window.showErrorToast?.(json.message ?? '');
            return;
        }
        window.showSuccessToast?.(json.message);
        emit('saved');
    } finally {
        removing.value = false;
    }
}
</script>

<template>
    <div data-lgpd-section>
        <div class="apd-table mb-2">
            <div class="apd-row">
                <span class="apd-label">{{ tr('field_location', 'Onde processa') }}</span>
                <span class="apd-value" data-lgpd-location>{{ tr(`location_${l.location}`, l.location) }}</span>
            </div>
            <div class="apd-row">
                <span class="apd-label">{{ tr('field_transfer', 'Transferência internacional') }}</span>
                <span class="apd-value">{{ tr(`transfer_${l.transfer}`, l.transfer) }}</span>
            </div>
            <div class="apd-row">
                <span class="apd-label">{{ tr('field_patients', 'Dados de pacientes') }}</span>
                <span class="apd-value">
                    <span
                        class="badge rounded fs-12"
                        :class="
                            l.patients
                                ? 'badge-soft-success text-success border border-success'
                                : 'badge-soft-danger text-danger border border-danger'
                        "
                        data-lgpd-patients
                        >{{
                            l.patients ? tr('patients_allowed', 'Permitido') : tr('patients_blocked', 'Bloqueado')
                        }}</span
                    >
                </span>
            </div>
            <div v-if="l.region_env" class="apd-row">
                <span class="apd-label">{{ tr('field_env_var', 'Variável no .env') }}</span>
                <span class="apd-value"
                    ><code>{{ l.region_env }}={{ l.data_region }}</code></span
                >
            </div>
        </div>

        <p v-if="l.region_env" class="form-text mt-0 mb-2">{{ t.lgpd_region_hint }}</p>
        <p v-if="provider.code === 'maritaca'" class="form-text mt-0 mb-2">{{ t.lgpd_maritaca_hint }}</p>

        <div v-if="!l.patients" class="alert alert-danger py-2 small mb-2" role="note">
            <i class="ti ti-shield-x me-1" aria-hidden="true"></i>{{ t[`blocked_reason_${l.blocked_reason}`] }}
            {{ t.blocked_note }}
        </div>

        <template v-if="canRegister">
            <div class="fw-semibold small mb-1">{{ tr('field_record', 'Mecanismo registrado') }}</div>
            <div v-if="record" class="small mb-2" data-lgpd-record>
                <div>
                    <i class="ti ti-shield-check text-success me-1" aria-hidden="true"></i
                    >{{ tr(`mechanism_${record.mechanism}`, record.mechanism) }}
                </div>
                <div class="text-muted text-break">{{ record.reference }} · {{ record.signed_at_display }}</div>
                <div v-if="record.registered_by" class="text-muted">
                    {{
                        tr('transfer_registered_by', ':name · :date', {
                            name: record.registered_by,
                            date: record.registered_at ?? '',
                        })
                    }}
                </div>
            </div>
            <div v-else-if="l.pending" class="alert alert-warning py-2 small mb-2" role="note">
                <i class="ti ti-shield-exclamation me-1" aria-hidden="true"></i>{{ t.transfer_contract }}
            </div>

            <form class="row g-2" novalidate data-lgpd-form @submit.prevent="save">
                <div class="col-12">
                    <label class="form-label small fw-semibold mb-1" for="lgpd-mechanism">{{
                        tr('transfer_mechanism', 'Mecanismo')
                    }}</label>
                    <select
                        id="lgpd-mechanism"
                        v-model="form.mechanism"
                        class="form-select"
                        :class="{ 'is-invalid': errors.mechanism }"
                        :aria-invalid="!!errors.mechanism"
                        aria-describedby="lgpd-mechanism-error"
                        required
                    >
                        <option v-for="m in lgpd.mechanisms" :key="m" :value="m">{{ tr(`mechanism_${m}`, m) }}</option>
                    </select>
                    <div id="lgpd-mechanism-error" class="invalid-feedback">{{ errors.mechanism?.[0] }}</div>
                </div>
                <div class="col-12 col-sm-8">
                    <label class="form-label small fw-semibold mb-1" for="lgpd-reference">{{
                        tr('transfer_reference', 'Referência do documento')
                    }}</label>
                    <input
                        id="lgpd-reference"
                        v-model.trim="form.reference"
                        type="text"
                        class="form-control"
                        :class="{ 'is-invalid': errors.reference }"
                        maxlength="255"
                        :placeholder="t.transfer_reference_placeholder"
                        :aria-invalid="!!errors.reference"
                        aria-describedby="lgpd-reference-hint lgpd-reference-error"
                        required
                        data-lgpd-reference
                    />
                    <div id="lgpd-reference-error" class="invalid-feedback">{{ errors.reference?.[0] }}</div>
                    <div id="lgpd-reference-hint" class="form-text">{{ t.transfer_reference_hint }}</div>
                </div>
                <div class="col-12 col-sm-4">
                    <label class="form-label small fw-semibold mb-1" for="lgpd-signed-at">{{
                        tr('transfer_signed_at', 'Assinado em')
                    }}</label>
                    <input
                        id="lgpd-signed-at"
                        v-model="form.signed_at"
                        type="date"
                        class="form-control"
                        :class="{ 'is-invalid': errors.signed_at }"
                        :max="today()"
                        :aria-invalid="!!errors.signed_at"
                        aria-describedby="lgpd-signed-at-error"
                        required
                        data-lgpd-signed-at
                    />
                    <div id="lgpd-signed-at-error" class="invalid-feedback">{{ errors.signed_at?.[0] }}</div>
                </div>
                <div class="col-12 d-flex flex-wrap gap-2">
                    <button
                        type="submit"
                        class="btn btn-primary btn-sm"
                        :disabled="saving || !form.reference || !form.signed_at"
                        data-lgpd-save
                    >
                        <span v-if="saving" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                        <i v-else class="ti ti-shield-check me-1" aria-hidden="true"></i
                        >{{
                            record
                                ? tr('transfer_replace', 'Substituir registro')
                                : tr('transfer_register', 'Registrar')
                        }}
                    </button>
                    <button
                        v-if="record && !l.in_role"
                        type="button"
                        class="btn btn-outline-danger btn-sm"
                        :disabled="removing"
                        data-lgpd-remove
                        @click="remove"
                    >
                        <span v-if="removing" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                        <i v-else class="ti ti-trash me-1" aria-hidden="true"></i>{{ tr('transfer_remove', 'Remover') }}
                    </button>
                </div>
            </form>
        </template>
        <p v-else-if="l.patients" class="text-muted small mb-2">{{ t.transfer_not_needed }}</p>

        <div v-if="l.sources?.length" class="small mt-3">
            <div class="fw-semibold mb-1">{{ tr('field_sources', 'Fontes oficiais') }}</div>
            <ul class="ps-3 mb-1">
                <li v-for="url in l.sources" :key="url">
                    <a :href="url" target="_blank" rel="noopener noreferrer" class="text-break">{{ url }}</a>
                </li>
            </ul>
            <div v-if="lgpd.checked_at" class="text-muted">
                {{ tr('lgpd_checked_at', ':date', { date: lgpd.checked_at }) }}
            </div>
        </div>
    </div>
</template>

<style scoped>
.apd-table {
    display: grid;
    gap: 0.375rem;
}
.apd-row {
    display: grid;
    grid-template-columns: 170px 1fr;
    gap: 0.5rem;
    font-size: 0.875rem;
    align-items: baseline;
}
.apd-label {
    font-weight: 600;
    color: var(--bs-body-color);
}
.apd-value {
    color: var(--bs-secondary-color);
    word-break: break-word;
}
@media (max-width: 575.98px) {
    .apd-row {
        grid-template-columns: 1fr;
        gap: 0;
    }
}
</style>
