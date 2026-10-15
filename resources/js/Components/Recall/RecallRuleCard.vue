<script setup>
import { computed, useId } from 'vue';
import { makeTx, cidLabel, NEUTRAL_BADGE } from '@/Support/recallRule.js';

/**
 * RecallRuleCard — a REGRA de um protocolo de retorno em linguagem clara.
 *
 * É o renderizador ÚNICO da regra: o Manager, a página do Revisor Clínico e a tela de
 * adoção da clínica mostram o conteúdo por aqui, então o que o Revisor aprova é o que
 * a clínica lê (ADR 0002). Recebe o conteúdo NORMALIZADO (servidor) e o dicionário de
 * textos `t` (lang `recall_rule` + `labels` dos enums, ver RecallRuleLabels).
 */
const props = defineProps({
    content: { type: Object, required: true },
    t: { type: Object, required: true },
    /** Mostra as explicações curtas sob cada rótulo (some no uso compacto). */
    showHints: { type: Boolean, default: true },
});

const uid = useId();
const tx = makeTx(() => props.t);
const labels = computed(() => props.t?.labels ?? {});

const cidLists = computed(() =>
    ['strong', 'weak', 'exclude'].map((key) => ({ key, codes: props.content?.entry?.cid?.[key] ?? [] })),
);
const medications = computed(() => props.content?.entry?.medications ?? []);
const tasks = computed(() => props.content?.tasks ?? []);
const references = computed(() => props.content?.references ?? []);

function taskName(task) {
    return task.kind === 'exam'
        ? (labels.value.exams?.[task.exam_key] ?? task.exam_key)
        : (labels.value.kinds?.[task.kind] ?? task.kind);
}

function leadText(task) {
    return task.lead_days === 0 ? tx('tasks.lead_zero') : tx('tasks.lead', { count: task.lead_days });
}

function graceText(task) {
    return task.grace_days === 0 ? tx('tasks.grace_zero') : tx('tasks.grace', { count: task.grace_days });
}

// Selos com contraste AA nos dois temas (resources/css/system/_recall.scss).
const evidenceClass = {
    high: 'recall-tone recall-tone--success',
    medium: 'recall-tone recall-tone--warning',
    low: `badge ${NEUTRAL_BADGE}`,
};
</script>

<template>
    <article class="recall-rule" data-testid="recall-rule-card">
        <section :aria-labelledby="`${uid}-entry`" class="mb-4">
            <h3 :id="`${uid}-entry`" class="h6 fw-semibold">{{ tx('entry.title') }}</h3>
            <dl class="row mb-0 gy-2">
                <template v-for="list in cidLists" :key="list.key">
                    <dt class="col-md-4 fw-medium">
                        {{ tx(`entry.${list.key}`) }}
                        <small v-if="showHints" class="d-block text-body-secondary fw-normal">{{
                            tx(`entry.${list.key}_hint`)
                        }}</small>
                    </dt>
                    <dd class="col-md-8 mb-0" :data-testid="`cid-${list.key}`">
                        <span v-if="!list.codes.length" class="text-body-secondary">{{ tx('entry.none') }}</span>
                        <span
                            v-for="code in list.codes"
                            :key="code"
                            :class="NEUTRAL_BADGE"
                            class="badge me-1 mb-1 font-monospace fw-normal"
                            >{{ cidLabel(code) }}</span
                        >
                    </dd>
                </template>
                <dt class="col-md-4 fw-medium">
                    {{ tx('entry.medications') }}
                    <small v-if="showHints" class="d-block text-body-secondary fw-normal">{{
                        tx('entry.medications_hint')
                    }}</small>
                </dt>
                <dd class="col-md-8 mb-0" data-testid="medications">
                    <span v-if="!medications.length" class="text-body-secondary">{{ tx('entry.none') }}</span>
                    <span
                        v-for="key in medications"
                        :key="key"
                        :class="NEUTRAL_BADGE"
                        class="badge me-1 mb-1 fw-normal"
                        >{{ labels.medications?.[key] ?? key }}</span
                    >
                </dd>
            </dl>
        </section>

        <section :aria-labelledby="`${uid}-tasks`" class="mb-4">
            <h3 :id="`${uid}-tasks`" class="h6 fw-semibold">{{ tx('tasks.title') }}</h3>
            <p v-if="!tasks.length" class="text-body-secondary mb-0">{{ tx('tasks.empty') }}</p>
            <ul v-else class="list-group">
                <li
                    v-for="task in tasks"
                    :key="task.key"
                    class="list-group-item px-3 py-2"
                    :data-testid="`task-${task.key}`"
                >
                    <div class="d-flex flex-wrap align-items-start gap-2">
                        <div class="me-auto">
                            <strong>{{ taskName(task) }}</strong>
                            <code class="small text-body-secondary ms-1">{{ task.key }}</code>
                        </div>
                        <div class="d-flex flex-wrap gap-1">
                            <span
                                class="fw-normal"
                                :class="task.required ? 'recall-tone recall-tone--info' : `badge ${NEUTRAL_BADGE}`"
                                >{{ task.required ? tx('tasks.required') : tx('tasks.optional') }}</span
                            >
                            <span class="badge fw-normal" :class="NEUTRAL_BADGE">{{
                                task.recurs ? tx('tasks.recurs') : tx('tasks.once')
                            }}</span>
                        </div>
                    </div>
                    <div class="mt-1">
                        <strong>{{
                            tx('tasks.interval_default', {
                                value: task.interval.default,
                                unit: (Number(task.interval.default) === 1 ? labels.units_one : labels.units)?.[
                                    task.interval.unit
                                ],
                            })
                        }}</strong>
                        <small class="text-body-secondary ms-1">{{
                            tx('tasks.range', {
                                min: task.interval.min,
                                max: task.interval.max,
                                unit: labels.units?.[task.interval.unit],
                            })
                        }}</small>
                    </div>
                    <ul class="small text-body-secondary mb-0 ps-3 mt-1">
                        <li>{{ leadText(task) }}</li>
                        <li>{{ graceText(task) }}</li>
                        <li>{{ tx('tasks.lost', { count: task.lost_after_days }) }}</li>
                        <li v-if="task.starts_after_months">
                            {{ tx('tasks.starts_after', { count: task.starts_after_months }) }}
                        </li>
                    </ul>
                </li>
            </ul>
        </section>

        <section :aria-labelledby="`${uid}-order`" class="mb-4">
            <h3 :id="`${uid}-order`" class="h6 fw-semibold">{{ tx('doctor_order.title') }}</h3>
            <p class="mb-0" data-testid="doctor-order">
                {{ content.doctor_order?.cap_to_max ? tx('doctor_order.cap') : tx('doctor_order.no_cap') }}
            </p>
        </section>

        <section :aria-labelledby="`${uid}-rationale`" class="mb-4">
            <h3 :id="`${uid}-rationale`" class="h6 fw-semibold">{{ tx('rationale.title') }}</h3>
            <p class="mb-0 text-break" style="white-space: pre-line" data-testid="rationale">{{ content.rationale }}</p>
        </section>

        <section v-if="content.change_note" :aria-labelledby="`${uid}-change`" class="mb-4">
            <h3 :id="`${uid}-change`" class="h6 fw-semibold">{{ tx('change_note.title') }}</h3>
            <p class="mb-0 text-break" style="white-space: pre-line" data-testid="change-note">
                {{ content.change_note }}
            </p>
        </section>

        <section :aria-labelledby="`${uid}-refs`">
            <h3 :id="`${uid}-refs`" class="h6 fw-semibold">{{ tx('references.title') }}</h3>
            <p v-if="!references.length" class="text-body-secondary mb-0">{{ tx('references.none') }}</p>
            <ul v-else class="list-unstyled mb-0">
                <li v-for="reference in references" :key="reference.title" class="mb-2" data-testid="reference">
                    <span class="text-break">{{ reference.title }}</span>
                    <span class="text-body-secondary"> ({{ reference.year ?? tx('references.year_unknown') }})</span>
                    <span
                        class="ms-1 fw-normal"
                        :class="evidenceClass[reference.evidence] ?? `badge ${NEUTRAL_BADGE}`"
                        >{{ labels.evidence?.[reference.evidence] ?? reference.evidence }}</span
                    >
                    <small v-if="reference.note" class="d-block text-body-secondary text-break">{{
                        reference.note
                    }}</small>
                </li>
            </ul>
        </section>
    </article>
</template>
