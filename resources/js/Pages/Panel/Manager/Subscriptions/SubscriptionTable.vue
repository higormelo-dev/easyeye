<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import SortableTh from '@/Components/Panel/SortableTh.vue';
import BillingStateBadge from '@/Components/Panel/BillingStateBadge.vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';
import ActionDropdown from '@/Components/Panel/ActionDropdown.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup from '@/Components/Panel/ActionIconGroup.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useSubscriptionPresenter } from './useSubscriptionPresenter.js';

const props = defineProps({
    subscriptions: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    billingCycles: { type: Array, default: () => [] },
    canManagePlans: { type: Boolean, default: false },
    hasFilters: { type: Boolean, default: false },
    t: { type: Object, default: () => ({}) },
});

defineEmits(['sort', 'view', 'extend', 'change', 'newFor', 'cancel', 'block']);

const { date } = useLocaleFormat();
const { amountText, monthlyText, accessText, dunningText, extendDisabledReason } = useSubscriptionPresenter(
    () => props.t,
    () => props.billingCycles,
);

const currentSort = computed(() => props.filters.sort ?? 'created_at');
const currentDir = computed(() => props.filters.direction ?? 'desc');
</script>

<template>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <SortableTh
                        col-key="entity_name"
                        :current-sort="currentSort"
                        :current-dir="currentDir"
                        @sort="$emit('sort', $event)"
                        >{{ t.col_entity }}</SortableTh
                    >
                    <SortableTh
                        col-key="plan_name"
                        :current-sort="currentSort"
                        :current-dir="currentDir"
                        @sort="$emit('sort', $event)"
                        >{{ t.col_plan }}</SortableTh
                    >
                    <th>{{ t.col_modality }}</th>
                    <th class="text-end">{{ t.col_amount }}</th>
                    <SortableTh
                        col-key="ends_at"
                        :current-sort="currentSort"
                        :current-dir="currentDir"
                        @sort="$emit('sort', $event)"
                        >{{ t.col_period }}</SortableTh
                    >
                    <th class="text-center">{{ t.col_status }}</th>
                    <th class="text-end">{{ t.col_actions }}</th>
                </tr>
            </thead>
            <tbody>
                <!-- Empty state -->
                <tr v-if="subscriptions.data.length === 0">
                    <td colspan="7" class="text-center text-muted py-5">
                        <i class="ti ti-file-invoice fs-1 d-block mb-2 opacity-25" aria-hidden="true"></i>
                        {{ hasFilters ? t.empty_filtered : t.empty_list }}
                    </td>
                </tr>

                <tr
                    v-for="s in subscriptions.data"
                    :key="s.id"
                    :class="{ 'sub-row--attention': s.needs_attention, 'sub-row--historical': !s.is_current }"
                    :data-modality="s.modality"
                >
                    <!-- Empresa -->
                    <td>
                        <div
                            class="fw-medium text-truncate"
                            style="font-size: 0.875rem; max-width: 260px"
                            :title="s.entity_name"
                        >
                            {{ s.entity_name }}
                        </div>
                        <div class="d-flex flex-wrap gap-1 mt-1">
                            <span v-if="!s.is_current" class="badge badge-soft-secondary fs-11">{{
                                t.historical_badge
                            }}</span>
                            <span v-if="!s.entity_active" class="badge badge-soft-danger fs-11">
                                <i class="ti ti-lock me-1" aria-hidden="true"></i>{{ t.blocked_badge }}
                            </span>
                            <span v-if="s.needs_attention" class="badge badge-soft-warning fs-11">
                                <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>{{ t.attention_badge }}
                            </span>
                            <span
                                v-if="s.needs_reconciliation"
                                class="badge badge-soft-info fs-11"
                                :title="t.needs_review_hint"
                                data-test="sub-needs-review"
                            >
                                <i class="ti ti-file-search me-1" aria-hidden="true"></i>{{ t.needs_review_badge }}
                            </span>
                            <span
                                v-if="s.recurrence_alert"
                                class="badge badge-soft-warning fs-11"
                                :title="t.recurrence_alert_hint"
                                data-test="sub-recurrence-alert"
                            >
                                <i class="ti ti-repeat-off me-1" aria-hidden="true"></i>{{ t.recurrence_alert_badge }}
                            </span>
                        </div>
                    </td>

                    <!-- Plano -->
                    <td class="small">
                        <Link
                            v-if="canManagePlans && s.plan_id"
                            :href="route('manager.plans.index', { search: s.plan_name })"
                            :title="t.action_view_plan"
                            >{{ s.plan_name }}</Link
                        >
                        <span v-else>{{ s.plan_name }}</span>
                    </td>

                    <!-- Modalidade -->
                    <td>
                        <span class="badge sub-modality" :class="`sub-modality--${s.modality}`">{{
                            t.modality?.[s.modality]
                        }}</span>
                        <div v-if="s.billing_cycle_label" class="small text-muted mt-1">
                            {{ s.billing_cycle_label }}
                            <span v-if="s.gateway" class="text-uppercase">· {{ s.gateway }}</span>
                        </div>
                    </td>

                    <!-- Valor -->
                    <td class="text-end text-nowrap">
                        <div class="fw-semibold small">{{ amountText(s) }}</div>
                        <div v-if="monthlyText(s)" class="text-muted" style="font-size: 0.75rem">
                            {{ monthlyText(s) }}
                        </div>
                    </td>

                    <!-- Período / acesso -->
                    <td class="small text-nowrap">
                        <div>
                            {{
                                s.open_ended ? t.period_no_end : t.period_until.replace(':date', date(s.access_ends_at))
                            }}
                        </div>
                        <div
                            v-if="accessText(s)"
                            :class="s.days_left < 0 ? 'text-danger-emphasis' : 'text-muted'"
                            style="font-size: 0.75rem"
                            data-test="sub-access"
                        >
                            {{ accessText(s) }}
                        </div>
                    </td>

                    <!-- Situação -->
                    <td class="text-center">
                        <span class="badge" :class="s.status_badge">{{ s.status_label }}</span>
                        <div v-if="s.billing_state" class="mt-1">
                            <BillingStateBadge
                                :badge="s.billing_state_badge"
                                :label="s.billing_state_label"
                                :state="s.billing_state"
                            />
                        </div>
                        <div
                            v-if="dunningText(s)"
                            class="text-danger-emphasis mt-1"
                            style="font-size: 0.75rem"
                            data-test="sub-dunning"
                        >
                            {{ dunningText(s) }}
                        </div>
                    </td>

                    <!-- Ações -->
                    <td class="text-end">
                        <ActionIconGroup align="end" gap="tight">
                            <ActionIconButton icon="ti ti-eye" :title="t.action_manage" @click="$emit('view', s.id)" />
                            <ActionIconButton
                                icon="ti ti-calendar-plus"
                                variant="primary"
                                :title="s.can_extend ? t.action_extend : extendDisabledReason(s)"
                                :disabled="!s.can_extend"
                                @click="$emit('extend', s)"
                            />
                            <ActionDropdown
                                :min-width="230"
                                btn-class="ee-action-icon ee-action-icon--default"
                                icon="ti ti-dots-vertical"
                            >
                                <li>
                                    <button
                                        class="dropdown-item rounded-1"
                                        :disabled="!s.can_change_terms"
                                        @click="$emit('change', s)"
                                    >
                                        <i class="ti ti-adjustments-dollar me-1" aria-hidden="true"></i>
                                        {{ t.action_change }}
                                    </button>
                                </li>
                                <li>
                                    <button class="dropdown-item rounded-1" @click="$emit('newFor', s)">
                                        <i class="ti ti-file-plus me-1" aria-hidden="true"></i>
                                        {{ t.action_new_for_company }}
                                    </button>
                                </li>
                                <li v-if="s.is_current && (s.is_accessible || s.needs_reconciliation)">
                                    <button class="dropdown-item rounded-1 text-warning" @click="$emit('cancel', s)">
                                        <i class="ti ti-ban me-1" aria-hidden="true"></i> {{ t.action_cancel }}
                                    </button>
                                </li>
                                <li><hr class="dropdown-divider my-1" /></li>
                                <li>
                                    <button class="dropdown-item rounded-1" @click="$emit('block', s)">
                                        <i
                                            :class="`ti me-1 ${s.entity_active ? 'ti-lock' : 'ti-lock-open'}`"
                                            aria-hidden="true"
                                        ></i>
                                        {{ s.entity_active ? t.action_block : t.action_unblock }}
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
        :data="subscriptions"
        :showing-from="t.showing_from"
        :showing-of="t.showing_of"
        :showing-suffix="t.showing_suffix"
    />
</template>

<style scoped>
.sub-row--historical td {
    color: var(--bs-secondary-color);
}
/*
 * Linha que pede atenção (em atraso, falha de cobrança...): faixa à esquerda e
 * fundo suave, sem o `table-warning` do tema (fundo amarelo forte com texto
 * branco/vermelho abaixo de 3:1). O texto da régua fica em text-danger-emphasis
 * (≥ 4,5:1 nos temas claro e escuro).
 */
.sub-row--attention > td {
    background-color: var(--warning-transparent);
}
.sub-row--attention > td:first-child {
    /* Mantém o realce do hover do Bootstrap (2ª sombra) junto com a faixa. */
    box-shadow:
        inset 3px 0 0 var(--warning),
        inset 0 0 0 9999px var(--bs-table-bg-state, var(--bs-table-bg-type, var(--bs-table-accent-bg)));
}
.sub-modality {
    border: 1px solid transparent;
    font-weight: 500;
}
.sub-modality--trial {
    background: var(--bs-info-bg-subtle);
    color: var(--bs-info-text-emphasis);
}
.sub-modality--gateway {
    background: var(--bs-primary-bg-subtle);
    color: var(--bs-primary-text-emphasis);
}
.sub-modality--complimentary {
    background: var(--bs-warning-bg-subtle);
    color: var(--bs-warning-text-emphasis);
}
</style>
