<script setup>
import { computed, useId } from 'vue';
import { makeTx, NEUTRAL_BADGE } from '@/Support/recallRule.js';

/**
 * ReviewSummary — o que o Revisor está prestes a enviar, só para leitura: a decisão e o
 * comentário de cada versão e a identificação/declaração. É a última conferência antes do
 * POST único (depois dele não há como alterar).
 */
const props = defineProps({
    items: { type: Array, required: true },
    state: { type: Object, required: true },
    decisions: { type: Array, required: true },
    statement: { type: Object, required: true },
    t: { type: Object, required: true },
});

const tx = makeTx(() => props.t);
const uid = useId();
const a = computed(() => props.state.attestation);

const labelOf = (value) => props.decisions.find((option) => option.value === value)?.label ?? value;
</script>

<template>
    <section :aria-labelledby="`${uid}-title`" data-testid="review-summary">
        <h2 :id="`${uid}-title`" class="h5">{{ tx('summary.decisions') }}</h2>

        <ul class="list-group mb-4">
            <li v-for="item in items" :key="item.id" class="list-group-item" :data-testid="`summary-${item.key}`">
                <div class="d-flex flex-wrap justify-content-between gap-2">
                    <span>
                        <strong>{{ item.title }}</strong>
                        <span class="text-body-secondary"> — {{ tx('item.version', { version: item.version }) }}</span>
                    </span>
                    <span class="badge fw-normal align-self-center" :class="NEUTRAL_BADGE">{{
                        labelOf(state.decisions[item.id]?.decision)
                    }}</span>
                </div>
                <p v-if="state.decisions[item.id]?.comment" class="mb-1 mt-2 text-break" style="white-space: pre-line">
                    {{ state.decisions[item.id].comment }}
                </p>
                <p
                    v-if="state.decisions[item.id]?.proposed_changes"
                    class="mb-0 mt-2 small text-break"
                    style="white-space: pre-line"
                >
                    <strong>{{ tx('summary.proposed') }}</strong> {{ state.decisions[item.id].proposed_changes }}
                </p>
            </li>
        </ul>

        <h2 class="h5">{{ tx('summary.identity') }}</h2>
        <dl class="row mb-4" data-testid="summary-identity">
            <dt class="col-sm-4">{{ tx('attestation.name') }}</dt>
            <dd class="col-sm-8">{{ a.name }}</dd>
            <dt class="col-sm-4">{{ tx('attestation.email') }}</dt>
            <dd class="col-sm-8 text-break">{{ a.email }}</dd>
            <dt class="col-sm-4">{{ tx('summary.crm') }}</dt>
            <dd class="col-sm-8">{{ a.crm_number }} / {{ a.crm_uf }}</dd>
            <dt class="col-sm-4">{{ tx('attestation.rqe') }}</dt>
            <dd class="col-sm-8">{{ a.rqe }}</dd>
            <dt class="col-sm-4">{{ tx('attestation.specialty') }}</dt>
            <dd class="col-sm-8">{{ a.specialty }}</dd>
            <dt class="col-sm-4">{{ tx('summary.conflict') }}</dt>
            <dd class="col-sm-8">
                {{ a.has_conflict ? tx('summary.conflict_yes') : tx('summary.conflict_no') }}
                <span v-if="a.has_conflict" class="d-block text-break" style="white-space: pre-line">{{
                    a.conflict_description
                }}</span>
            </dd>
            <dt class="col-sm-4">{{ tx('summary.display') }}</dt>
            <dd class="col-sm-8">
                {{ a.allow_identity_display ? tx('summary.display_yes') : tx('summary.display_no') }}
            </dd>
            <dt class="col-sm-4">{{ tx('summary.statement') }}</dt>
            <dd class="col-sm-8">
                {{ tx('summary.statement_agreed', { code: String(statement.sha256 ?? '').slice(0, 12) }) }}
            </dd>
        </dl>

        <div class="review-alert review-alert--warning mb-3" role="note">
            <strong>{{ tx('summary.final_title') }}</strong> {{ tx('summary.final_body') }}
        </div>
    </section>
</template>
