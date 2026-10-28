<script setup>
import { nextTick, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import ReviewLayout from '@/Layouts/ReviewLayout.vue';
import { makeTx } from '@/Support/recallRule.js';
import { focusHeading } from '@/Support/clinicalReviewFocus.js';

/**
 * A página GENÉRICA de "link indisponível" do Revisor Clínico: é o destino de TODO link
 * ruim — id desconhecido, token errado, assinatura inválida, convite revogado, vencido ou
 * substituído — e nunca diz qual foi o caso (anti-enumeração, ADR 0002). Lista as
 * possibilidades todas juntas e diz o que fazer.
 */
const props = defineProps({
    t: { type: Object, required: true },
    urls: { type: Object, default: () => ({}) },
});

const tx = makeTx(() => props.t);
const heading = ref(null);

onMounted(() => nextTick(() => focusHeading(heading.value)));
</script>

<template>
    <Head :title="tx('unavailable.title')" />

    <ReviewLayout :t="t" current="unavailable" :locale-url="urls.locale">
        <section class="card shadow-sm" data-testid="review-unavailable">
            <div class="card-body p-4 p-md-5 text-center">
                <i class="ti ti-link-off fs-1 text-body-secondary" aria-hidden="true"></i>
                <h1 ref="heading" tabindex="-1" class="h3 mt-3">{{ tx('unavailable.title') }}</h1>
                <p class="mb-3">{{ tx('unavailable.body') }}</p>
                <ul class="list-unstyled text-body-secondary mb-3">
                    <li>{{ tx('unavailable.reason_expired') }}</li>
                    <li>{{ tx('unavailable.reason_replaced') }}</li>
                    <li>{{ tx('unavailable.reason_used') }}</li>
                </ul>
                <p class="mb-0">{{ tx('unavailable.action') }}</p>
            </div>
        </section>
    </ReviewLayout>
</template>
