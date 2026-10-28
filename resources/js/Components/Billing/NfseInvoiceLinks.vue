<script setup>
import { computed } from 'vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

const props = defineProps({ nfse: { type: Object, default: null }, t: { type: Object, default: () => ({}) } });
const { dateTime } = useLocaleFormat();
const label = computed(() => props.nfse?.number ?? '');
const visible = computed(() => ['autorizado', 'cancelado'].includes(props.nfse?.status));
</script>

<template>
    <div v-if="visible" class="mt-2 small" data-test="nfse-invoice-links">
        <span class="d-block fw-semibold"
            ><i class="ti ti-file-invoice me-1" aria-hidden="true"></i>{{ t.title
            }}<span v-if="label"> · {{ label }}</span></span
        >
        <span v-if="nfse.issued_at" class="text-muted d-block">{{ dateTime(nfse.issued_at) }}</span>
        <span v-if="nfse.cancelled" class="badge badge-soft-secondary mt-1">{{ t.cancelled }}</span>
        <div class="d-flex flex-wrap gap-2 mt-1">
            <a v-if="nfse.pdf_url" :href="nfse.pdf_url" target="_blank" rel="noopener" class="link-primary">{{
                t.pdf
            }}</a
            ><a v-if="nfse.xml_url" :href="nfse.xml_url" target="_blank" rel="noopener" class="link-primary">{{
                t.xml
            }}</a
            ><a
                v-if="nfse.cancel_xml_url"
                :href="nfse.cancel_xml_url"
                target="_blank"
                rel="noopener"
                class="link-primary"
                >{{ t.cancel_xml }}</a
            ><a v-if="nfse.mirror_url" :href="nfse.mirror_url" target="_blank" rel="noopener" class="link-primary">{{
                t.download
            }}</a>
        </div>
    </div>
</template>
