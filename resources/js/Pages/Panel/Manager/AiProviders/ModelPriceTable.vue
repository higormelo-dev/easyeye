<script setup>
import { computed } from 'vue';
import SortableTh from '@/Components/Panel/SortableTh.vue';
import StatusBadge from '@/Components/Panel/StatusBadge.vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';
import ActionDropdown from '@/Components/Panel/ActionDropdown.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup from '@/Components/Panel/ActionIconGroup.vue';
import { usePriceFormat } from './usePriceFormat';

/**
 * Catálogo de modelos e preços em tabela — mesmo layout das listagens do
 * manager (colunas ordenáveis no servidor, ver detalhes + menu de ações).
 * Ativos aparecem no seletor de modelos; travado = a sincronização não muda.
 */
const props = defineProps({
    prices: { type: Object, required: true }, // paginator Laravel
    filters: { type: Object, default: () => ({}) },
    t: { type: Object, default: () => ({}) },
});

defineEmits(['sort', 'view', 'edit', 'toggleActive', 'toggleLock']);

const { usd } = usePriceFormat();

// Sem ordenação escolhida, o servidor põe os modelos em uso primeiro.
const currentSort = computed(() => props.filters.sort ?? '');
const currentDir = computed(() => props.filters.direction ?? 'asc');

const SOURCES = {
    seed: ['source_seed', 'Padrão'],
    manual: ['source_manual', 'Manual'],
    sync: ['source_sync', 'Sincronizado'],
};
const sourceLabel = (row) => {
    const [key, fallback] = SOURCES[row.source] ?? SOURCES.seed;
    return props.t[key] ?? fallback;
};
</script>

<template>
    <div class="table-responsive">
        <table class="table table-nowrap table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <SortableTh
                        col-key="provider"
                        :current-sort="currentSort"
                        :current-dir="currentDir"
                        @sort="$emit('sort', $event)"
                    >
                        {{ t.provider ?? 'Provedor' }}
                    </SortableTh>
                    <SortableTh
                        col-key="model"
                        :current-sort="currentSort"
                        :current-dir="currentDir"
                        @sort="$emit('sort', $event)"
                    >
                        {{ t.model ?? 'Modelo' }}
                    </SortableTh>
                    <SortableTh
                        col-key="input"
                        :current-sort="currentSort"
                        :current-dir="currentDir"
                        class="text-end"
                        :title="t.section_prices"
                        @sort="$emit('sort', $event)"
                    >
                        {{ t.col_input ?? 'Entrada' }}
                    </SortableTh>
                    <SortableTh
                        col-key="output"
                        :current-sort="currentSort"
                        :current-dir="currentDir"
                        class="text-end d-none d-sm-table-cell"
                        :title="t.section_prices"
                        @sort="$emit('sort', $event)"
                    >
                        {{ t.col_output ?? 'Saída' }}
                    </SortableTh>
                    <SortableTh
                        col-key="source"
                        :current-sort="currentSort"
                        :current-dir="currentDir"
                        class="d-none d-md-table-cell"
                        @sort="$emit('sort', $event)"
                    >
                        {{ t.col_source ?? 'Origem' }}
                    </SortableTh>
                    <SortableTh
                        col-key="active"
                        :current-sort="currentSort"
                        :current-dir="currentDir"
                        class="text-center"
                        @sort="$emit('sort', $event)"
                    >
                        {{ t.col_status ?? 'Status' }}
                    </SortableTh>
                    <th class="text-end">{{ t.col_actions ?? 'Ações' }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="prices.data.length === 0">
                    <td colspan="7" class="text-center text-muted py-5">
                        <i class="ti ti-tags fs-1 d-block mb-2" aria-hidden="true"></i>
                        {{ t.empty_filtered ?? 'Nenhum modelo com esses filtros.' }}
                    </td>
                </tr>

                <tr v-for="row in prices.data" :key="row.id" :data-price-row="`${row.provider}|${row.model}`">
                    <td class="small">{{ row.provider_label }}</td>
                    <td>
                        <code class="small text-break">{{ row.model }}</code>
                        <span
                            v-if="row.in_use"
                            class="badge badge-soft-primary rounded fs-11 ms-1"
                            :title="t.in_use_hint"
                            data-badge-in-use
                            >{{ t.in_use ?? 'Em uso' }}</span
                        >
                        <span
                            v-if="row.unlisted_at"
                            class="badge badge-soft-warning rounded text-warning border border-warning fs-11 ms-1"
                            :title="(t.model_unlisted ?? '').replace(':date', row.unlisted_at)"
                            data-badge-unlisted
                            ><i class="ti ti-alert-triangle me-1" aria-hidden="true"></i
                            >{{ t.unlisted ?? 'Não listado' }}</span
                        >
                    </td>
                    <td class="text-end small">{{ usd(row.input_usd_per_million) }}</td>
                    <td class="text-end small d-none d-sm-table-cell">{{ usd(row.output_usd_per_million) }}</td>
                    <td class="small d-none d-md-table-cell">
                        <span :title="row.synced_at ? (t.synced_hint ?? '').replace(':date', row.synced_at) : ''">{{
                            sourceLabel(row)
                        }}</span>
                        <i
                            v-if="row.price_locked"
                            class="ti ti-lock ms-1 text-secondary"
                            :title="t.locked_hint"
                            role="img"
                            :aria-label="t.locked ?? 'Travado'"
                            data-locked
                        ></i>
                    </td>
                    <td class="text-center">
                        <StatusBadge
                            :active="row.active"
                            :label-active="t.status_active"
                            :label-inactive="t.status_inactive"
                        />
                    </td>
                    <td class="text-end">
                        <ActionIconGroup align="end" gap="tight">
                            <ActionIconButton
                                icon="ti ti-eye"
                                :title="`${t.action_view ?? 'Ver detalhes'} — ${row.model}`"
                                data-price-view
                                @click="$emit('view', row)"
                            />
                            <ActionDropdown
                                btn-class="ee-action-icon ee-action-icon--default"
                                icon="ti ti-dots-vertical"
                                :title="t.more_actions"
                                :min-width="240"
                            >
                                <li>
                                    <button class="dropdown-item rounded-1" data-price-edit @click="$emit('edit', row)">
                                        <i class="ti ti-edit me-1"></i> {{ t.action_edit_price ?? 'Editar preço' }}
                                    </button>
                                </li>
                                <li>
                                    <button
                                        class="dropdown-item rounded-1"
                                        data-price-lock
                                        @click="$emit('toggleLock', row)"
                                    >
                                        <i :class="`ti me-1 ${row.price_locked ? 'ti-lock-open' : 'ti-lock'}`"></i>
                                        {{ row.price_locked ? t.action_unlock : t.action_lock }}
                                    </button>
                                </li>
                                <li><hr class="dropdown-divider" /></li>
                                <li>
                                    <button
                                        class="dropdown-item rounded-1"
                                        :class="{ 'text-danger': row.active }"
                                        data-price-toggle
                                        @click="$emit('toggleActive', row)"
                                    >
                                        <i :class="`ti me-1 ${row.active ? 'ti-player-pause' : 'ti-player-play'}`"></i>
                                        {{ row.active ? (t.deactivate ?? 'Desativar') : (t.activate ?? 'Ativar') }}
                                    </button>
                                </li>
                            </ActionDropdown>
                        </ActionIconGroup>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <TablePagination
        :data="prices"
        :showing-from="t.showing_from"
        :showing-of="t.showing_of"
        :showing-suffix="t.showing_suffix"
        :aria-label="t.pagination_label"
        :previous-label="t.previous"
        :next-label="t.next"
    />
</template>
