<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { callApi, errorMessages, useOperationText } from './useOperation.js';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

/**
 * Descrição cirúrgica: rascunho do cirurgião → FINALIZADA (hash do conteúdo e
 * retrato do cabeçalho). Finalizada não se edita: correção é adendo, que fica
 * encadeado ao hash. A alta exige a descrição finalizada.
 */
const props = defineProps({
    section: { type: Object, required: true },
    t: { type: Object, required: true },
});

const emit = defineEmits(['reload']);

const { tr } = useOperationText(() => props.t);
const { dateTime } = useLocaleFormat();

const description = computed(() => props.section.description);
const finalized = computed(() => description.value?.finalized === true);
const canEdit = computed(() => props.section.state.can_describe);

const form = reactive({
    performed_start_at: '',
    performed_end_at: '',
    anesthesia_used: '',
    findings: '',
    technique_performed: '',
    no_complications: false,
    complications: '',
});

function load() {
    const d = description.value;
    form.performed_start_at = d?.performed_start_at ?? '';
    form.performed_end_at = d?.performed_end_at ?? '';
    form.anesthesia_used = d?.anesthesia_used ?? '';
    form.findings = d?.findings ?? '';
    form.technique_performed = d?.technique_performed ?? '';
    form.no_complications = Boolean(d?.no_complications);
    form.complications = d?.complications ?? '';
}

// Revisão que a tela conhece do rascunho. Cada gravação do servidor sobe a revisão: guardar a devolvida pelo
// último `save` (e não só a do prop) é o que deixa o médico corrigir e gravar de novo quando a finalização,
// que grava antes em silêncio, é recusada — sem recarregar, o prop ficaria velho e todo `save` daria 409.
const revision = ref(description.value?.revision ?? null);

load();
watch(
    () => props.section.description,
    () => {
        load();
        revision.value = description.value?.revision ?? null;
    },
);

const busy = ref(false);
const errors = ref([]);
const saved = ref(false);

function body() {
    return {
        ...form,
        performed_start_at: form.performed_start_at ? new Date(form.performed_start_at).toISOString() : null,
        performed_end_at: form.performed_end_at ? new Date(form.performed_end_at).toISOString() : null,
        anesthesia_used: form.anesthesia_used || null,
        expected_revision: revision.value,
    };
}

async function save(silent = false) {
    if (busy.value) return false;
    busy.value = true;
    errors.value = [];
    saved.value = false;

    const result = await callApi(props.section.urls.description_save, { method: 'PUT', body: body() });
    busy.value = false;

    if (result.ok) {
        saved.value = true;
        revision.value = result.data.revision ?? revision.value;
        if (!silent) emit('reload');
        return true;
    }
    errors.value = errorMessages(result);
    return false;
}

async function finalize() {
    if (!(await save(true))) return;
    busy.value = true;
    const result = await callApi(props.section.urls.description_finalize, {
        body: { expected_revision: revision.value },
    });
    busy.value = false;

    if (result.ok) {
        emit('reload');
        return;
    }
    errors.value = errorMessages(result);
}

// ── Adendo ────────────────────────────────────────────────────────────────
const addendum = reactive({ text: '', reason: '' });
const addendumErrors = ref([]);
const addendumBusy = ref(false);

async function addAddendum() {
    addendumBusy.value = true;
    addendumErrors.value = [];
    const result = await callApi(props.section.urls.description_addendum, { body: { ...addendum } });
    addendumBusy.value = false;

    if (result.ok) {
        addendum.text = '';
        addendum.reason = '';
        emit('reload');
        return;
    }
    addendumErrors.value = errorMessages(result);
}
</script>

<template>
    <section class="card mb-3" data-testid="op-description">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="ti ti-file-description" aria-hidden="true"></i>
            <h2 class="h6 mb-0">{{ tr('description.title') }}</h2>
            <span
                v-if="description"
                class="badge ms-2"
                :class="finalized ? 'text-bg-success' : 'text-bg-warning'"
                data-testid="op-description-status"
            >
                {{ finalized ? tr('description.finalized') : tr('description.draft') }}
            </span>
            <a
                v-if="description && section.state.can_print"
                class="btn btn-sm btn-outline-secondary ms-auto"
                :href="section.urls.description_pdf"
                target="_blank"
                rel="noopener"
                data-testid="op-description-pdf"
            >
                <i class="ti ti-printer me-1" aria-hidden="true"></i>{{ tr('description.pdf') }}
            </a>
        </div>

        <div class="card-body">
            <p v-if="!description && !canEdit" class="text-muted mb-0">{{ tr('description.none') }}</p>

            <!-- Leitura (finalizada) -->
            <div v-else-if="finalized" data-testid="op-description-final">
                <dl class="row small mb-2">
                    <dt class="col-sm-3">{{ tr('description.start') }}</dt>
                    <dd class="col-sm-9">{{ dateTime(description.performed_start_at) }}</dd>
                    <dt class="col-sm-3">{{ tr('description.end') }}</dt>
                    <dd class="col-sm-9">{{ dateTime(description.performed_end_at) }}</dd>
                    <dt class="col-sm-3">{{ tr('description.findings') }}</dt>
                    <dd class="col-sm-9 text-break" style="white-space: pre-wrap">{{ description.findings }}</dd>
                    <dt class="col-sm-3">{{ tr('description.technique') }}</dt>
                    <dd class="col-sm-9 text-break" style="white-space: pre-wrap">
                        {{ description.technique_performed }}
                    </dd>
                    <dt class="col-sm-3">{{ tr('description.complications') }}</dt>
                    <dd class="col-sm-9 text-break" style="white-space: pre-wrap">
                        {{
                            description.no_complications
                                ? tr('description.no_complications_text')
                                : description.complications
                        }}
                    </dd>
                </dl>
                <p class="small text-muted mb-3">
                    {{ tr('description.finalized_at', { date: dateTime(description.finalized_at) }) }} ·
                    <code>{{ description.content_hash?.slice(0, 16) }}</code>
                </p>

                <h3 class="h6">{{ tr('description.addenda') }}</h3>
                <p v-if="!description.addenda.length" class="text-muted small">{{ tr('description.addenda_none') }}</p>
                <ul v-else class="list-unstyled">
                    <li
                        v-for="a in description.addenda"
                        :key="a.id"
                        class="border-start border-3 border-primary ps-2 mb-2"
                        data-testid="op-addendum"
                    >
                        <div class="small text-muted">
                            {{ dateTime(a.created_at) }} · {{ a.doctor }} {{ a.registry }} · {{ a.reason }}
                        </div>
                        <div class="text-break" style="white-space: pre-wrap">{{ a.text }}</div>
                    </li>
                </ul>

                <form v-if="section.state.can_addendum" class="border rounded p-2" @submit.prevent="addAddendum">
                    <h4 class="h6">{{ tr('description.addendum_new') }}</h4>
                    <label class="form-label small mb-1" for="op-add-reason">{{
                        tr('description.addendum_reason')
                    }}</label>
                    <input
                        id="op-add-reason"
                        v-model="addendum.reason"
                        type="text"
                        class="form-control form-control-sm mb-2"
                        maxlength="500"
                        data-testid="op-addendum-reason"
                    />
                    <label class="form-label small mb-1" for="op-add-text">{{ tr('description.addendum_text') }}</label>
                    <textarea
                        id="op-add-text"
                        v-model="addendum.text"
                        class="form-control form-control-sm mb-2"
                        rows="3"
                        data-testid="op-addendum-text"
                    ></textarea>
                    <div v-if="addendumErrors.length" class="alert alert-danger py-2" role="alert">
                        <div v-for="(m, i) in addendumErrors" :key="i">{{ m }}</div>
                    </div>
                    <button
                        type="submit"
                        class="btn btn-sm btn-outline-primary"
                        :disabled="addendumBusy"
                        data-testid="op-addendum-submit"
                    >
                        {{ tr('description.addendum_add') }}
                    </button>
                </form>
            </div>

            <!-- Edição (rascunho) -->
            <form v-else-if="canEdit" @submit.prevent="save()">
                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label small mb-1" for="op-d-start">{{ tr('description.start') }}</label>
                        <input
                            id="op-d-start"
                            v-model="form.performed_start_at"
                            type="datetime-local"
                            class="form-control form-control-sm"
                            data-testid="op-d-start"
                        />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small mb-1" for="op-d-end">{{ tr('description.end') }}</label>
                        <input
                            id="op-d-end"
                            v-model="form.performed_end_at"
                            type="datetime-local"
                            class="form-control form-control-sm"
                            data-testid="op-d-end"
                        />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small mb-1" for="op-d-anesthesia">{{
                            tr('description.anesthesia')
                        }}</label>
                        <select
                            id="op-d-anesthesia"
                            v-model="form.anesthesia_used"
                            class="form-select form-select-sm"
                            data-testid="op-d-anesthesia"
                        >
                            <option value="">{{ tr('description.choose') }}</option>
                            <option v-for="a in section.options.anesthesias" :key="a.value" :value="a.value">
                                {{ a.label }}
                            </option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-1" for="op-d-findings">{{
                            tr('description.findings')
                        }}</label>
                        <textarea
                            id="op-d-findings"
                            v-model="form.findings"
                            class="form-control"
                            rows="3"
                            data-testid="op-d-findings"
                        ></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-1" for="op-d-technique">{{
                            tr('description.technique')
                        }}</label>
                        <textarea
                            id="op-d-technique"
                            v-model="form.technique_performed"
                            class="form-control"
                            rows="5"
                            data-testid="op-d-technique"
                        ></textarea>
                    </div>
                    <div class="col-12">
                        <div class="form-check mb-1">
                            <input
                                id="op-d-nocomp"
                                v-model="form.no_complications"
                                type="checkbox"
                                class="form-check-input"
                                data-testid="op-d-nocomp"
                            />
                            <label class="form-check-label" for="op-d-nocomp">{{
                                tr('description.no_complications')
                            }}</label>
                        </div>
                        <textarea
                            v-if="!form.no_complications"
                            v-model="form.complications"
                            class="form-control"
                            rows="2"
                            :aria-label="tr('description.complications')"
                            :placeholder="tr('description.complications')"
                            data-testid="op-d-complications"
                        ></textarea>
                    </div>
                </div>

                <ul
                    v-if="description?.problems?.length"
                    class="small text-warning-emphasis mt-2 mb-0"
                    data-testid="op-description-problems"
                >
                    <li v-for="(p, i) in description.problems" :key="i">{{ p }}</li>
                </ul>

                <div
                    v-if="errors.length"
                    class="alert alert-danger py-2 mt-2"
                    role="alert"
                    data-testid="op-description-errors"
                >
                    <div v-for="(m, i) in errors" :key="i">{{ m }}</div>
                </div>
                <div v-if="saved && !errors.length" class="text-success small mt-2" role="status">
                    {{ tr('description.saved') }}
                </div>

                <div class="d-flex gap-2 mt-3">
                    <button
                        type="submit"
                        class="btn btn-outline-primary"
                        :disabled="busy"
                        data-testid="op-description-save"
                    >
                        <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ tr('description.save') }}
                    </button>
                    <button
                        v-if="section.state.can_finalize"
                        type="button"
                        class="btn btn-success"
                        :disabled="busy"
                        data-testid="op-description-finalize"
                        @click="finalize"
                    >
                        <i class="ti ti-lock me-1" aria-hidden="true"></i>{{ tr('description.finalize') }}
                    </button>
                </div>
                <p v-if="!section.state.can_finalize" class="form-text mb-0">
                    {{ tr('description.finalize_after_performed') }}
                </p>
            </form>

            <p v-else class="text-muted mb-0">{{ tr('description.only_surgeon') }}</p>
        </div>
    </section>
</template>
