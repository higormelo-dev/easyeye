<script setup>
import { computed } from 'vue';
import SortableTh from '@/Components/Panel/SortableTh.vue';
import StatusBadge from '@/Components/Panel/StatusBadge.vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';
import ActionDropdown from '@/Components/Panel/ActionDropdown.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup from '@/Components/Panel/ActionIconGroup.vue';

/**
 * Catálogo de medicamentos em tabela — mesmo layout de Manager → Planos
 * (colunas ordenáveis, ver detalhes + menu de ações). Item da CMED só
 * edita a posologia: ativar/desativar e excluir vêm da importação.
 */
const props = defineProps({
    medicines: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    t: { type: Object, default: () => ({}) },
});

defineEmits(['sort', 'view', 'edit', 'delete', 'toggleActive']);

const currentSort = computed(() => props.filters.sort ?? 'name');
const currentDir = computed(() => props.filters.direction ?? 'asc');

function posology(m) {
    return [m.dosage, m.frequency, m.duration].filter(Boolean).join(' · ');
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
                        {{ t.col_medicine }}
                    </SortableTh>
                    <th class="d-none d-lg-table-cell">{{ t.col_presentation }}</th>
                    <SortableTh
                        col-key="laboratory"
                        :current-sort="currentSort"
                        :current-dir="currentDir"
                        class="d-none d-md-table-cell"
                        @sort="$emit('sort', $event)"
                    >
                        {{ t.col_laboratory }}
                    </SortableTh>
                    <SortableTh
                        col-key="source"
                        :current-sort="currentSort"
                        :current-dir="currentDir"
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
                <tr v-if="medicines.data.length === 0">
                    <td colspan="6" class="text-center text-muted py-5">
                        <i class="ti ti-pill fs-1 d-block mb-2"></i>
                        {{ t.empty }}
                    </td>
                </tr>

                <tr v-for="m in medicines.data" :key="m.id">
                    <td>
                        <div class="fw-medium" style="font-size: 0.875rem">
                            {{ m.name }}
                            <span v-if="m.concentration" class="fw-normal">{{ m.concentration }}</span>
                            <i v-if="m.is_ophthalmic" class="ti ti-eye text-info ms-1" :title="t.ophthalmic"></i>
                            <span v-if="m.is_ophthalmic" class="visually-hidden">{{ t.ophthalmic }}</span>
                        </div>
                        <div
                            v-if="m.active_ingredient"
                            class="text-muted text-truncate"
                            style="font-size: 0.75rem; max-width: 280px"
                            :title="m.active_ingredient"
                        >
                            {{ m.active_ingredient }}
                        </div>
                        <div
                            v-if="posology(m)"
                            class="text-primary text-truncate"
                            style="font-size: 0.75rem; max-width: 280px"
                        >
                            <i class="ti ti-clipboard-text me-1"></i>{{ posology(m) }}
                        </div>
                    </td>
                    <td class="d-none d-lg-table-cell">
                        <div style="font-size: 0.875rem">{{ m.form ?? '—' }}</div>
                        <div
                            v-if="m.presentation_detail"
                            class="text-muted text-truncate"
                            style="font-size: 0.75rem; max-width: 300px"
                            :title="m.presentation_detail"
                        >
                            {{ m.presentation_detail }}
                        </div>
                    </td>
                    <td class="d-none d-md-table-cell">
                        <div class="text-truncate" style="font-size: 0.875rem; max-width: 240px" :title="m.laboratory">
                            {{ m.laboratory ?? '—' }}
                        </div>
                        <div v-if="m.category" class="text-muted" style="font-size: 0.75rem">{{ m.category }}</div>
                    </td>
                    <td>
                        <span
                            class="badge rounded fs-12"
                            :class="m.source === 'cmed' ? 'badge-soft-secondary' : 'badge-soft-primary'"
                            >{{ m.source_label }}</span
                        >
                    </td>
                    <td class="text-center">
                        <!-- Aviso na mesma linha do status (antes: frase solta embaixo). -->
                        <div class="d-inline-flex flex-wrap align-items-center justify-content-center gap-1">
                            <StatusBadge
                                :active="m.active"
                                :label-active="t.status_active"
                                :label-inactive="t.status_inactive"
                            />
                            <span
                                v-if="m.source === 'cmed' && m.active && !m.is_marketed"
                                class="badge badge-soft-warning border border-warning rounded fs-13 fw-medium"
                                :title="t.not_marketed_hint"
                                ><i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>{{ t.not_marketed }}</span
                            >
                        </div>
                    </td>
                    <td class="text-end">
                        <ActionIconGroup align="end" gap="tight">
                            <ActionIconButton icon="ti ti-eye" :title="t.action_view" @click="$emit('view', m)" />
                            <ActionDropdown
                                btn-class="ee-action-icon ee-action-icon--default"
                                icon="ti ti-dots-vertical"
                                :title="t.more_actions"
                            >
                                <li>
                                    <button class="dropdown-item rounded-1" @click="$emit('edit', m)">
                                        <i class="ti ti-edit me-1"></i>
                                        {{ m.source === 'cmed' ? t.edit_posology : t.edit }}
                                    </button>
                                </li>
                                <template v-if="m.source === 'manual'">
                                    <li>
                                        <button class="dropdown-item rounded-1" @click="$emit('toggleActive', m)">
                                            <i :class="`ti me-1 ${m.active ? 'ti-lock-open' : 'ti-lock'}`"></i>
                                            {{ m.active ? t.deactivate : t.activate }}
                                        </button>
                                    </li>
                                    <li><hr class="dropdown-divider" /></li>
                                    <li>
                                        <button class="dropdown-item rounded-1 text-danger" @click="$emit('delete', m)">
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
        :data="medicines"
        :showing-from="t.showing_from"
        :showing-of="t.showing_of"
        :showing-suffix="t.showing_suffix"
        :previous-label="t.previous"
        :next-label="t.next"
    />
</template>
