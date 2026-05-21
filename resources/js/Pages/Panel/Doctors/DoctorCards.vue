<script setup>
import { ref, watch, onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup from '@/Components/Panel/ActionIconGroup.vue';

const props = defineProps({
    cardsUrl:      { type: String, required: true },
    initialSearch: { type: String, default: '' },
});

const emit    = defineEmits(['edit', 'delete', 'toggleActive']);
const doctors = ref([]);
const meta    = ref({ current_page: 1, last_page: 1, total: 0 });
const loading = ref(false);

async function fetchCards(p = 1) {
    loading.value = true;
    try {
        const params = new URLSearchParams({ page: p, search: props.initialSearch });
        const res    = await fetch(`${props.cardsUrl}?${params}`);
        const json   = await res.json();
        doctors.value = json.data;
        meta.value    = json.meta;
    } finally {
        loading.value = false;
    }
}

watch(() => props.initialSearch, () => fetchCards(1));

let removeSuccessListener;
onMounted(() => {
    fetchCards(1);
    removeSuccessListener = router.on('success', () => fetchCards(meta.value.current_page));
});
onUnmounted(() => removeSuccessListener?.());
</script>

<template>
    <div v-if="loading" class="text-center py-5">
        <div class="spinner-border text-primary" role="status"></div>
    </div>

    <template v-else>
        <div v-if="doctors.length === 0" class="text-center text-muted py-5">
            <i class="ti ti-stethoscope fs-1 mb-2 d-block"></i>
            <p>Nenhum médico encontrado.</p>
        </div>

        <div v-else class="row g-3">
            <div v-for="d in doctors" :key="d.id" class="col-sm-6 col-md-4 col-xl-3">
                <div class="card card-body h-100">
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <div class="position-relative">
                            <img
                                :src="d.photo_url" :alt="d.full_name"
                                class="rounded-circle"
                                style="width:52px;height:52px;object-fit:cover;"
                            >
                            <span
                                v-if="d.color"
                                class="position-absolute bottom-0 end-0 rounded-circle border border-white"
                                :style="{ background: d.color, width: '14px', height: '14px' }"
                            ></span>
                        </div>
                        <div>
                            <h6 class="mb-0 fw-semibold lh-sm">{{ d.full_name }}</h6>
                            <div class="text-muted" style="font-size:.75rem;">{{ d.email }}</div>
                        </div>
                    </div>
                    <div class="small text-muted mb-2">
                        <div><strong>Código:</strong> {{ d.code }}</div>
                        <div><strong>CRM:</strong> {{ d.record }}</div>
                        <div><strong>Especialidade:</strong> {{ d.record_specialty ?? '—' }}</div>
                    </div>
                    <hr class="my-2">
                    <ActionIconGroup v-if="d.mode === 'full'" align="end" gap="tight">
                        <ActionIconButton
                            icon="ti ti-edit"
                            title="Editar"
                            @click="$emit('edit', d.id)"
                        />
                        <ActionIconButton
                            :icon="`ti ${d.active ? 'ti-lock-open' : 'ti-lock'}`"
                            :title="d.active ? 'Desativar' : 'Ativar'"
                            @click="$emit('toggleActive', d.id, d.active)"
                        />
                        <ActionIconButton
                            icon="ti ti-trash"
                            title="Excluir"
                            variant="danger"
                            @click="$emit('delete', d.id)"
                        />
                    </ActionIconGroup>
                </div>
            </div>
        </div>

        <nav v-if="meta.last_page > 1" class="d-flex justify-content-center mt-3">
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item" :class="{ disabled: meta.current_page === 1 }">
                    <button class="page-link" @click="fetchCards(meta.current_page - 1)">
                        <i class="ti ti-arrow-left"></i>
                    </button>
                </li>
                <template v-for="p in meta.last_page" :key="p">
                    <li class="page-item" :class="{ active: p === meta.current_page }">
                        <button class="page-link" @click="fetchCards(p)">{{ p }}</button>
                    </li>
                </template>
                <li class="page-item" :class="{ disabled: meta.current_page === meta.last_page }">
                    <button class="page-link" @click="fetchCards(meta.current_page + 1)">
                        <i class="ti ti-arrow-right"></i>
                    </button>
                </li>
            </ul>
        </nav>
    </template>
</template>
