<script setup>
import { computed } from 'vue';
import SortableTh from '@/Components/Panel/SortableTh.vue';
import StatusBadge from '@/Components/Panel/StatusBadge.vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';
import ActionDropdown from '@/Components/Panel/ActionDropdown.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup from '@/Components/Panel/ActionIconGroup.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';

/**
 * Catálogo de convênios em tabela — mesmo layout de Manager → Medicamentos
 * (colunas ordenáveis, ver detalhes + menu de ações). PARTICULAR não é
 * desativado nem excluído; operadora da ANS só é desativada (exclusão é
 * só de convênio manual sem uso — o servidor confere).
 */
const props = defineProps({
    covenants: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    t: { type: Object, default: () => ({}) },
});

defineEmits(['sort', 'view', 'edit', 'delete', 'toggleActive']);

const { number } = useLocaleFormat();

const currentSort = computed(() => props.filters.sort ?? 'name');
const currentDir = computed(() => props.filters.direction ?? 'asc');

function subtitle(c) {
    return c.trade_name && c.trade_name.toUpperCase() !== c.name ? c.trade_name : c.company_name;
}
</script>

<template>
    <div class="table-responsive">
        <table class="table table-nowrap table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <SortableTh
                        col-key="name"
                        :current-sort="currentSort"
                        :current-dir="currentDir"
                        @sort="$emit('sort', $event)"
                    >
                        {{ t.col_covenant }}
                    </SortableTh>
                    <SortableTh
                        col-key="ans_registry"
                        :current-sort="currentSort"
                        :current-dir="currentDir"
                        class="d-none d-md-table-cell"
                        @sort="$emit('sort', $event)"
                    >
                        {{ t.col_registry }}
                    </SortableTh>
                    <SortableTh
                        col-key="ans_modality"
                        :current-sort="currentSort"
                        :current-dir="currentDir"
                        class="d-none d-lg-table-cell"
                        @sort="$emit('sort', $event)"
                    >
                        {{ t.col_modality }}
                    </SortableTh>
                    <th class="d-none d-lg-table-cell text-center">{{ t.col_plans }}</th>
                    <SortableTh
                        col-key="uf"
                        :current-sort="currentSort"
                        :current-dir="currentDir"
                        class="d-none d-xl-table-cell"
                        @sort="$emit('sort', $event)"
                    >
                        {{ t.col_location }}
                    </SortableTh>
                    <SortableTh
                        col-key="source"
                        :current-sort="currentSort"
                        :current-dir="currentDir"
                        class="d-none d-sm-table-cell"
                        @sort="$emit('sort', $event)"
                    >
                        {{ t.col_source }}
                    </SortableTh>
                    <SortableTh
                        col-key="active"
                        :current-sort="currentSort"
                        :current-dir="currentDir"
                        class="text-center"
                        @sort="$emit('sort', $event)"
                    >
                        {{ t.col_status }}
                    </SortableTh>
                    <th class="text-end">{{ t.col_actions }}</th>
                </tr>
            </thead>
            <tbody>
                <!-- Empty state -->
                <tr v-if="covenants.data.length === 0">
                    <td colspan="8" class="text-center text-muted py-5">
                        <i class="ti ti-building-hospital fs-1 d-block mb-2"></i>
                        {{ t.empty }}
                    </td>
                </tr>

                <tr v-for="c in covenants.data" :key="c.id">
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <span
                                class="rounded-circle flex-shrink-0 border"
                                :style="{ width: '12px', height: '12px', background: c.color || 'transparent' }"
                                aria-hidden="true"
                            ></span>
                            <div class="min-w-0">
                                <div
                                    class="fw-medium text-truncate"
                                    style="font-size: 0.875rem; max-width: 320px"
                                    :title="c.name"
                                >
                                    {{ c.name }}
                                    <span v-if="c.is_particular" class="badge badge-soft-info rounded fs-11 ms-1">{{
                                        t.particular_badge
                                    }}</span>
                                </div>
                                <div
                                    v-if="subtitle(c)"
                                    class="text-muted text-truncate"
                                    style="font-size: 0.75rem; max-width: 320px"
                                    :title="subtitle(c)"
                                >
                                    {{ subtitle(c) }}
                                </div>
                            </div>
                        </div>
                    </td>
                    <td class="d-none d-md-table-cell font-monospace" style="font-size: 0.85rem">
                        {{ c.ans_registry ?? '—' }}
                    </td>
                    <td class="d-none d-lg-table-cell" style="font-size: 0.875rem">{{ c.ans_modality ?? '—' }}</td>
                    <td class="d-none d-lg-table-cell text-center" style="font-size: 0.875rem">
                        {{ c.is_particular ? '—' : number(c.plans_count ?? 0) }}
                    </td>
                    <td class="d-none d-xl-table-cell" style="font-size: 0.875rem">
                        <template v-if="c.city || c.uf">{{ [c.city, c.uf].filter(Boolean).join(' / ') }}</template>
                        <template v-else>—</template>
                    </td>
                    <td class="d-none d-sm-table-cell">
                        <span
                            class="badge rounded fs-12"
                            :class="c.source === 'ans' ? 'badge-soft-secondary' : 'badge-soft-primary'"
                            >{{ c.source_label }}</span
                        >
                    </td>
                    <td class="text-center">
                        <div class="d-inline-flex flex-wrap align-items-center justify-content-center gap-1">
                            <StatusBadge
                                :active="c.active"
                                :label-active="t.status_active"
                                :label-inactive="t.status_inactive"
                            />
                            <span
                                v-if="c.ans_status === 'cancelled'"
                                class="badge badge-soft-warning border border-warning rounded fs-13 fw-medium"
                                :title="t.cancelled_hint"
                                ><i class="ti ti-alert-triangle me-1" aria-hidden="true"></i
                                >{{ t.cancelled_badge }}</span
                            >
                        </div>
                    </td>
                    <td class="text-end">
                        <ActionIconGroup align="end" gap="tight">
                            <ActionIconButton icon="ti ti-eye" :title="t.action_view" @click="$emit('view', c)" />
                            <ActionDropdown
                                btn-class="ee-action-icon ee-action-icon--default"
                                icon="ti ti-dots-vertical"
                                :title="t.more_actions"
                            >
                                <li>
                                    <button class="dropdown-item rounded-1" @click="$emit('edit', c)">
                                        <i class="ti ti-edit me-1"></i> {{ t.edit }}
                                    </button>
                                </li>
                                <li v-if="!c.is_particular">
                                    <button class="dropdown-item rounded-1" @click="$emit('toggleActive', c)">
                                        <i :class="`ti me-1 ${c.active ? 'ti-lock-open' : 'ti-lock'}`"></i>
                                        {{ c.active ? t.deactivate : t.activate }}
                                    </button>
                                </li>
                                <template v-if="c.source === 'manual' && !c.is_particular">
                                    <li><hr class="dropdown-divider" /></li>
                                    <li>
                                        <button class="dropdown-item rounded-1 text-danger" @click="$emit('delete', c)">
                                            <i class="ti ti-trash me-1"></i> {{ t.delete }}
                                        </button>
                                    </li>
                                </template>
                            </ActionDropdown>
                        </ActionIconGroup>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <TablePagination
        :data="covenants"
        :showing-from="t.showing_from"
        :showing-of="t.showing_of"
        :showing-suffix="t.showing_suffix"
        :previous-label="t.previous"
        :next-label="t.next"
    />
</template>
