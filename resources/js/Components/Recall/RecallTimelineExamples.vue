<script setup>
import { computed } from 'vue';
import { makeTx } from '@/Support/recallRule.js';
import { useLocaleFormat } from '@/composables/useLocaleFormat';

/**
 * RecallTimelineExamples — "se o último atendimento foi em X, o sistema calcula…".
 * As datas vêm do servidor (TaskTimeline, o MESMO cálculo do avaliador de recall);
 * esta tela só formata. Serve para o Manager e o Revisor conferirem se os prazos fazem
 * sentido — nunca entra no hash.
 */
const props = defineProps({
    examples: { type: Array, default: () => [] },
    /** Data do último atendimento usada no exemplo (YYYY-MM-DD). */
    anchor: { type: String, default: '' },
    t: { type: Object, required: true },
});

const tx = makeTx(() => props.t);
const { date } = useLocaleFormat();
const labels = computed(() => props.t?.labels ?? {});

function name(example) {
    return example.kind === 'exam'
        ? (labels.value.exams?.[example.exam_key] ?? example.exam_key)
        : (labels.value.kinds?.[example.kind] ?? example.kind);
}

const hasStarts = computed(() => props.examples.some((example) => example.starts_at));
</script>

<template>
    <section class="recall-examples" data-testid="recall-examples">
        <h3 class="h6 fw-semibold">{{ tx('examples.title') }}</h3>
        <p v-if="anchor" class="mb-1">{{ tx('examples.intro', { date: date(anchor) }) }}</p>
        <p class="small text-body-secondary">{{ tx('examples.intro_hint') }}</p>

        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">{{ tx('examples.task') }}</th>
                        <th v-if="hasStarts" scope="col">{{ tx('examples.starts') }}</th>
                        <th scope="col">{{ tx('examples.due') }}</th>
                        <th scope="col">{{ tx('examples.invite') }}</th>
                        <th scope="col">{{ tx('examples.grace') }}</th>
                        <th scope="col">{{ tx('examples.lost') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="example in examples" :key="example.key" :data-testid="`example-${example.key}`">
                        <th scope="row" class="fw-medium">
                            {{ name(example) }}
                            <code class="d-block small fw-normal text-body-secondary">{{ example.key }}</code>
                        </th>
                        <td v-if="hasStarts">{{ example.starts_at ? date(example.starts_at) : tx('empty_value') }}</td>
                        <td>{{ date(example.due_at) }}</td>
                        <td>{{ date(example.invite) }}</td>
                        <td>{{ date(example.grace) }}</td>
                        <td>{{ date(example.lost_after) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
