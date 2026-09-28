<script setup>
import { computed } from 'vue';
import TablePagination            from '@/Components/Panel/TablePagination.vue';
import ReportSettingActions       from './ReportSettingActions.vue';
import { useReportSettingFormat } from './useReportSettingFormat.js';

/**
 * Cards de modelos de documento no padrão de Patients/PatientCards: título,
 * status/origem, dados principais em linhas rotuladas e as mesmas ações da
 * tabela. Usa o MESMO paginator da tabela (prop `items`), sem endpoint extra.
 */
const props = defineProps({
    items:     { type: Object, required: true },   // paginator Laravel
    t:         { type: Object, default: () => ({}) },
    emptyText: { type: String, default: '' },
});

const emit = defineEmits(['reimport', 'delete']);

const { date, blocks } = useReportSettingFormat(() => props.t);

const rows = computed(() => props.items?.data ?? []);

/** Só os blocos incluídos, por extenso ("Cabeçalho, Assinatura"). */
function includedBlocks(item) {
    return blocks(item).filter((block) => block.on).map((block) => block.label).join(', ') || '—';
}
</script>

<template>
    <div v-if="rows.length === 0" class="text-center text-muted py-5">
        <i class="ti ti-file-text fs-1 mb-3 d-block" aria-hidden="true"></i>
        <p>{{ emptyText }}</p>
    </div>

    <div v-else class="row g-3">
        <div v-for="item in rows" :key="item.id" class="col-12 col-sm-6 col-md-4 col-xl-3">
            <div class="card card-body h-100">
                <div class="d-flex align-items-start gap-3">
                    <span class="rs-icon flex-shrink-0" aria-hidden="true"><i class="ti ti-file-text"></i></span>
                    <div class="min-w-0">
                        <h6 class="mb-1 fw-semibold lh-sm text-break">{{ item.title }}</h6>
                        <div class="d-flex flex-wrap gap-1">
                            <span
                                :class="item.active
                                    ? 'badge badge-soft-success rounded text-success border border-success fs-12'
                                    : 'badge badge-soft-danger rounded text-danger border border-danger fs-12'"
                            >{{ item.active ? (t.status_active ?? 'Ativo') : (t.status_inactive ?? 'Inativo') }}</span>
                            <span v-if="item.is_adopted" class="badge badge-soft-info rounded fs-11">{{ t.origin_adopted ?? 'Adotado' }}</span>
                            <span v-if="item.has_update" class="badge badge-soft-warning rounded fs-11">{{ t.update_available ?? 'Atualização disponível' }}</span>
                        </div>
                    </div>
                </div>

                <p
                    class="small mt-2 mb-0 rs-description"
                    :class="item.description ? 'text-muted' : 'text-body-secondary fst-italic'"
                >{{ item.description || (t.no_description ?? 'Sem descrição') }}</p>

                <!-- Linhas rotuladas no estilo de PatientCards ("Rótulo: valor"). -->
                <dl class="small text-muted mt-2 mb-1">
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_category ?? 'Categoria' }}:</dt>
                        <dd class="mb-0 text-break">{{ item.category || '—' }}</dd>
                    </div>
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_paper ?? 'Papel' }}:</dt>
                        <dd class="mb-0">{{ item.paper_size }}</dd>
                    </div>
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_blocks ?? 'Blocos' }}:</dt>
                        <dd class="mb-0">{{ includedBlocks(item) }}</dd>
                    </div>
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_updated_at ?? 'Atualizado em' }}:</dt>
                        <dd class="mb-0">{{ date(item.updated_at) }}</dd>
                    </div>
                </dl>

                <hr class="my-2 mt-auto">

                <ReportSettingActions
                    :item="item"
                    :t="t"
                    @reimport="emit('reimport', $event)"
                    @delete="emit('delete', $event)"
                />
            </div>
        </div>
    </div>

    <TablePagination
        :data="items"
        :showing-from="t.pagination_showing"
        :showing-of="t.pagination_of"
        :showing-suffix="t.pagination_suffix"
        :aria-label="t.pagination_label"
        :previous-label="t.pagination_previous"
        :next-label="t.pagination_next"
    />
</template>

<style scoped>
.min-w-0 {
    min-width: 0;
}
.rs-icon {
    width: 40px;
    height: 40px;
    border-radius: var(--bs-border-radius);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.15rem;
    color: var(--bs-primary);
    background: var(--bs-primary-bg-subtle);
}
/* Descrição longa: até 2 linhas no card, sem esticar a grade. */
.rs-description {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
</style>
