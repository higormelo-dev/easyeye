<script setup>
import StatusBadge from '@/Components/Panel/StatusBadge.vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';
import ActionDropdown from '@/Components/Panel/ActionDropdown.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup from '@/Components/Panel/ActionIconGroup.vue';
import { cmedSituation } from './cmedSituation.js';

/**
 * Catálogo de medicamentos em cards — mesmo layout de Manager → Planos.
 * Usa a mesma página/filtros da tabela (paginação server-side do Inertia).
 */
defineProps({
    medicines: { type: Object, required: true },
    t: { type: Object, default: () => ({}) },
});

defineEmits(['view', 'edit', 'delete', 'toggleActive', 'approve']);
</script>

<template>
    <!-- Empty state -->
    <div v-if="medicines.data.length === 0" class="text-center text-muted py-5">
        <i class="ti ti-pill fs-1 mb-2 d-block"></i>
        <p>{{ t.empty }}</p>
    </div>

    <!-- Cards grid -->
    <div v-else class="row g-3">
        <div v-for="m in medicines.data" :key="m.id" class="col-sm-6 col-xl-4">
            <div class="card card-body h-100">
                <div class="d-flex align-items-start gap-3">
                    <div
                        class="avatar-sm rounded-circle bg-info-subtle d-flex align-items-center justify-content-center flex-shrink-0"
                        style="width: 44px; height: 44px"
                    >
                        <i :class="`ti ${m.is_ophthalmic ? 'ti-eye' : 'ti-pill'} text-info fs-18`"></i>
                    </div>
                    <div class="flex-grow-1 min-w-0">
                        <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                            <h6 class="mb-0 fw-semibold lh-sm">
                                {{ m.name }}
                                <span v-if="m.concentration" class="fw-normal">{{ m.concentration }}</span>
                            </h6>
                            <StatusBadge
                                :active="m.active"
                                :label-active="t.status_active"
                                :label-inactive="t.status_inactive"
                            />
                            <span
                                v-if="m.posology_pending_review"
                                class="badge badge-soft-warning rounded fs-12 fw-medium"
                                :title="t.posology_ai_badge_hint"
                                data-test="ai-review-badge"
                                ><i class="ti ti-sparkles me-1" aria-hidden="true"></i>{{ t.posology_ai_badge }}</span
                            >
                            <span
                                v-if="cmedSituation(m, t)"
                                class="badge rounded fs-12 fw-medium"
                                :class="cmedSituation(m, t).cls"
                                :title="cmedSituation(m, t).hint"
                                ><i :class="`ti ${cmedSituation(m, t).icon} me-1`" aria-hidden="true"></i
                                >{{ cmedSituation(m, t).label }}</span
                            >
                        </div>
                        <div class="text-muted small mb-2">
                            <div v-if="m.active_ingredient">{{ m.active_ingredient }}</div>
                            <div class="mt-1">
                                <span
                                    class="badge rounded fs-11"
                                    :class="m.source === 'cmed' ? 'badge-soft-secondary' : 'badge-soft-primary'"
                                    >{{ m.source_label }}</span
                                >
                                <span v-if="m.form" class="ms-1">{{ m.form }}</span>
                            </div>
                            <div v-if="m.laboratory" class="mt-1 text-truncate" style="font-size: 0.8rem">
                                {{ m.laboratory }}
                            </div>
                        </div>
                    </div>
                </div>

                <hr class="my-2 mt-auto" />

                <ActionIconGroup align="end" gap="tight">
                    <ActionIconButton
                        v-if="m.posology_pending_review"
                        icon="ti ti-check"
                        variant="success"
                        :title="t.posology_approve_hint"
                        data-test="card-approve"
                        @click="$emit('approve', m)"
                    />
                    <ActionIconButton icon="ti ti-eye" :title="t.action_view" @click="$emit('view', m)" />
                    <ActionIconButton
                        icon="ti ti-edit"
                        :title="m.source === 'cmed' ? t.edit_posology : t.edit"
                        @click="$emit('edit', m)"
                    />
                    <ActionDropdown
                        v-if="m.source === 'manual'"
                        btn-class="ee-action-icon ee-action-icon--default"
                        icon="ti ti-dots-vertical"
                        :title="t.more_actions"
                    >
                        <li>
                            <button class="dropdown-item rounded-1" @click="$emit('toggleActive', m)">
                                <i :class="`ti me-1 ${m.active ? 'ti-lock-open' : 'ti-lock'}`"></i>
                                {{ m.active ? t.deactivate : t.activate }}
                            </button>
                        </li>
                        <li>
                            <button class="dropdown-item rounded-1 text-danger" @click="$emit('delete', m)">
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
        :data="medicines"
        :showing-from="t.showing_from"
        :showing-of="t.showing_of"
        :showing-suffix="t.showing_suffix"
        :previous-label="t.previous"
        :next-label="t.next"
    />
</template>
