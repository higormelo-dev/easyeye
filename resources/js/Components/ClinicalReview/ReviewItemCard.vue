<script setup>
import { computed, useId } from 'vue';
import RecallRuleCard from '@/Components/Recall/RecallRuleCard.vue';
import RecallContentDiff from '@/Components/Recall/RecallContentDiff.vue';
import RecallTimelineExamples from '@/Components/Recall/RecallTimelineExamples.vue';
import { makeTx, NEUTRAL_BADGE } from '@/Support/recallRule.js';
import { COMMENT_MAX, COMMENT_MIN, PROPOSED_MAX, needsComment } from '@/Support/clinicalReview.js';

/**
 * ReviewItemCard — UMA versão de protocolo na página do Revisor: a regra (o mesmo
 * RecallRuleCard do Manager e da clínica — o que ele aprova é o que a clínica lê), o que
 * mudou desde a versão anterior, os exemplos de datas e a decisão dele.
 *
 * Os erros chegam já em texto (`errors[caminho]`); os ids dos campos vêm da página
 * (`idFor`), que é quem leva o foco ao primeiro campo com erro.
 */
const props = defineProps({
    item: { type: Object, required: true },
    /** { decision, comment, proposed_changes } deste item. */
    modelValue: { type: Object, required: true },
    decisions: { type: Array, required: true },
    errors: { type: Object, default: () => ({}) },
    exampleDate: { type: String, default: '' },
    ruleT: { type: Object, required: true },
    t: { type: Object, required: true },
    idFor: { type: Function, required: true },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const tx = makeTx(() => props.t);
const uid = useId();

const path = (field) => `decisions.${props.item.id}.${field}`;
const error = (field) => props.errors[path(field)] ?? '';
const needs = computed(() => needsComment(props.modelValue.decision));
const commentLength = computed(() => String(props.modelValue.comment ?? '').length);

function update(field, value) {
    emit('update:modelValue', { ...props.modelValue, [field]: value });
}

/** O primeiro rádio leva o id do grupo (é para onde o foco vai quando falta a decisão). */
function radioId(index, value) {
    return index === 0 ? props.idFor(path('decision')) : `${props.idFor(path('decision'))}-${value}`;
}
</script>

<template>
    <article class="card shadow-sm mb-4" :aria-labelledby="`${uid}-title`" :data-testid="`review-item-${item.key}`">
        <div class="card-header bg-body d-flex flex-wrap align-items-center gap-2">
            <h2 :id="`${uid}-title`" class="h5 mb-0 me-auto">{{ item.title }}</h2>
            <span class="badge text-bg-primary fw-normal">{{ tx('item.version', { version: item.version }) }}</span>
            <span class="badge fw-normal font-monospace" :class="NEUTRAL_BADGE">{{
                tx('item.hash', { hash: item.short_hash })
            }}</span>
        </div>

        <div class="card-body">
            <p v-if="item.summary" class="text-body-secondary">{{ item.summary }}</p>

            <div
                v-if="!item.available"
                class="review-alert review-alert--warning mb-3"
                role="alert"
                data-testid="item-unavailable"
            >
                {{ tx('item.unavailable') }}
            </div>

            <template v-if="item.content">
                <RecallRuleCard :content="item.content" :t="ruleT" />

                <hr />
                <p v-if="item.parent" class="small text-body-secondary mb-2" data-testid="item-parent">
                    {{
                        item.parent.validated
                            ? tx('item.parent_validated', { version: item.parent.version })
                            : tx('item.parent_not_validated', { version: item.parent.version })
                    }}
                </p>
                <RecallContentDiff :changes="item.diff" :has-parent="!!item.parent" :t="ruleT" />

                <template v-if="item.examples.length">
                    <hr />
                    <RecallTimelineExamples :examples="item.examples" :anchor="exampleDate" :t="ruleT" />
                </template>
            </template>

            <hr />

            <fieldset v-if="item.available" :disabled="disabled" class="border-0 p-0 m-0" style="min-width: 0">
                <legend class="h6 fw-semibold">
                    {{ tx('item.decision_legend', { title: item.title, version: item.version }) }}
                </legend>

                <div>
                    <div v-for="(option, index) in decisions" :key="option.value" class="form-check mb-2">
                        <input
                            :id="radioId(index, option.value)"
                            class="form-check-input"
                            type="radio"
                            :name="`${uid}-decision`"
                            :value="option.value"
                            :checked="modelValue.decision === option.value"
                            :aria-invalid="error('decision') ? 'true' : 'false'"
                            :aria-describedby="error('decision') ? idFor(path('decision')) + '-error' : undefined"
                            :data-testid="`decision-${item.key}-${option.value}`"
                            @change="update('decision', option.value)"
                        />
                        <label class="form-check-label" :for="radioId(index, option.value)">
                            <span class="fw-medium">{{ option.label }}</span>
                            <small class="d-block text-body-secondary">{{
                                tx(`item.decision_hint.${option.value}`)
                            }}</small>
                        </label>
                    </div>
                </div>
                <div
                    v-if="error('decision')"
                    :id="idFor(path('decision')) + '-error'"
                    class="invalid-feedback d-block mb-2"
                    role="alert"
                >
                    {{ error('decision') }}
                </div>

                <div class="mt-3">
                    <label :for="idFor(path('comment'))" class="form-label fw-medium">
                        {{ needs ? tx('item.comment_required') : tx('item.comment_optional') }}
                    </label>
                    <textarea
                        :id="idFor(path('comment'))"
                        class="form-control"
                        :class="{ 'is-invalid': error('comment') }"
                        rows="4"
                        :maxlength="COMMENT_MAX"
                        :value="modelValue.comment"
                        :required="needs"
                        :aria-required="needs ? 'true' : 'false'"
                        :aria-invalid="error('comment') ? 'true' : 'false'"
                        :aria-describedby="`${idFor(path('comment'))}-hint${error('comment') ? ' ' + idFor(path('comment')) + '-error' : ''}`"
                        :data-testid="`comment-${item.key}`"
                        @input="update('comment', $event.target.value)"
                    ></textarea>
                    <div :id="`${idFor(path('comment'))}-hint`" class="form-text d-flex justify-content-between gap-2">
                        <span>{{
                            needs
                                ? tx('item.comment_hint_required', { min: COMMENT_MIN })
                                : tx('item.comment_hint_optional')
                        }}</span>
                        <span class="text-nowrap">{{ commentLength }}/{{ COMMENT_MAX }}</span>
                    </div>
                    <div
                        v-if="error('comment')"
                        :id="idFor(path('comment')) + '-error'"
                        class="invalid-feedback d-block"
                        role="alert"
                    >
                        {{ error('comment') }}
                    </div>
                </div>

                <div v-if="modelValue.decision === 'changes_requested' || modelValue.proposed_changes" class="mt-3">
                    <label :for="idFor(path('proposed_changes'))" class="form-label fw-medium">{{
                        tx('item.proposed_label')
                    }}</label>
                    <textarea
                        :id="idFor(path('proposed_changes'))"
                        class="form-control"
                        :class="{ 'is-invalid': error('proposed_changes') }"
                        rows="3"
                        :maxlength="PROPOSED_MAX"
                        :value="modelValue.proposed_changes"
                        :aria-invalid="error('proposed_changes') ? 'true' : 'false'"
                        :aria-describedby="`${idFor(path('proposed_changes'))}-hint`"
                        :data-testid="`proposed-${item.key}`"
                        @input="update('proposed_changes', $event.target.value)"
                    ></textarea>
                    <div :id="`${idFor(path('proposed_changes'))}-hint`" class="form-text">
                        {{ tx('item.proposed_hint') }}
                    </div>
                    <div v-if="error('proposed_changes')" class="invalid-feedback d-block" role="alert">
                        {{ error('proposed_changes') }}
                    </div>
                </div>
            </fieldset>
        </div>
    </article>
</template>
