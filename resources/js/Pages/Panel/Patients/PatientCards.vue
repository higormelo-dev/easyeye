<script setup>
import { ref, watch, onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    cardsUrl:      { type: String, required: true },
    initialSearch: { type: String, default: '' },
});

const emit = defineEmits(['edit', 'view', 'delete', 'toggleActive', 'restore']);

const fallbackPhoto = '/img/system/team.png';
const patients  = ref([]);
const meta      = ref({ current_page: 1, last_page: 1, total: 0 });
const loading   = ref(false);
const page      = ref(1);

async function fetchCards(p = 1) {
    loading.value = true;
    page.value    = p;
    try {
        const params = new URLSearchParams({ page: p, search: props.initialSearch });
        const res    = await fetch(`${props.cardsUrl}?${params}`);
        const json   = await res.json();
        patients.value = json.data;
        meta.value     = json.meta;
    } finally {
        loading.value = false;
    }
}

watch(() => props.initialSearch, () => fetchCards(1));

let removeSuccessListener;
onMounted(() => {
    fetchCards(1);
    removeSuccessListener = router.on('success', () => fetchCards(page.value));
});
onUnmounted(() => removeSuccessListener?.());
</script>

<template>
    <!-- Loading -->
    <div v-if="loading" class="text-center py-5">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Carregando...</span>
        </div>
    </div>

    <template v-else>
        <!-- Empty state -->
        <div v-if="patients.length === 0" class="text-center text-muted py-5">
            <i class="ti ti-user-off fs-1 mb-3 d-block"></i>
            <p>Nenhum paciente encontrado.</p>
        </div>

        <!-- Cards grid -->
        <div v-else class="row g-3">
            <div
                v-for="p in patients"
                :key="p.id"
                class="col-xs-12 col-sm-6 col-md-4 col-lg-4 col-xl-3"
            >
                <div class="card card-body h-100">
                    <div class="row align-items-center">
                        <div class="col-3 text-center">
                            <img
                                :src="p.photo_url ?? fallbackPhoto"
                                :alt="p.full_name"
                                class="img-fluid rounded-circle"
                                style="width:56px;height:56px;object-fit:cover;"
                                @error="$event.target.src = fallbackPhoto"
                            >
                        </div>
                        <div class="col-9">
                            <h6 class="mb-1 fw-semibold lh-sm">{{ p.full_name }}</h6>
                            <span
                                :class="p.active
                                    ? 'badge badge-soft-success rounded text-success border border-success fs-12'
                                    : 'badge badge-soft-danger rounded text-danger border border-danger fs-12'"
                            >{{ p.active ? 'Ativo' : 'Inativo' }}</span>
                        </div>
                    </div>

                    <address class="small text-muted mt-2 mb-1">
                        <strong>Código:</strong> {{ p.code }}<br>
                        <strong>Convênio:</strong> {{ p.covenant ?? 'Não informado' }}<br>
                        <strong>Pele:</strong> {{ p.skin ?? 'Não informado' }}<br>
                        <strong>Íris:</strong> {{ p.iris ?? 'Não informado' }}
                    </address>

                    <hr class="my-2">

                    <div class="d-flex align-items-center justify-content-end gap-1">
                        <!-- RESTORE -->
                        <template v-if="p.mode === 'restore'">
                            <button
                                class="btn btn-sm btn-outline-secondary"
                                title="Restaurar"
                                @click="$emit('restore', p.id)"
                            >
                                <i class="ti ti-recycle"></i>
                            </button>
                        </template>

                        <!-- VIEW ONLY -->
                        <template v-else-if="p.mode === 'view_only'">
                            <button
                                class="btn btn-sm btn-outline-secondary"
                                @click="$emit('view', p.id)"
                            >
                                <i class="ti ti-eye"></i>
                            </button>
                        </template>

                        <!-- FULL -->
                        <template v-else-if="p.mode === 'full'">
                            <button
                                class="btn btn-sm btn-outline-secondary"
                                title="Visualizar"
                                @click="$emit('view', p.id)"
                            >
                                <i class="ti ti-eye"></i>
                            </button>
                            <a
                                :href="p.medical_records_url"
                                class="btn btn-sm btn-outline-info"
                                title="Prontuário"
                            >
                                <i class="ti ti-stethoscope"></i>
                            </a>
                            <div class="dropdown">
                                <button
                                    class="btn btn-sm btn-outline-secondary"
                                    data-bs-toggle="dropdown"
                                >
                                    <i class="ti ti-dots-vertical"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end p-2">
                                    <li>
                                        <button
                                            class="dropdown-item rounded-1"
                                            @click="$emit('edit', p.id)"
                                        >
                                            <i class="ti ti-edit me-1"></i> Editar
                                        </button>
                                    </li>
                                    <li>
                                        <button
                                            class="dropdown-item rounded-1"
                                            @click="$emit('toggleActive', p.id, p.active)"
                                        >
                                            <i :class="`ti me-1 ${p.active ? 'ti-lock-open' : 'ti-lock'}`"></i>
                                            {{ p.active ? 'Desativar' : 'Ativar' }}
                                        </button>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <button
                                            class="dropdown-item rounded-1 text-danger"
                                            @click="$emit('delete', p.id)"
                                        >
                                            <i class="ti ti-trash me-1"></i> Excluir
                                        </button>
                                    </li>
                                </ul>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pagination -->
        <nav v-if="meta.last_page > 1" class="d-flex justify-content-center mt-3">
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item" :class="{ disabled: meta.current_page === 1 }">
                    <button class="page-link" @click="fetchCards(meta.current_page - 1)">
                        <i class="ti ti-arrow-left text-body"></i>
                    </button>
                </li>
                <template v-for="p in meta.last_page" :key="p">
                    <li class="page-item" :class="{ active: p === meta.current_page }">
                        <button class="page-link" @click="fetchCards(p)">{{ p }}</button>
                    </li>
                </template>
                <li class="page-item" :class="{ disabled: meta.current_page === meta.last_page }">
                    <button class="page-link" @click="fetchCards(meta.current_page + 1)">
                        <i class="ti ti-arrow-right text-body"></i>
                    </button>
                </li>
            </ul>
        </nav>
    </template>
</template>
