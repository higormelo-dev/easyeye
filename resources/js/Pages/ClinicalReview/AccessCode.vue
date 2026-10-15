<script setup>
import { nextTick, onMounted, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import ReviewLayout from '@/Layouts/ReviewLayout.vue';
import { makeTx } from '@/Support/recallRule.js';
import { focusHeading } from '@/Support/clinicalReviewFocus.js';

/**
 * Código de acesso (PIN) do convite — a 2ª barreira, entregue à pessoa por OUTRO canal
 * (telefone, mensagem). A resposta de erro é a mesma para código errado e para convite
 * travado depois de 5 erros: a tela não confirma qual dos dois aconteceu.
 */
const props = defineProps({
    reviewerName: { type: String, default: '' },
    t: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const tx = makeTx(() => props.t);
const form = useForm({ code: '' });
const input = ref(null);
const heading = ref(null);

onMounted(() => nextTick(() => focusHeading(heading.value)));

function submit() {
    form.post(props.urls.verify, {
        preserveScroll: true,
        onError: () => {
            form.reset('code');
            nextTick(() => input.value?.focus());
        },
    });
}
</script>

<template>
    <Head :title="tx('code.title')" />

    <ReviewLayout :t="t" current="code" :locale-url="urls.locale">
        <section class="card shadow-sm mx-auto" style="max-width: 520px" data-testid="review-code">
            <div class="card-body p-4">
                <h1 ref="heading" tabindex="-1" class="h4">{{ tx('code.title') }}</h1>
                <p v-if="reviewerName" class="mb-2">{{ tx('show.greeting', { name: reviewerName }) }}</p>
                <p class="text-body-secondary">{{ tx('code.body') }}</p>

                <form novalidate @submit.prevent="submit">
                    <label for="review-code-input" class="form-label fw-medium">{{ tx('code.label') }}</label>
                    <input
                        id="review-code-input"
                        ref="input"
                        v-model="form.code"
                        type="text"
                        class="form-control form-control-lg font-monospace text-uppercase"
                        :class="{ 'is-invalid': form.errors.code }"
                        autocomplete="one-time-code"
                        autocapitalize="characters"
                        spellcheck="false"
                        maxlength="30"
                        :aria-invalid="form.errors.code ? 'true' : 'false'"
                        :aria-describedby="form.errors.code ? 'review-code-error' : 'review-code-hint'"
                        required
                    />
                    <div id="review-code-hint" class="form-text">{{ tx('code.hint') }}</div>
                    <div v-if="form.errors.code" id="review-code-error" class="invalid-feedback d-block" role="alert">
                        {{ form.errors.code }}
                    </div>

                    <button
                        type="submit"
                        class="btn btn-primary mt-3 w-100"
                        :disabled="form.processing || !form.code.trim()"
                    >
                        <span
                            v-if="form.processing"
                            class="spinner-border spinner-border-sm me-2"
                            aria-hidden="true"
                        ></span>
                        {{ tx('code.submit') }}
                    </button>
                </form>
            </div>
        </section>
    </ReviewLayout>
</template>
