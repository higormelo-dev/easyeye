<script setup>
import { computed } from 'vue';
import SortableTh from '@/Components/Panel/SortableTh.vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';
import ActionDropdown from '@/Components/Panel/ActionDropdown.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup from '@/Components/Panel/ActionIconGroup.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import { usageHint, usageLocked } from './cid10Presenter.js';

/**
 * Catálogo CID-10 em tabela — mesmo layout de Manager → Medicamentos
 * (colunas ordenáveis no servidor, ver detalhes + menu de ações). Descrição
 * editada mostra a oficial embaixo; código em uso não tem "excluir".
 */
const props = defineProps({
    codes: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    t: { type: Object, default: () => ({}) },
});

defineEmits(['sort', 'view', 'edit', 'delete']);

const { number } = useLocaleFormat();

const currentSort = computed(() => props.filters.sort ?? 'code');
const currentDir = computed(() => props.filters.direction ?? 'asc');
</script>

<template>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <SortableTh
                        col-key="code"
                        :current-sort="currentSort"
                        :current-dir="currentDir"
                        style="width: 110px"
                        @sort="$emit('sort', $event)"
                    >
                        {{ t.col_code }}
                    </SortableTh>
                    <SortableTh
                        col-key="description"
                        :current-sort="currentSort"
                        :current-dir="currentDir"
                        @sort="$emit('sort', $event)"
                    >
                        {{ t.col_description }}
                    </SortableTh>
                    <SortableTh
                        col-key="chapter"
                        :current-sort="currentSort"
                        :current-dir="currentDir"
                        class="d-none d-lg-table-cell"
                        @sort="$emit('sort', $event)"
                    >
                        {{ t.col_chapter }}
                    </SortableTh>
                    <SortableTh
                        col-key="category"
                        :current-sort="currentSort"
                        :current-dir="currentDir"
                        class="d-none d-md-table-cell"
                        @sort="$emit('sort', $event)"
                    >
                        {{ t.col_category }}
                    </SortableTh>
                    <SortableTh
                        col-key="usage"
                        :current-sort="currentSort"
                        :current-dir="currentDir"
                        class="text-end"
                        @sort="$emit('sort', $event)"
                    >
                        {{ t.col_usage }}
                    </SortableTh>
                    <th class="text-end">{{ t.col_actions }}</th>
                </tr>
            </thead>
            <tbody>
                <!-- Empty state -->
                <tr v-if="codes.data.length === 0">
                    <td colspan="6" class="text-center text-muted py-5">
                        <i class="ti ti-stethoscope fs-1 d-block mb-2" aria-hidden="true"></i>
                        {{ t.empty }}
                    </td>
                </tr>

                <tr v-for="c in codes.data" :key="c.id" :data-code="c.code">
                    <td class="fw-semibold text-nowrap">{{ c.code }}</td>
                    <td>
                        <div style="font-size: 0.875rem">
                            {{ c.description }}
                            <span
                                v-if="c.is_custom"
                                class="badge rounded fs-11 badge-soft-purple ms-1"
                                :title="t.custom_hint"
                                data-test="badge-custom"
                                >{{ t.badge_custom }}</span
                            >
                            <span
                                v-if="c.is_edited"
                                class="badge rounded fs-11 badge-soft-warning ms-1"
                                :title="(t.edited_hint ?? '').replace(':official', c.official_description ?? '')"
                                data-test="badge-edited"
                                >{{ t.badge_edited }}</span
                            >
                        </div>
                        <!-- Oficial ao lado do texto editado (o que a TISS/SUS reconhece). -->
                        <div
                            v-if="c.is_edited && c.official_description"
                            class="text-muted text-truncate"
                            style="font-size: 0.75rem; max-width: 520px"
                            :title="c.official_description"
                            data-test="official-text"
                        >
                            {{ t.official_label }} {{ c.official_description }}
                        </div>
                    </td>
                    <td class="d-none d-lg-table-cell">
                        <span
                            v-if="c.chapter"
                            class="badge rounded fs-12 badge-soft-secondary"
                            :title="c.chapter_name"
                            >{{ c.chapter }}</span
                        >
                        <div
                            v-if="c.group_name"
                            class="text-muted text-truncate"
                            style="font-size: 0.75rem; max-width: 260px"
                            :title="c.group_name"
                        >
                            {{ c.group_name }}
                        </div>
                    </td>
                    <td class="d-none d-md-table-cell">
                        <div class="text-truncate" style="font-size: 0.875rem; max-width: 220px" :title="c.category">
                            {{ c.category ?? '—' }}
                        </div>
                    </td>
                    <td class="text-end text-nowrap">
                        <span
                            v-if="c.usage.total > 0"
                            class="fw-semibold"
                            :title="usageHint(c, t, number)"
                            data-test="usage"
                            >{{ number(c.usage.total) }}</span
                        >
                        <span v-else class="text-muted small">{{ t.usage_none }}</span>
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
                                    <button class="dropdown-item rounded-1" data-test="edit" @click="$emit('edit', c)">
                                        <i class="ti ti-edit me-1" aria-hidden="true"></i> {{ t.edit }}
                                    </button>
                                </li>
                                <li><hr class="dropdown-divider" /></li>
                                <li>
                                    <button
                                        class="dropdown-item rounded-1 text-danger"
                                        :disabled="usageLocked(c)"
                                        :title="
                                            usageLocked(c)
                                                ? (t.delete_blocked ?? '').replace(
                                                      ':count',
                                                      number(c.usage.total + c.usage.links),
                                                  )
                                                : undefined
                                        "
                                        data-test="delete"
                                        @click="$emit('delete', c)"
                                    >
                                        <i class="ti ti-trash me-1" aria-hidden="true"></i> {{ t.delete }}
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
        :data="codes"
        :showing-from="t.showing_from"
        :showing-of="t.showing_of"
        :showing-suffix="t.showing_suffix"
        :previous-label="t.previous"
        :next-label="t.next"
    />
</template>
