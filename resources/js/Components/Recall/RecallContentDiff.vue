<script setup>
import { computed } from 'vue';
import { makeTx, cidLabel } from '@/Support/recallRule.js';

/**
 * RecallContentDiff — o que mudou entre duas versões de um protocolo (lista que o
 * servidor monta em ProtocolContentDiff). É a leitura que o Revisor faz junto da regra:
 * "o que mudou desde a versão que eu já validei". Sem versão anterior, avisa.
 */
const props = defineProps({
    /** Itens de ProtocolContentDiff::between(). */
    changes: { type: Array, default: () => [] },
    /** Existe versão anterior para comparar? */
    hasParent: { type: Boolean, default: true },
    t: { type: Object, required: true },
});

const tx = makeTx(() => props.t);
const labels = computed(() => props.t?.labels ?? {});

// Selos com contraste AA nos dois temas (resources/css/system/_recall.scss).
const opClass = {
    added: 'recall-tone recall-tone--success',
    removed: 'recall-tone recall-tone--danger',
    changed: 'recall-tone recall-tone--warning',
};

/** Rótulo do campo: a chave tem ponto ("interval.default"), então não passa por tx(). */
function fieldLabel(field) {
    return props.t?.diff?.fields?.[field] ?? field;
}

function sectionLabel(section) {
    return props.t?.diff?.sections?.[section] ?? section;
}

function formatValue(field, value) {
    if (value === null || value === undefined || value === '') return tx('empty_value');
    if (typeof value === 'boolean') return value ? tx('yes') : tx('no');

    const maps = { kind: 'kinds', exam_key: 'exams', 'interval.unit': 'units', evidence: 'evidence' };
    const map = maps[field];

    return map ? (labels.value[map]?.[value] ?? String(value)) : String(value);
}

function taskSummary(task) {
    const name =
        task.kind === 'exam'
            ? (labels.value.exams?.[task.exam_key] ?? task.exam_key)
            : (labels.value.kinds?.[task.kind] ?? task.kind);
    const unit = (Number(task.interval?.default) === 1 ? labels.value.units_one : labels.value.units)?.[
        task.interval?.unit
    ];

    return `${name} — ${task.interval?.default} ${unit ?? ''}`.trim();
}

const isTextChange = (change) => ['rationale', 'change_note'].includes(change.section);
</script>

<template>
    <section class="recall-diff" data-testid="recall-diff">
        <h3 class="h6 fw-semibold">{{ tx('diff.title') }}</h3>

        <p v-if="!hasParent" class="text-body-secondary mb-0" data-testid="diff-first">{{ tx('diff.first') }}</p>
        <p v-else-if="!changes.length" class="text-body-secondary mb-0" data-testid="diff-none">
            {{ tx('diff.none') }}
        </p>

        <ul v-else class="list-unstyled mb-0">
            <li
                v-for="(change, index) in changes"
                :key="index"
                class="border-bottom py-2"
                :data-testid="`diff-${change.section}-${change.op}`"
            >
                <span class="me-2 fw-normal" :class="opClass[change.op]">{{ tx(`diff.${change.op}`) }}</span>
                <strong>{{ sectionLabel(change.section) }}</strong>

                <!-- conjuntos: CID e medicação -->
                <template v-if="change.section.startsWith('cid.')">
                    <span class="font-monospace ms-1">{{ cidLabel(change.value) }}</span>
                </template>
                <template v-else-if="change.section === 'medication'">
                    <span class="ms-1">{{ labels.medications?.[change.value] ?? change.value }}</span>
                </template>

                <!-- tarefa -->
                <template v-else-if="change.section === 'task'">
                    <code class="ms-1">{{ change.key }}</code>
                    <template v-if="change.op === 'changed'">
                        — {{ fieldLabel(change.field) }}:
                        {{
                            tx('diff.from_to', {
                                from: formatValue(change.field, change.from),
                                to: formatValue(change.field, change.to),
                            })
                        }}
                    </template>
                    <small v-else class="d-block text-body-secondary">{{ taskSummary(change.value) }}</small>
                </template>

                <!-- pedido do médico -->
                <template v-else-if="change.section === 'doctor_order'">
                    — {{ fieldLabel(change.field) }}:
                    {{
                        tx('diff.from_to', {
                            from: formatValue(change.field, change.from),
                            to: formatValue(change.field, change.to),
                        })
                    }}
                </template>

                <!-- referência -->
                <template v-else-if="change.section === 'reference'">
                    <span class="ms-1 text-break">{{ change.key }}</span>
                    <template v-if="change.op === 'changed'">
                        — {{ fieldLabel(change.field) }}:
                        {{
                            tx('diff.from_to', {
                                from: formatValue(change.field, change.from),
                                to: formatValue(change.field, change.to),
                            })
                        }}
                    </template>
                </template>

                <!-- textos longos: antes e depois lado a lado -->
                <div v-else-if="isTextChange(change)" class="row g-2 mt-1">
                    <div class="col-md-6">
                        <div class="small text-body-secondary">{{ tx('diff.removed') }}</div>
                        <p
                            class="small text-break mb-0 border rounded p-2 bg-body-tertiary"
                            style="white-space: pre-line"
                        >
                            {{ change.from || tx('empty_value') }}
                        </p>
                    </div>
                    <div class="col-md-6">
                        <div class="small text-body-secondary">{{ tx('diff.added') }}</div>
                        <p
                            class="small text-break mb-0 border rounded p-2 bg-body-tertiary"
                            style="white-space: pre-line"
                        >
                            {{ change.to || tx('empty_value') }}
                        </p>
                    </div>
                </div>
            </li>
        </ul>
    </section>
</template>
