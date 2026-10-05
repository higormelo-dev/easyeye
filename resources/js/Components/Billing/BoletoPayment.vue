<script setup>
import { computed } from 'vue';
import CopyField from './CopyField.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';

/**
 * Boleto na tela do EasyEye: linha digitável com "Copiar", vencimento e o
 * PDF do gateway (abre em nova aba). Compensação em até 3 dias úteis — a
 * confirmação chega pelo tempo real (CheckoutFlow).
 */
const props = defineProps({
    boleto: { type: Object, required: true },
    ui: { type: Object, default: () => ({}) },
});

const { date } = useLocaleFormat();
const { tx } = useTrans(() => props.ui);

const line = computed(() => props.boleto.digitable_line || props.boleto.barcode || '');

const pdfUrl = computed(() => {
    try {
        const url = new URL(String(props.boleto.pdf_url ?? ''));

        return url.protocol === 'https:' ? url.href : '';
    } catch {
        return '';
    }
});
</script>

<template>
    <div class="ee-boleto" data-test="checkout-boleto">
        <CopyField
            v-if="line"
            :label="ui.boleto_line"
            :value="line"
            :copy-label="ui.copy"
            :copied-label="ui.copied"
            :failed-label="ui.copy_failed"
            multiline
        />

        <div class="d-flex flex-column flex-sm-row align-items-sm-center gap-2 mt-3">
            <p v-if="boleto.due_date" class="mb-0 fw-semibold flex-grow-1" data-test="boleto-due">
                <i class="ti ti-calendar-due me-1" aria-hidden="true"></i
                >{{ tx('boleto_due', { date: date(boleto.due_date) }) }}
            </p>
            <a
                v-if="pdfUrl"
                :href="pdfUrl"
                target="_blank"
                rel="noopener noreferrer"
                class="btn btn-outline-primary"
                data-test="boleto-pdf"
            >
                <i class="ti ti-file-download me-1" aria-hidden="true"></i>{{ ui.boleto_download }}
                <span class="visually-hidden">{{ ui.opens_new_tab }}</span>
            </a>
        </div>

        <p class="small text-muted mt-3 mb-0">{{ ui.boleto_hint }}</p>
    </div>
</template>
