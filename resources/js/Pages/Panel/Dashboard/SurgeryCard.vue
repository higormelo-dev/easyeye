<script setup>
import { Link } from '@inertiajs/vue3';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

/**
 * Cartão resumido do módulo cirúrgico no Dashboard (admin e médico, só com o
 * recurso do plano): cirurgias dos últimos 90 dias, taxa de autorização e pedidos
 * aguardando resposta. Só números agregados; o médico vê os próprios.
 * `card` chega `null` do controller quando não se aplica — o cartão nem monta.
 */
defineProps({
    card: { type: Object, required: true }, // { surgeries, rate, awaiting, url, labels }
});

const { number } = useLocaleFormat();
</script>

<template>
    <section class="card db-card surgery-card" :aria-label="card.labels.title">
        <div class="db-card-header">
            <h3 class="db-card-title">
                <i class="ti ti-scissors" aria-hidden="true"></i>
                {{ card.labels.title }}
            </h3>
        </div>
        <div class="card-body">
            <dl class="row mb-2">
                <dt class="col-7 fw-normal text-muted">{{ card.labels.surgeries }}</dt>
                <dd class="col-5 text-end fw-semibold mb-1" data-test="surgery-card-surgeries">
                    {{ number(card.surgeries) }}
                </dd>
                <dt class="col-7 fw-normal text-muted">{{ card.labels.rate }}</dt>
                <dd class="col-5 text-end fw-semibold mb-1" data-test="surgery-card-rate">
                    {{ card.rate === null ? '—' : number(card.rate, 1) + '%' }}
                </dd>
                <dt class="col-7 fw-normal text-muted">{{ card.labels.open }}</dt>
                <dd class="col-5 text-end fw-semibold mb-0" data-test="surgery-card-awaiting">
                    {{ number(card.awaiting) }}
                </dd>
            </dl>
            <Link :href="card.url" class="small">{{ card.labels.details }}</Link>
        </div>
    </section>
</template>
