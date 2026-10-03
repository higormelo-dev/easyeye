<script setup>
import StatusBadge from '@/Components/Panel/StatusBadge.vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';
import ActionDropdown from '@/Components/Panel/ActionDropdown.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup from '@/Components/Panel/ActionIconGroup.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';

/**
 * Catálogo de convênios em cards — mesmo layout de Manager → Medicamentos.
 * Usa a mesma página/filtros da tabela (paginação server-side do Inertia).
 */
defineProps({
    covenants: { type: Object, required: true },
    t: { type: Object, default: () => ({}) },
});

defineEmits(['view', 'edit', 'delete', 'toggleActive']);

const { number } = useLocaleFormat();
</script>

<template>
    <!-- Empty state -->
    <div v-if="covenants.data.length === 0" class="text-center text-muted py-5">
        <i class="ti ti-building-hospital fs-1 mb-2 d-block"></i>
        <p>{{ t.empty }}</p>
    </div>

    <!-- Cards grid -->
    <div v-else class="row g-3">
        <div v-for="c in covenants.data" :key="c.id" class="col-sm-6 col-xl-4">
            <div class="card card-body h-100">
                <div class="d-flex align-items-start gap-3">
                    <div
                        class="avatar-sm rounded-circle bg-info-subtle d-flex align-items-center justify-content-center flex-shrink-0"
                        style="width: 44px; height: 44px"
                        aria-hidden="true"
                    >
                        <i class="ti ti-building-hospital text-info fs-18"></i>
                    </div>
                    <div class="flex-grow-1 min-w-0">
                        <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                            <span
                                class="rounded-circle flex-shrink-0 border"
                                :style="{ width: '12px', height: '12px', background: c.color || 'transparent' }"
                                aria-hidden="true"
                            ></span>
                            <h6 class="mb-0 fw-semibold lh-sm text-break">{{ c.name }}</h6>
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
                        <div class="text-muted small mb-2">
                            <div v-if="c.company_name" class="text-truncate" :title="c.company_name">
                                {{ c.company_name }}
                            </div>
                            <div class="mt-1">
                                <span
                                    class="badge rounded fs-11"
                                    :class="c.source === 'ans' ? 'badge-soft-secondary' : 'badge-soft-primary'"
                                    >{{ c.source_label }}</span
                                >
                                <span v-if="c.is_particular" class="badge badge-soft-info rounded fs-11 ms-1">{{
                                    t.particular_badge
                                }}</span>
                                <span v-if="c.ans_registry" class="ms-1 font-monospace">{{ c.ans_registry }}</span>
                            </div>
                            <div v-if="!c.is_particular" class="mt-1" style="font-size: 0.8rem">
                                <i class="ti ti-list-details me-1" aria-hidden="true"></i
                                >{{ (t.plans_count_short ?? '').replace(':count', number(c.plans_count ?? 0)) }}
                            </div>
                            <div v-if="c.ans_modality || c.uf" class="mt-1" style="font-size: 0.8rem">
                                {{
                                    [c.ans_modality, [c.city, c.uf].filter(Boolean).join(' / ')]
                                        .filter(Boolean)
                                        .join(' · ')
                                }}
                            </div>
                        </div>
                    </div>
                </div>

                <hr class="my-2 mt-auto" />

                <ActionIconGroup align="end" gap="tight">
                    <ActionIconButton icon="ti ti-eye" :title="t.action_view" @click="$emit('view', c)" />
                    <ActionIconButton icon="ti ti-edit" :title="t.edit" @click="$emit('edit', c)" />
                    <ActionDropdown
                        v-if="!c.is_particular"
                        btn-class="ee-action-icon ee-action-icon--default"
                        icon="ti ti-dots-vertical"
                        :title="t.more_actions"
                    >
                        <li>
                            <button class="dropdown-item rounded-1" @click="$emit('toggleActive', c)">
                                <i :class="`ti me-1 ${c.active ? 'ti-lock-open' : 'ti-lock'}`"></i>
                                {{ c.active ? t.deactivate : t.activate }}
                            </button>
                        </li>
                        <li v-if="c.source === 'manual'">
                            <button class="dropdown-item rounded-1 text-danger" @click="$emit('delete', c)">
                                <i class="ti ti-trash me-1"></i> {{ t.delete }}
                            </button>
                        </li>
                    </ActionDropdown>
                </ActionIconGroup>
            </div>
        </div>
    </div>

    <!-- Pagination -->
    <TablePagination
        class="mt-3"
        :data="covenants"
        :showing-from="t.showing_from"
        :showing-of="t.showing_of"
        :showing-suffix="t.showing_suffix"
        :previous-label="t.previous"
        :next-label="t.next"
    />
</template>
