<script setup>
import { nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { requestJson, firstError } from '@/Support/clinicalSafety.js';
import { latestRequest, isAbortError } from '@/Support/latestRequest.js';
import { makeTx } from '@/Support/recallRule.js';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

/**
 * Condições crônicas do paciente na gaveta do cadastro (Fase 6) — só o MÉDICO vê. O que o sistema detectou (CID forte
 * em prontuário assinado, medicação confirmada), o que o médico decide (confirmar, descartar, dar alta, registrar à
 * mão, corrigir olho/início) e os retornos que cada condição ativa gera. Só uma condição ATIVA gera retorno: a
 * sugerida espera a confirmação.
 *
 * Carrega sozinho (a resposta traz os textos já traduzidos) e some se o servidor recusar (plano sem a feature ou
 * perfil sem permissão). Cada ato devolve a situação inteira: o bloco é trocado de uma vez. Resposta atrasada de
 * OUTRO paciente nunca sobrescreve a tela (latestRequest).
 */
const props = defineProps({
    patientId: { type: [String, Number], required: true },
});

const data = ref(null);
const tx = makeTx(() => data.value?.t);
const { date } = useLocaleFormat();
const request = latestRequest();

const busy = ref(false);
const message = ref('');
const error = ref('');

// Formulário em linha aberto: 'add' (nova), 'edit' ou 'discharge' (de uma condição).
const panel = reactive({ kind: null, id: null });
const form = reactive({ protocol_key: '', eye: 'both', started_on: '', reason: '' });
const root = ref(null);

const TONE = {
    suggested: 'recall-tone recall-tone--warning',
    active: 'recall-tone recall-tone--success',
    discharged: 'recall-tone recall-tone--muted',
    rejected: 'recall-tone recall-tone--muted',
};

const url = (name, condition = null) =>
    route(`panel.patients.recall-conditions.${name}`, condition ? [props.patientId, condition.id] : props.patientId);

async function load(id) {
    data.value = null;
    message.value = '';
    error.value = '';
    closePanel();

    const mine = request.begin();

    try {
        const res = await requestJson(route('panel.patients.recall-conditions.index', id), { signal: mine.signal });

        if (mine.current()) data.value = res.ok ? res.data.data : null;
    } catch (failure) {
        if (!isAbortError(failure) && mine.current()) data.value = null;
    }
}

watch(
    () => props.patientId,
    (id) => {
        if (id) load(id);
    },
    { immediate: true },
);

onBeforeUnmount(() => request.cancel());

/** Roda um ato; a resposta (situação inteira) troca o bloco. Recusa de negócio vira aviso em alerta. */
async function run(name, { condition = null, method = 'POST', body = null, restoreFocus = null } = {}) {
    if (busy.value) return;

    busy.value = true;
    error.value = '';
    message.value = '';

    const mine = request.begin();

    try {
        const res = await requestJson(url(name, condition), { method, body });

        if (!mine.current()) return;

        if (res.ok) {
            data.value = res.data.data;
            message.value = res.data.message ?? '';
            closePanel(restoreFocus);
        } else {
            error.value = firstError(res.data, tx('errors.generic'));
        }
    } catch (failure) {
        if (!isAbortError(failure) && mine.current()) error.value = tx('errors.generic');
    } finally {
        busy.value = false;
    }
}

// ── formulários em linha ─────────────────────────────────────────────────────

function openPanel(kind, condition = null) {
    panel.kind = kind;
    panel.id = condition?.id ?? null;
    error.value = '';
    message.value = '';

    form.protocol_key = data.value?.protocols?.[0]?.key ?? '';
    form.eye = condition?.eye ?? 'both';
    form.started_on = condition?.started_on ?? '';
    form.reason = '';

    nextTick(() => root.value?.querySelector('[data-pc-first]')?.focus());
}

function closePanel(restoreFocus = null) {
    const trigger = restoreFocus ?? (panel.kind ? `${panel.kind}-${panel.id ?? 'new'}` : null);

    panel.kind = null;
    panel.id = null;

    if (trigger) nextTick(() => root.value?.querySelector(`[data-pc-trigger="${trigger}"]`)?.focus());
}

const startedOn = () => (form.started_on === '' ? null : form.started_on);

function submitAdd() {
    run('store', {
        body: { protocol_key: form.protocol_key, eye: form.eye, started_on: startedOn() },
        restoreFocus: 'add-new',
    });
}

function submitEdit(condition) {
    run('update', {
        condition,
        method: 'PUT',
        body: { eye: form.eye, started_on: startedOn() },
        restoreFocus: `edit-${condition.id}`,
    });
}

function submitDischarge(condition) {
    run('discharge', { condition, body: { reason: form.reason }, restoreFocus: `discharge-${condition.id}` });
}
</script>

<template>
    <section
        v-if="data"
        ref="root"
        class="detail-section recall-form"
        aria-labelledby="pc-title"
        data-testid="patient-conditions"
    >
        <div class="detail-section__title d-flex align-items-center justify-content-between gap-2">
            <span id="pc-title"><i class="ti ti-heartbeat me-1" aria-hidden="true"></i>{{ tx('title') }}</span>
            <button
                v-if="data.protocols.length"
                type="button"
                class="btn btn-sm recall-btn text-nowrap"
                data-pc-trigger="add-new"
                data-testid="pc-add"
                @click="openPanel('add')"
            >
                {{ tx('actions.add') }}
            </button>
        </div>

        <div v-if="message" class="small mb-2" role="status" data-testid="pc-message">
            <span class="recall-tone recall-tone--success">{{ message }}</span>
        </div>
        <div v-if="error" class="recall-notice recall-notice--warning small" role="alert" data-testid="pc-error">
            {{ error }}
        </div>

        <!-- Registrar à mão -->
        <form
            v-if="panel.kind === 'add'"
            class="border rounded p-2 mb-2"
            novalidate
            data-testid="pc-add-form"
            @submit.prevent="submitAdd"
        >
            <h4 class="h6">{{ tx('form.add_title') }}</h4>

            <div class="mb-2">
                <label class="form-label small mb-1" for="pc-protocol">{{ tx('form.protocol') }}</label>
                <select
                    id="pc-protocol"
                    v-model="form.protocol_key"
                    class="form-select form-select-sm"
                    required
                    data-pc-first
                >
                    <option v-for="protocol in data.protocols" :key="protocol.key" :value="protocol.key">
                        {{ protocol.title }}
                    </option>
                </select>
            </div>

            <div class="row g-2 mb-2">
                <div class="col-sm-6">
                    <label class="form-label small mb-1" for="pc-eye">{{ tx('form.eye') }}</label>
                    <select id="pc-eye" v-model="form.eye" class="form-select form-select-sm">
                        <option v-for="eye in data.eyes" :key="eye.value" :value="eye.value">{{ eye.label }}</option>
                    </select>
                </div>
                <div class="col-sm-6">
                    <label class="form-label small mb-1" for="pc-started">{{ tx('form.started_on') }}</label>
                    <input
                        id="pc-started"
                        v-model="form.started_on"
                        type="date"
                        class="form-control form-control-sm"
                        :max="data.today"
                        aria-describedby="pc-started-hint"
                    />
                </div>
            </div>
            <div id="pc-started-hint" class="form-text mb-2">{{ tx('form.started_hint') }}</div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm" :disabled="busy" data-testid="pc-add-submit">
                    {{ busy ? tx('actions.saving') : tx('actions.save') }}
                </button>
                <button type="button" class="btn btn-sm recall-btn" :disabled="busy" @click="closePanel()">
                    {{ tx('actions.cancel') }}
                </button>
            </div>
        </form>

        <p v-if="!data.protocols.length && !data.conditions.length" class="form-text mb-1">
            {{ tx('form.no_protocols') }}
        </p>
        <p v-if="!data.conditions.length && panel.kind !== 'add'" class="small mb-2" data-testid="pc-empty">
            {{ tx('empty') }}
        </p>

        <!-- Em acompanhamento -->
        <ul v-if="data.conditions.length" class="list-unstyled mb-2">
            <li
                v-for="condition in data.conditions"
                :key="condition.id"
                class="border rounded p-2 mb-2"
                :data-testid="`pc-condition-${condition.protocol_key}`"
                :data-status="condition.status"
            >
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <strong class="me-auto">{{ condition.title }}</strong>
                    <span class="small">{{ condition.eye_label }}</span>
                    <span :class="TONE[condition.status]" data-testid="pc-status">{{ condition.status_label }}</span>
                </div>

                <div v-if="condition.evidence_text" class="small mt-1">{{ condition.evidence_text }}</div>
                <div class="small">
                    {{
                        condition.started_on
                            ? tx('started_on', { date: date(condition.started_on) })
                            : tx('started_unknown')
                    }}
                </div>

                <div v-if="condition.status === 'suggested'" class="small fw-semibold mt-1" data-testid="pc-hint">
                    {{ tx('status_hint.suggested') }}
                </div>
                <div
                    v-if="!condition.protocol_active"
                    class="recall-notice recall-notice--warning small mt-2 mb-0"
                    data-testid="pc-inactive"
                >
                    {{ tx('protocol_inactive') }}
                </div>

                <div v-if="condition.next.length" class="small mt-2" data-testid="pc-next">
                    <div class="fw-semibold">{{ tx('next.title') }}</div>
                    <ul class="list-unstyled mb-0">
                        <li v-for="(item, index) in condition.next" :key="index">
                            {{ item.label }} —
                            <template v-if="item.due_at">{{ tx('next.due', { date: date(item.due_at) }) }}</template>
                            <template v-else>{{ tx('next.missing') }}</template>
                            <span v-if="item.state_label" class="ms-1 fw-semibold">({{ item.state_label }})</span>
                        </li>
                    </ul>
                </div>

                <div class="d-flex flex-wrap gap-2 mt-2">
                    <button
                        v-if="condition.can.confirm"
                        type="button"
                        class="btn btn-primary btn-sm"
                        :disabled="busy"
                        data-testid="pc-confirm"
                        @click="run('confirm', { condition })"
                    >
                        {{ tx('actions.confirm') }}
                    </button>
                    <button
                        v-if="condition.can.reject"
                        type="button"
                        class="btn btn-sm recall-btn"
                        :disabled="busy"
                        data-testid="pc-reject"
                        @click="run('reject', { condition })"
                    >
                        {{ tx('actions.reject') }}
                    </button>
                    <button
                        v-if="condition.can.edit"
                        type="button"
                        class="btn btn-sm recall-btn"
                        :disabled="busy"
                        :data-pc-trigger="`edit-${condition.id}`"
                        data-testid="pc-edit"
                        @click="openPanel('edit', condition)"
                    >
                        {{ tx('actions.edit') }}
                    </button>
                    <button
                        v-if="condition.can.discharge"
                        type="button"
                        class="btn btn-sm recall-btn recall-btn--danger"
                        :disabled="busy"
                        :data-pc-trigger="`discharge-${condition.id}`"
                        data-testid="pc-discharge"
                        @click="openPanel('discharge', condition)"
                    >
                        {{ tx('actions.discharge') }}
                    </button>
                </div>

                <!-- Corrigir olho e início -->
                <form
                    v-if="panel.kind === 'edit' && panel.id === condition.id"
                    class="border-top pt-2 mt-2"
                    novalidate
                    data-testid="pc-edit-form"
                    @submit.prevent="submitEdit(condition)"
                >
                    <h4 class="h6">{{ tx('form.edit_title') }}</h4>
                    <div class="row g-2 mb-2">
                        <div class="col-sm-6">
                            <label class="form-label small mb-1" :for="`pc-eye-${condition.id}`">{{
                                tx('form.eye')
                            }}</label>
                            <select
                                :id="`pc-eye-${condition.id}`"
                                v-model="form.eye"
                                class="form-select form-select-sm"
                                data-pc-first
                            >
                                <option v-for="eye in data.eyes" :key="eye.value" :value="eye.value">
                                    {{ eye.label }}
                                </option>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small mb-1" :for="`pc-start-${condition.id}`">{{
                                tx('form.started_on')
                            }}</label>
                            <input
                                :id="`pc-start-${condition.id}`"
                                v-model="form.started_on"
                                type="date"
                                class="form-control form-control-sm"
                                :max="data.today"
                            />
                        </div>
                    </div>
                    <div class="form-text mb-2">{{ tx('form.edit_note') }}</div>
                    <div class="d-flex gap-2">
                        <button
                            type="submit"
                            class="btn btn-primary btn-sm"
                            :disabled="busy"
                            data-testid="pc-edit-submit"
                        >
                            {{ busy ? tx('actions.saving') : tx('actions.save') }}
                        </button>
                        <button type="button" class="btn btn-sm recall-btn" :disabled="busy" @click="closePanel()">
                            {{ tx('actions.cancel') }}
                        </button>
                    </div>
                </form>

                <!-- Dar alta -->
                <form
                    v-if="panel.kind === 'discharge' && panel.id === condition.id"
                    class="border-top pt-2 mt-2"
                    novalidate
                    data-testid="pc-discharge-form"
                    @submit.prevent="submitDischarge(condition)"
                >
                    <h4 class="h6">{{ tx('discharge.title') }}</h4>
                    <p class="small mb-2">{{ tx('discharge.text') }}</p>
                    <label class="form-label small mb-1" :for="`pc-reason-${condition.id}`">{{
                        tx('discharge.reason')
                    }}</label>
                    <textarea
                        :id="`pc-reason-${condition.id}`"
                        v-model="form.reason"
                        class="form-control form-control-sm"
                        rows="2"
                        maxlength="500"
                        required
                        :aria-describedby="`pc-reason-hint-${condition.id}`"
                        data-pc-first
                    ></textarea>
                    <div :id="`pc-reason-hint-${condition.id}`" class="form-text mb-2">
                        {{ tx('discharge.reason_hint', { min: 3 }) }}
                    </div>
                    <div class="d-flex gap-2">
                        <button
                            type="submit"
                            class="btn btn-sm recall-btn recall-btn--danger"
                            :disabled="busy"
                            data-testid="pc-discharge-submit"
                        >
                            {{ busy ? tx('actions.saving') : tx('discharge.submit') }}
                        </button>
                        <button type="button" class="btn btn-sm recall-btn" :disabled="busy" @click="closePanel()">
                            {{ tx('actions.cancel') }}
                        </button>
                    </div>
                </form>
            </li>
        </ul>

        <!-- Histórico -->
        <details v-if="data.history.length" class="small" data-testid="pc-history">
            <summary>{{ tx('history', { count: data.history.length }) }}</summary>
            <ul class="list-unstyled mt-2 mb-0">
                <li v-for="condition in data.history" :key="condition.id" class="mb-2">
                    <strong>{{ condition.title }}</strong> · {{ condition.eye_label }} ·
                    <span :class="TONE[condition.status]">{{ condition.status_label }}</span>
                    <div v-if="condition.decided_at">
                        {{ tx(`decided.${condition.status}`, { date: date(condition.decided_at) }) }}
                    </div>
                    <div v-if="condition.discharge_reason">
                        {{ tx('discharge_reason', { reason: condition.discharge_reason }) }}
                    </div>
                    <div v-if="condition.evidence_text">{{ condition.evidence_text }}</div>
                </li>
            </ul>
        </details>
    </section>
</template>
