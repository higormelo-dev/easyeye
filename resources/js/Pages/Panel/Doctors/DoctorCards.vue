<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';
import ActionDropdown   from '@/Components/Panel/ActionDropdown.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup  from '@/Components/Panel/ActionIconGroup.vue';

/**
 * Cards de médicos no padrão de Patients/PatientCards: foto, nome, status,
 * dados principais e as mesmas ações da tabela (ver, horários, menu). O
 * endpoint `cards` devolve a mesma linha da tabela (toTableRow).
 *
 * Recarrega após qualquer visita Inertia bem-sucedida (busca, editar,
 * excluir, ativar), lendo a busca do próprio evento — sem requisição por tecla.
 */
const props = defineProps({
    cardsUrl: { type: String, required: true },
    search:   { type: String, default: '' },
    t:        { type: Object, default: () => ({}) },
});

const emit = defineEmits(['view', 'edit', 'delete', 'toggleActive']);

const doctors = ref([]);
const meta    = ref({ current_page: 1, last_page: 1, total: 0 });
const loading = ref(false);
const failed  = ref(false);

// Busca efetivamente carregada — NÃO comparar com props.search: no Inertia 3
// o prop do filho já foi atualizado quando o evento `success` dispara.
let loadedSearch = props.search ?? '';
// Descarta respostas fora de ordem (busca/paginação rápidas).
let requestSeq = 0;

async function fetchCards(page = 1, search = loadedSearch) {
    const seq = ++requestSeq;
    loadedSearch  = search ?? '';
    loading.value = true;
    failed.value  = false;
    try {
        const params = new URLSearchParams({ page, search: search ?? '' });
        const res    = await fetch(`${props.cardsUrl}?${params}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);

        const json    = await res.json();
        if (seq !== requestSeq) return;
        doctors.value = json.data ?? [];
        meta.value    = json.meta ?? { current_page: 1, last_page: 1, total: 0 };
    } catch {
        if (seq !== requestSeq) return;
        doctors.value = [];
        failed.value  = true;
    } finally {
        if (seq === requestSeq) loading.value = false;
    }
}

// Janela de páginas (atual ± 2, com primeira/última).
const pageWindow = computed(() => {
    const { current_page: current, last_page: last } = meta.value;
    const pages = new Set([1, last]);
    for (let p = current - 2; p <= current + 2; p++) {
        if (p >= 1 && p <= last) pages.add(p);
    }
    const sorted = [...pages].sort((a, b) => a - b);

    return sorted.flatMap((p, i) => (i > 0 && p - sorted[i - 1] > 1 ? ['…', p] : [p]));
});

function goTo(page) {
    if (page < 1 || page > meta.value.last_page || page === meta.value.current_page) return;
    fetchCards(page);
}

let removeSuccessListener;
onMounted(() => {
    fetchCards(1);
    removeSuccessListener = router.on?.('success', (event) => {
        const search = event?.detail?.page?.props?.filters?.search ?? '';
        // Busca nova volta para a página 1; mesma busca (editar/excluir) mantém a página.
        fetchCards(search === loadedSearch ? meta.value.current_page : 1, search);
    });
});
onUnmounted(() => removeSuccessListener?.());
</script>

<template>
    <div v-if="loading" class="text-center py-5">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">{{ t.loading ?? 'Carregando...' }}</span>
        </div>
    </div>

    <div v-else-if="failed" class="alert alert-danger d-flex align-items-center gap-2" role="alert">
        <i class="ti ti-alert-triangle" aria-hidden="true"></i>
        <span class="me-auto">{{ t.load_error ?? 'Não foi possível carregar os médicos.' }}</span>
        <button
            type="button"
            class="btn btn-sm btn-outline-danger"
            :title="t.retry ?? 'Tentar novamente'"
            :aria-label="t.retry ?? 'Tentar novamente'"
            @click="fetchCards(meta.current_page)"
        >
            <i class="ti ti-refresh" aria-hidden="true"></i>
        </button>
    </div>

    <template v-else>
        <div v-if="doctors.length === 0" class="text-center text-muted py-5">
            <i class="ti ti-stethoscope fs-1 mb-3 d-block" aria-hidden="true"></i>
            <p>{{ t.empty_list ?? 'Nenhum médico encontrado.' }}</p>
        </div>

        <div v-else class="row g-3">
            <div
                v-for="d in doctors"
                :key="d.id"
                class="col-12 col-sm-6 col-md-4 col-lg-4 col-xl-3"
            >
                <div class="card card-body h-100">
                    <div class="row align-items-center">
                        <div class="col-3 text-center">
                            <div class="position-relative d-inline-block">
                                <img
                                    :src="d.photo_url"
                                    :alt="d.full_name"
                                    class="img-fluid rounded-circle"
                                    style="width:56px;height:56px;object-fit:cover;"
                                >
                                <span
                                    v-if="d.color"
                                    class="position-absolute bottom-0 end-0 rounded-circle"
                                    :style="{ background: d.color, width: '14px', height: '14px', border: '2px solid var(--bs-body-bg)' }"
                                    aria-hidden="true"
                                ></span>
                            </div>
                        </div>
                        <div class="col-9">
                            <h6 class="mb-1 fw-semibold lh-sm text-break">{{ d.full_name }}</h6>
                            <span
                                :class="d.active
                                    ? 'badge badge-soft-success rounded text-success border border-success fs-12'
                                    : 'badge badge-soft-danger rounded text-danger border border-danger fs-12'"
                            >{{ d.active ? (t.status_active ?? 'Ativo') : (t.status_inactive ?? 'Inativo') }}</span>
                        </div>
                    </div>

                    <dl class="small text-muted mt-2 mb-1">
                        <div class="d-flex gap-1"><dt class="fw-semibold">{{ t.col_code ?? 'Código' }}:</dt><dd class="mb-0">{{ d.code }}</dd></div>
                        <div class="d-flex gap-1"><dt class="fw-semibold">{{ t.col_record ?? 'CRM' }}:</dt><dd class="mb-0">{{ d.record ?? '—' }}</dd></div>
                        <div class="d-flex gap-1"><dt class="fw-semibold">{{ t.specialty ?? 'Especialidade' }}:</dt><dd class="mb-0">{{ d.record_specialty ?? '—' }}</dd></div>
                        <div class="d-flex gap-1">
                            <dt class="fw-semibold">{{ t.col_phone ?? 'Telefone' }}:</dt>
                            <dd class="mb-0">
                                <i
                                    v-if="d.whatsapp"
                                    class="fab fa-whatsapp text-success me-1"
                                role="img"
                                    :title="t.whatsapp ?? 'WhatsApp'"
                                    :aria-label="t.whatsapp ?? 'WhatsApp'"
                                ></i>{{ d.cellphone ?? '—' }}
                            </dd>
                        </div>
                    </dl>

                    <hr class="my-2 mt-auto">

                    <ActionIconGroup align="end" gap="tight">
                        <template v-if="d.mode === 'view_only' || d.mode === 'full'">
                            <ActionIconButton
                                icon="ti ti-eye"
                                :title="t.action_view ?? 'Visualizar'"
                                @click="emit('view', d.id)"
                            />
                        </template>
                        <template v-if="d.mode === 'full'">
                            <ActionIconButton
                                icon="ti ti-calendar-time"
                                :title="t.action_work_schedule ?? 'Horários de atendimento'"
                                variant="info"
                                :href="d.work_schedule_url"
                            />
                            <ActionDropdown
                                :title="t.more_actions ?? 'Mais ações'"
                                btn-class="ee-action-icon ee-action-icon--default"
                                icon="ti ti-dots-vertical"
                            >
                                <li>
                                    <button class="dropdown-item rounded-1" @click="emit('edit', d.id)">
                                        <i class="ti ti-edit me-1"></i> {{ t.action_edit ?? 'Editar' }}
                                    </button>
                                </li>
                                <li>
                                    <button class="dropdown-item rounded-1" @click="emit('toggleActive', d.id, d.active)">
                                        <i :class="`ti me-1 ${d.active ? 'ti-lock-open' : 'ti-lock'}`"></i>
                                        {{ d.active ? (t.action_deactivate ?? 'Desativar') : (t.action_activate ?? 'Ativar') }}
                                    </button>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <button class="dropdown-item rounded-1 text-danger" @click="emit('delete', d.id)">
                                        <i class="ti ti-trash me-1"></i> {{ t.action_delete ?? 'Excluir' }}
                                    </button>
                                </li>
                            </ActionDropdown>
                        </template>
                    </ActionIconGroup>
                </div>
            </div>
        </div>

        <nav v-if="meta.last_page > 1" class="d-flex justify-content-center mt-3" :aria-label="t.pagination_label ?? 'Paginação'">
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item" :class="{ disabled: meta.current_page === 1 }">
                    <button type="button" class="page-link" :aria-label="t.pagination_previous ?? 'Anterior'" @click="goTo(meta.current_page - 1)">
                        <i class="ti ti-arrow-left text-body" aria-hidden="true"></i>
                    </button>
                </li>
                <li
                    v-for="(p, i) in pageWindow"
                    :key="`${p}-${i}`"
                    class="page-item"
                    :class="{ active: p === meta.current_page, disabled: p === '…' }"
                >
                    <span v-if="p === '…'" class="page-link">…</span>
                    <button
                        v-else
                        type="button"
                        class="page-link"
                        :aria-current="p === meta.current_page ? 'page' : undefined"
                        @click="goTo(p)"
                    >{{ p }}</button>
                </li>
                <li class="page-item" :class="{ disabled: meta.current_page === meta.last_page }">
                    <button type="button" class="page-link" :aria-label="t.pagination_next ?? 'Próxima'" @click="goTo(meta.current_page + 1)">
                        <i class="ti ti-arrow-right text-body" aria-hidden="true"></i>
                    </button>
                </li>
            </ul>
        </nav>
    </template>
</template>
