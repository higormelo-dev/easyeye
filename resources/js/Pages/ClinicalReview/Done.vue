<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import ReviewLayout from '@/Layouts/ReviewLayout.vue';
import { makeTx } from '@/Support/recallRule.js';
import { groupedHash } from '@/Support/clinicalReview.js';
import { NEUTRAL_BADGE } from '@/Support/recallRule.js';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import { focusHeading } from '@/Support/clinicalReviewFocus.js';

/**
 * Parecer enviado: o recibo (código de verificação do envio, decisões) e o que acontece
 * agora. É também o destino de quem reabre um link já respondido — a mesma página, sem
 * nunca voltar ao formulário.
 */
const props = defineProps({
    reviewerName: { type: String, default: '' },
    submission: { type: Object, default: null },
    decisions: { type: Array, default: () => [] },
    t: { type: Object, required: true },
    urls: { type: Object, default: () => ({}) },
});

const tx = makeTx(() => props.t);
const { dateTime } = useLocaleFormat();
const heading = ref(null);
const copied = ref(false);
const code = computed(() => groupedHash(props.submission?.submission_hash));

onMounted(() => nextTick(() => focusHeading(heading.value)));

async function copyCode() {
    try {
        await navigator.clipboard.writeText(props.submission?.submission_hash ?? '');
        copied.value = true;
        setTimeout(() => (copied.value = false), 2500);
    } catch {
        // Sem permissão de área de transferência: o código está na tela para copiar à mão.
    }
}
</script>

<template>
    <Head :title="tx('done.title')" />

    <ReviewLayout :t="t" current="done" :locale-url="urls.locale">
        <section class="card shadow-sm" data-testid="review-done">
            <div class="card-body p-4 p-md-5">
                <div class="text-center mb-4">
                    <i class="ti ti-circle-check fs-1 text-success" aria-hidden="true"></i>
                    <h1 ref="heading" tabindex="-1" class="h3 mt-2">{{ tx('done.title') }}</h1>
                    <p v-if="reviewerName" class="mb-0">{{ tx('done.thanks', { name: reviewerName }) }}</p>
                </div>

                <dl v-if="submission" class="row mb-4">
                    <dt class="col-sm-4 fw-medium">{{ tx('done.sent_at') }}</dt>
                    <dd class="col-sm-8" data-testid="done-sent-at">{{ dateTime(submission.submitted_at) }}</dd>

                    <dt class="col-sm-4 fw-medium">{{ tx('done.code') }}</dt>
                    <dd class="col-sm-8">
                        <code class="user-select-all text-break text-body-emphasis" data-testid="done-code">{{
                            code
                        }}</code>
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-primary ms-2"
                            data-testid="copy-code"
                            @click="copyCode"
                        >
                            {{ copied ? tx('done.copied') : tx('done.copy') }}
                        </button>
                        <div class="form-text">{{ tx('done.code_hint') }}</div>
                    </dd>

                    <dt class="col-sm-4 fw-medium">{{ tx('done.receipt') }}</dt>
                    <dd class="col-sm-8" data-testid="done-receipt">
                        {{ submission.receipt_sent ? tx('done.receipt_sent') : tx('done.receipt_pending') }}
                    </dd>
                </dl>

                <h2 class="h6 fw-semibold">{{ tx('done.decisions') }}</h2>
                <ul class="list-group mb-4" data-testid="done-decisions">
                    <li
                        v-for="decision in decisions"
                        :key="decision.id"
                        class="list-group-item d-flex flex-wrap justify-content-between gap-2"
                    >
                        <span>
                            <strong>{{ decision.title }}</strong>
                            <span class="text-body-secondary">
                                — {{ tx('done.version', { version: decision.version }) }}</span
                            >
                        </span>
                        <span class="badge fw-normal align-self-center" :class="NEUTRAL_BADGE">{{
                            decision.label
                        }}</span>
                    </li>
                </ul>

                <h2 class="h6 fw-semibold">{{ tx('done.next_title') }}</h2>
                <p class="mb-2">{{ tx('done.next_body') }}</p>
                <p class="mb-0 text-body-secondary">{{ tx('done.close') }}</p>
            </div>
        </section>
    </ReviewLayout>
</template>
