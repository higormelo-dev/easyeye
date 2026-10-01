<script setup>
import { computed } from 'vue';
import ActionDropdown from '@/Components/Panel/ActionDropdown.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup from '@/Components/Panel/ActionIconGroup.vue';
import TablePagination from '@/Components/Panel/TablePagination.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';

/**
 * Histórico de períodos fechados: receitas, despesas, saldo, quem fechou,
 * quando (data/hora local), observação e atalho "Ver lançamentos" (Fluxo
 * filtrado pelo período). "Reabrir" fica num menu de ações perigosas e só
 * aparece para admin (`canReopen`); quem garante é o servidor.
 */
const props = defineProps({
    closes: { type: Object, required: true }, // paginator Laravel
    canReopen: { type: Boolean, default: false },
    busyId: { type: String, default: null },
    reopenError: { type: String, default: '' },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['reopen']);

const { money, signedMoney, date, dateTime } = useLocaleFormat();

function periodText(start, end) {
    return `${date(start)} – ${date(end)}`;
}

function entriesHref(close) {
    return route('panel.financial.cash-flow.index', { from: close.period_start, to: close.period_end });
}

const rows = computed(() => props.closes?.data ?? []);
</script>

<template>
    <div class="card cash-close-history">
        <div class="card-header">
            <h2 class="h6 fw-bold mb-0">{{ t.history }}</h2>
            <p v-if="!canReopen" class="small text-muted mb-0 mt-1" data-test="reopen-admin-only">
                <i class="ti ti-shield-lock me-1" aria-hidden="true"></i>{{ t.reopen_admin_only }}
            </p>
        </div>

        <div
            v-if="reopenError"
            class="alert alert-danger small d-flex gap-2 m-3 mb-0"
            role="alert"
            data-test="reopen-error"
        >
            <i class="ti ti-alert-circle mt-1" aria-hidden="true"></i>
            <span>{{ reopenError }}</span>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <caption class="visually-hidden">
                        {{
                            t.history
                        }}
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ t.col_period }}</th>
                            <th scope="col" class="text-end d-none d-md-table-cell">{{ t.col_income }}</th>
                            <th scope="col" class="text-end d-none d-md-table-cell">{{ t.col_expense }}</th>
                            <th scope="col" class="text-end">{{ t.col_balance }}</th>
                            <th scope="col" class="d-none d-lg-table-cell">{{ t.col_closed_by }}</th>
                            <th scope="col" class="d-none d-sm-table-cell">{{ t.col_closed_at }}</th>
                            <th scope="col" class="text-end">
                                <span class="visually-hidden">{{ t.col_actions }}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="rows.length === 0">
                            <td colspan="7" class="text-center text-muted py-4" data-test="history-empty">
                                {{ t.empty }}
                            </td>
                        </tr>
                        <tr
                            v-for="c in rows"
                            :key="c.id"
                            :class="{ 'opacity-50': busyId === c.id }"
                            :aria-busy="busyId === c.id ? 'true' : 'false'"
                            :data-test="`close-${c.id}`"
                        >
                            <td>
                                <span class="text-nowrap fw-medium">{{
                                    periodText(c.period_start, c.period_end)
                                }}</span>
                                <small
                                    v-if="c.notes"
                                    class="d-block text-muted cash-close-history__notes"
                                    :title="c.notes"
                                    data-test="close-notes"
                                >
                                    <span class="visually-hidden">{{ t.col_notes }}: </span>{{ c.notes }}
                                </small>
                            </td>
                            <td class="text-end text-nowrap d-none d-md-table-cell cash-close-history__value">
                                {{ money(c.total_income) }}
                            </td>
                            <td class="text-end text-nowrap d-none d-md-table-cell cash-close-history__value">
                                {{ money(c.total_expense) }}
                            </td>
                            <td class="text-end text-nowrap fw-medium cash-close-history__value">
                                {{ signedMoney(c.balance) }}
                            </td>
                            <td class="small d-none d-lg-table-cell">{{ c.closed_by_name || '—' }}</td>
                            <td class="small text-muted text-nowrap d-none d-sm-table-cell">
                                {{ dateTime(c.closed_at) }}
                            </td>
                            <td class="text-end text-nowrap">
                                <ActionIconGroup align="end" gap="tight">
                                    <ActionIconButton
                                        icon="ti ti-list-search"
                                        :title="t.view_entries"
                                        :aria-label="`${t.view_entries}: ${periodText(c.period_start, c.period_end)}`"
                                        :inertia-href="entriesHref(c)"
                                        data-test="view-entries"
                                    />
                                    <ActionDropdown
                                        v-if="canReopen"
                                        :title="`${t.actions_more}: ${periodText(c.period_start, c.period_end)}`"
                                        align="right"
                                        :min-width="200"
                                        btn-class="ee-action-icon ee-action-icon--default"
                                    >
                                        <li>
                                            <button
                                                type="button"
                                                class="dropdown-item d-flex align-items-center gap-2 text-danger"
                                                :disabled="busyId === c.id"
                                                data-test="reopen"
                                                @click="emit('reopen', c)"
                                            >
                                                <i class="ti ti-lock-open" aria-hidden="true"></i>{{ t.reopen }}
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
                :data="closes"
                class="p-3"
                :showing-from="t.pagination_showing"
                :showing-of="t.pagination_of"
                :showing-suffix="t.pagination_suffix"
                :aria-label="t.pagination_label"
                :previous-label="t.pagination_previous"
                :next-label="t.pagination_next"
            />
        </div>
    </div>
</template>

<style scoped>
.cash-close-history__notes {
    max-width: 18rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.cash-close-history__value {
    font-variant-numeric: tabular-nums;
}
</style>
