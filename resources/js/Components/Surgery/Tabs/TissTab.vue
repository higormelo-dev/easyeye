<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import TissGuideCard from '@/Components/Surgery/Tiss/TissGuideCard.vue';

/**
 * Aba "TISS": solicitação de internação e anexo de OPME do pedido cirúrgico. Layout e
 * geração das duas guias; o detalhe de cada uma (entrega, campos, itens, envio) fica em
 * TissGuideCard. Textos em `t.tiss.*` (lang/{locale}/surgery_tiss.php).
 */
const props = defineProps({
    section: { type: Object, required: true },
    surgicalCase: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
    urls: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
    t: { type: Object, required: true },
});

const emit = defineEmits(['reload', 'edit']);

const tx = computed(() => props.t.tiss ?? {});
const guides = computed(() => props.section.guides ?? {});
const canGenerate = computed(() => Boolean(props.section.can?.generate));
const canAct = computed(() => Boolean(props.section.can?.act));

const anyDoubt = computed(() => Object.values(guides.value).some((g) => g?.delivery?.state === 'doubt'));

function generate(type) {
    router.post(props.section.urls.store, { type }, { preserveScroll: true, onSuccess: () => emit('reload') });
}

const cards = computed(() => [
    {
        kind: 'inpatient',
        available: props.section.available?.inpatient,
        guide: guides.value.inpatient,
        hint: tx.value.generate_inpatient_hint,
        title: tx.value.inpatient_title,
    },
    {
        kind: 'opme',
        available: props.section.available?.opme,
        guide: guides.value.opme,
        hint: tx.value.generate_opme_hint,
        title: tx.value.opme_title,
    },
]);
</script>

<template>
    <div data-testid="tiss-tab">
        <div class="d-flex flex-wrap align-items-baseline gap-2 mb-2">
            <h2 class="h5 mb-0">{{ tx.title }}</h2>
            <span class="text-muted small">{{ tx.subtitle }}</span>
            <span v-if="section.operator?.name" class="ms-auto small"
                >{{ tx.operator_label }}: <strong>{{ section.operator.name }}</strong></span
            >
        </div>

        <div
            v-if="section.operator?.transport === 'mock'"
            class="alert alert-secondary py-2 small"
            data-testid="mock-notice"
        >
            {{ tx.mock_notice }}
        </div>
        <div v-if="!section.case_awaiting && !anyDoubt" class="alert alert-info py-2 small" data-testid="awaiting-hint">
            {{ tx.awaiting_hint }}
        </div>
        <div v-if="!canGenerate && canAct" class="text-muted small mb-2">{{ tx.case_locked_hint }}</div>

        <template v-for="card in cards" :key="card.kind">
            <TissGuideCard
                v-if="card.available && card.guide"
                :kind="card.kind"
                :guide="card.guide"
                :can-act="canAct"
                :case-awaiting="Boolean(section.case_awaiting)"
                :urls="section.urls"
                :table-codes="section.table_codes"
                :t="tx"
                @reload="emit('reload')"
            />
            <section v-else-if="card.available" class="card mb-3" :data-testid="`tiss-empty-${card.kind}`">
                <div class="card-body d-flex flex-wrap align-items-center gap-3">
                    <div class="me-auto">
                        <h3 class="h6 mb-1">{{ card.title }}</h3>
                        <p class="text-muted small mb-0">{{ tx.not_generated }} {{ card.hint }}</p>
                    </div>
                    <button
                        v-if="canGenerate"
                        type="button"
                        class="btn btn-primary btn-sm"
                        :data-testid="`generate-${card.kind}`"
                        @click="generate(card.kind)"
                    >
                        {{ tx.generate }}
                    </button>
                </div>
            </section>
        </template>
    </div>
</template>
