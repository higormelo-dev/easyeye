<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout     from '@/Layouts/AppLayout.vue';
import PageHeader    from '@/Components/Panel/PageHeader.vue';
import FlashMessage  from '@/Pages/Panel/Financial/DoctorPayouts/FlashMessage.vue';
import StatementView from '@/Pages/Panel/Financial/DoctorPayouts/StatementView.vue';

/**
 * "Meus repasses" › demonstrativo (médico, só leitura): o mesmo
 * StatementView da clínica, sem ajustes, pagamento ou reabertura — só PDF e
 * voltar.
 */
const props = defineProps({
    breadcrumbs: { type: Array,  default: () => [] },
    statement:   { type: Object, required: true },   // { payout, groups, adjustments }
    routes:      { type: Object, required: true },   // { index, pdf }
    t:           { type: Object, default: () => ({}) },
});

const code = computed(() => props.statement?.payout?.code ?? '');
</script>

<template>
    <AppLayout :title="`${t.statement_title} ${code}`" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader :title="t.statement_title">
                <template #actions>
                    <a :href="routes.pdf" class="btn btn-outline-secondary btn-sm" data-test="statement-pdf">
                        <i class="ti ti-file-type-pdf me-1" aria-hidden="true"></i>{{ t.download_pdf }}
                    </a>
                </template>
            </PageHeader>

            <p class="small mb-3">
                <Link :href="routes.index" data-test="back-my-payouts">
                    <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>{{ t.my_title }}
                </Link>
            </p>

            <FlashMessage />

            <StatementView :statement="statement" :t="t" />
        </div>
    </AppLayout>
</template>
