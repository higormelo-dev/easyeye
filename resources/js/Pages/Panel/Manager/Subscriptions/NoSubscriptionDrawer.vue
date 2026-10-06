<script setup>
import { ref, watch } from 'vue';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

/**
 * Card "Sem assinatura": mostra QUAIS empresas clientes estão sem assinatura
 * (mesmo critério do contador) e cria a assinatura de cada uma já com a
 * empresa escolhida no modal de "Nova assinatura".
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    total: { type: Number, default: 0 },
    t: { type: Object, required: true },
});

const emit = defineEmits(['close', 'create']);

const { date } = useLocaleFormat();
const companies = ref([]);
const loading = ref(false);
const failed = ref(false);

async function load() {
    loading.value = true;
    failed.value = false;

    try {
        const url = route('manager.subscriptions.entities', { without_subscription: 1 });
        const { data } = await window.axios.get(url, { headers: { Accept: 'application/json' } });
        companies.value = Array.isArray(data?.data) ? data.data : [];
    } catch {
        companies.value = [];
        failed.value = true;
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) load();
    },
);
</script>

<template>
    <OffcanvasPanel :open="open" :width="520" :loading="loading" :loading-label="t.loading" @close="emit('close')">
        <template #header>
            <div class="flex-grow-1 min-w-0">
                <h5 class="mb-0 fw-semibold">
                    <i class="ti ti-building-plus me-2 text-primary" aria-hidden="true"></i>
                    {{ t.no_sub_drawer_title }}
                </h5>
                <p class="mb-0 fs-12 text-muted">{{ t.no_sub_drawer_hint }}</p>
            </div>
        </template>

        <div v-if="failed" class="alert alert-danger fs-13 d-flex align-items-center gap-2" role="alert">
            <span class="flex-grow-1">{{ t.no_sub_drawer_error }}</span>
            <button type="button" class="btn btn-sm btn-outline-danger" data-test="no-sub-retry" @click="load">
                {{ t.no_sub_drawer_retry }}
            </button>
        </div>

        <p v-else-if="!loading && companies.length === 0" class="text-muted fs-13 mb-0" data-test="no-sub-empty">
            {{ t.no_sub_drawer_empty }}
        </p>

        <ul v-else class="list-unstyled mb-0" data-test="no-sub-list">
            <li
                v-for="company in companies"
                :key="company.id"
                class="d-flex align-items-center gap-2 py-2 border-bottom"
                data-test="no-sub-company"
            >
                <div class="flex-grow-1 min-w-0">
                    <div class="fw-semibold fs-13 text-truncate">{{ company.name }}</div>
                    <div class="fs-12 text-muted">
                        <span v-if="company.sub_label">{{ company.sub_label }} · </span>
                        <span v-if="company.created_at"
                            >{{ t.no_sub_drawer_since }} {{ date(company.created_at) }}</span
                        >
                        <span v-if="!company.active" class="badge bg-secondary-subtle text-secondary ms-1">{{
                            t.no_sub_drawer_inactive
                        }}</span>
                    </div>
                </div>
                <button
                    type="button"
                    class="btn btn-sm btn-outline-primary flex-shrink-0"
                    data-test="no-sub-create"
                    @click="emit('create', company.id)"
                >
                    <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ t.no_sub_drawer_create }}
                </button>
            </li>
        </ul>

        <p
            v-if="!loading && companies.length > 0 && total > companies.length"
            class="fs-12 text-muted mt-2 mb-0"
            data-test="no-sub-more"
        >
            {{ t.no_sub_drawer_more.replace(':shown', companies.length).replace(':total', total) }}
        </p>
    </OffcanvasPanel>
</template>
