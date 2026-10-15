<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

/**
 * Aba "Checklist": cirurgia segura (OMS) em três fases — Entrada (antes da
 * anestesia), Pausa Cirúrgica (antes da incisão) e Saída (antes de o paciente
 * deixar a sala). Responder e salvar é parcial; FECHAR exige todos os itens, e
 * item crítico respondido "não" pede nota. Fase fechada é só leitura: mostra o
 * texto que foi conferido, quem fechou e o hash do conteúdo.
 *
 * A Pausa Cirúrgica é condição para registrar a cirurgia como realizada; a
 * exceção (só o cirurgião, com justificativa) aparece aqui quando existe.
 */
const props = defineProps({
    section: { type: Object, required: true },
    surgicalCase: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
    urls: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
    t: { type: Object, required: true },
});

defineEmits(['reload']);

const { dateTime } = useLocaleFormat();
const c = computed(() => props.t.schedule?.checklist ?? {});
const ui = computed(() => c.value.ui ?? {});
const editable = computed(() => Boolean(props.section.can_answer));

// Rascunho local por fase: { [phase]: { [itemKey]: { answer, note } } }
const draft = reactive({});
const busy = ref(null); // `${phase}:save|close`
const errors = reactive({});
const touched = reactive({});

function rebuild() {
    for (const phase of props.section.phases) {
        draft[phase.phase] = Object.fromEntries(
            phase.items.map((item) => [item.key, { answer: item.answer ?? '', note: item.note ?? '' }]),
        );
        touched[phase.phase] = false;
        errors[phase.phase] = [];
    }
}

rebuild();
watch(() => props.section, rebuild);

const phases = computed(() =>
    props.section.phases.map((phase) => ({
        ...phase,
        answeredNow: phase.items.filter((item) => draft[phase.phase]?.[item.key]?.answer).length,
        needsNote: (item) => item.critical && draft[phase.phase]?.[item.key]?.answer === 'no',
    })),
);

const stateBadge = (state) => ({ not_started: 'secondary', open: 'warning', closed: 'success' })[state] ?? 'secondary';

function answer(phase, item, value) {
    if (!editable.value) return;
    draft[phase.phase][item.key].answer = value;
    touched[phase.phase] = true;
}

function applySuggestions(phase) {
    for (const item of phase.items) {
        const cur = draft[phase.phase][item.key];
        if (item.suggested_na && item.allow_na && !cur.answer) cur.answer = 'na';
    }
    touched[phase.phase] = true;
}

const hasSuggestions = (phase) =>
    editable.value && phase.items.some((i) => i.suggested_na && i.allow_na && !draft[phase.phase][i.key].answer);

function answersFor(phase) {
    return phase.items
        .filter((item) => draft[phase.phase][item.key].answer)
        .map((item) => ({
            key: item.key,
            answer: draft[phase.phase][item.key].answer,
            note: draft[phase.phase][item.key].note || null,
        }));
}

function url(kind, phase) {
    return props.section.urls[kind].replace('__PHASE__', phase.phase);
}

function run(kind, phase) {
    busy.value = `${phase.phase}:${kind}`;
    errors[phase.phase] = [];

    const options = {
        preserveScroll: true,
        preserveState: true,
        onError: (bag) => {
            errors[phase.phase] = [bag.checklist, bag.answers, bag.message].flat().filter(Boolean);
            if (!errors[phase.phase].length) errors[phase.phase] = Object.values(bag).flat();
        },
        onFinish: () => {
            busy.value = null;
        },
    };

    if (kind === 'save') {
        router.put(url('save', phase), { answers: answersFor(phase) }, options);
    } else {
        router.post(url('close', phase), { answers: answersFor(phase) }, options);
    }
}

const shortHash = (hash) => (hash ? hash.slice(0, 12) : '');
const allergyState = computed(() => props.section.safety?.allergies?.state ?? 'unknown');
</script>

<template>
    <div class="d-grid gap-4">
        <div v-if="!section.accepts_work" class="alert alert-secondary mb-0">{{ ui.case_not_open }}</div>

        <div
            v-if="section.time_out_satisfied === false && surgicalCase.status.value === 'scheduled'"
            class="alert alert-info mb-0"
            role="note"
        >
            <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ ui.time_out_needed }}
        </div>
        <div v-if="section.time_out_stale" class="alert alert-warning mb-0" role="alert" data-testid="time-out-stale">
            <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>{{ ui.time_out_stale }}
        </div>
        <div
            v-if="section.time_out_critical_no"
            class="alert alert-danger mb-0"
            role="alert"
            data-testid="time-out-critical-no"
        >
            <i class="ti ti-alert-octagon me-1" aria-hidden="true"></i>{{ ui.time_out_critical_no }}
        </div>

        <article
            v-for="phase in phases"
            :key="phase.phase"
            class="card"
            :data-testid="`phase-${phase.phase}`"
            :data-state="phase.state"
        >
            <header class="card-header d-flex flex-wrap align-items-center gap-2">
                <h2 class="h6 mb-0">{{ phase.label }}</h2>
                <span class="badge" :class="`text-bg-${stateBadge(phase.state)}`">{{ ui.state[phase.state] }}</span>
                <span class="text-muted small">{{ phase.when }}</span>
                <span class="ms-auto small text-muted">
                    {{
                        ui.progress
                            .replace(':done', phase.state === 'closed' ? phase.total : phase.answeredNow)
                            .replace(':total', phase.total)
                    }}
                </span>
            </header>

            <div class="card-body">
                <!-- Entrada: o que a equipe confere do paciente -->
                <div v-if="phase.phase === 'sign_in'" class="alert alert-light border small" data-testid="safety-panel">
                    <p class="fw-semibold mb-1">
                        {{ ui.safety_title }}
                        <span v-if="section.safety.taken_at" class="fw-normal text-muted" data-testid="safety-taken">
                            · {{ ui.safety_taken.replace(':date', dateTime(section.safety.taken_at)) }}
                        </span>
                    </p>
                    <div
                        v-if="section.safety.live_differs"
                        class="alert alert-warning py-1 px-2 mb-2"
                        role="alert"
                        data-testid="safety-changed"
                    >
                        <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>{{ ui.safety_changed }}
                    </div>
                    <p class="mb-1">
                        <strong>{{ ui.allergies }}:</strong>
                        <template v-if="allergyState === 'has'">
                            <span
                                v-for="(a, i) in section.safety.allergies.items"
                                :key="i"
                                class="badge text-bg-danger me-1"
                                >{{ a.substance }}</span
                            >
                        </template>
                        <template v-else-if="allergyState === 'denied'">{{ ui.allergies_denied }}</template>
                        <template v-else>{{ ui.allergies_unknown }}</template>
                    </p>
                    <p v-if="section.safety.risk_alerts.length" class="mb-1">
                        <strong>{{ ui.risks }}:</strong>
                        <span v-for="(r, i) in section.safety.risk_alerts" :key="i" class="badge text-bg-warning me-1"
                            >{{ r.alert }} ({{ (r.medications ?? []).join(', ') }})</span
                        >
                    </p>
                    <p class="mb-0 text-muted">
                        {{
                            ui.eye_and_anesthesia
                                .replace(':eye', section.eye)
                                .replace(':anesthesia', section.anesthesia ?? ui.anesthesia_unset)
                        }}
                    </p>
                </div>
                <!-- Pausa: o plano de LIO a conferir -->
                <div v-if="phase.phase === 'time_out'" class="alert alert-light border small" data-testid="iol-panel">
                    <p class="fw-semibold mb-1">{{ ui.plan_title }}</p>
                    <p class="mb-1">
                        {{
                            ui.eye_and_anesthesia
                                .replace(':eye', section.eye)
                                .replace(':anesthesia', section.anesthesia ?? ui.anesthesia_unset)
                        }}
                    </p>
                    <ul v-if="section.iol_plans.length" class="mb-0 ps-3">
                        <li v-for="(p, i) in section.iol_plans" :key="i">
                            {{ p.eye }} — {{ p.model }} ({{ p.power }} D)
                        </li>
                    </ul>
                    <p v-else class="mb-0 text-muted">{{ ui.no_iol_plan }}</p>
                </div>

                <div v-if="phase.override" class="alert alert-warning" role="alert" data-testid="override-notice">
                    <p class="fw-semibold mb-1">
                        <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>{{ ui.override_title }}
                    </p>
                    <p class="mb-0">
                        {{ phase.override.reason }}
                        <span class="text-muted small d-block"
                            >{{ phase.override.doctor }} · {{ dateTime(phase.override.at) }}</span
                        >
                    </p>
                </div>

                <ol class="list-unstyled mb-0 d-grid gap-3">
                    <li v-for="item in phase.items" :key="item.key" :data-testid="`item-${item.key}`">
                        <fieldset :disabled="!editable || phase.state === 'closed'">
                            <legend class="fs-6 fw-normal mb-1">
                                {{ item.text }}
                                <span v-if="item.critical" class="badge text-bg-danger ms-1">{{ ui.critical }}</span>
                                <span
                                    v-if="item.suggested_na && item.allow_na && phase.state !== 'closed'"
                                    class="badge text-bg-light ms-1"
                                    >{{ ui.suggested_na }}</span
                                >
                            </legend>
                            <p v-if="item.hint" class="form-text mt-0 mb-1">{{ item.hint }}</p>

                            <div class="btn-group btn-group-sm" role="group" :aria-label="item.text">
                                <template v-for="opt in ['yes', 'no', ...(item.allow_na ? ['na'] : [])]" :key="opt">
                                    <input
                                        :id="`${phase.phase}-${item.key}-${opt}`"
                                        type="radio"
                                        class="btn-check"
                                        :name="`${phase.phase}-${item.key}`"
                                        :checked="draft[phase.phase][item.key].answer === opt"
                                        @change="answer(phase, item, opt)"
                                    />
                                    <label
                                        class="btn"
                                        :class="[
                                            opt === 'yes'
                                                ? 'btn-outline-success'
                                                : opt === 'no'
                                                  ? 'btn-outline-danger'
                                                  : 'btn-outline-secondary',
                                        ]"
                                        :for="`${phase.phase}-${item.key}-${opt}`"
                                    >
                                        {{ c.answers[opt] }}
                                    </label>
                                </template>
                            </div>

                            <div
                                v-if="
                                    draft[phase.phase][item.key].answer &&
                                    (editable || draft[phase.phase][item.key].note)
                                "
                                class="mt-2"
                            >
                                <label class="form-label small mb-0" :for="`${phase.phase}-${item.key}-note`">
                                    {{ phase.needsNote(item) ? ui.note_required : ui.note }}
                                </label>
                                <textarea
                                    :id="`${phase.phase}-${item.key}-note`"
                                    v-model="draft[phase.phase][item.key].note"
                                    class="form-control form-control-sm"
                                    :class="{
                                        'is-invalid': phase.needsNote(item) && !draft[phase.phase][item.key].note,
                                    }"
                                    rows="2"
                                    maxlength="1000"
                                    @input="touched[phase.phase] = true"
                                ></textarea>
                            </div>
                        </fieldset>
                    </li>
                </ol>

                <div
                    v-if="errors[phase.phase]?.length"
                    class="alert alert-danger mt-3 mb-0"
                    role="alert"
                    data-testid="phase-errors"
                >
                    <ul class="mb-0 ps-3">
                        <li v-for="(e, i) in errors[phase.phase]" :key="i">{{ e }}</li>
                    </ul>
                </div>

                <p v-if="phase.state === 'closed'" class="small text-muted mt-3 mb-0" data-testid="closed-info">
                    <i class="ti ti-lock me-1" aria-hidden="true"></i>
                    {{
                        ui.closed_by.replace(':user', phase.closed_by ?? '—').replace(':at', dateTime(phase.closed_at))
                    }}
                    <span class="d-block text-break"
                        >{{ ui.hash }}: {{ shortHash(phase.hash) }} · {{ ui.catalog }} {{ phase.catalog }}</span
                    >
                </p>
            </div>

            <footer v-if="editable && phase.state !== 'closed'" class="card-footer d-flex flex-wrap gap-2">
                <button
                    v-if="hasSuggestions(phase)"
                    type="button"
                    class="btn btn-sm btn-link me-auto"
                    @click="applySuggestions(phase)"
                >
                    {{ ui.apply_suggestions }}
                </button>
                <button
                    type="button"
                    class="btn btn-sm btn-outline-secondary ms-auto"
                    :disabled="busy !== null || !touched[phase.phase]"
                    :data-testid="`save-${phase.phase}`"
                    @click="run('save', phase)"
                >
                    {{ ui.save }}
                </button>
                <button
                    type="button"
                    class="btn btn-sm btn-primary"
                    :disabled="busy !== null"
                    :data-testid="`close-${phase.phase}`"
                    @click="run('close', phase)"
                >
                    <span
                        v-if="busy === `${phase.phase}:close`"
                        class="spinner-border spinner-border-sm me-1"
                        aria-hidden="true"
                    ></span>
                    {{ ui.close }}
                </button>
            </footer>
        </article>
    </div>
</template>
