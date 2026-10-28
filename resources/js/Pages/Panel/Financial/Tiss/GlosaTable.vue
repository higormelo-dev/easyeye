<script setup>
import { computed } from 'vue';
import ActionDropdown from '@/Components/Panel/ActionDropdown.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';
import {
    GLOSA_ICONS,
    awaitingResponse,
    deadlineBadge,
    glosaRef,
    latestAppeal,
    nextAction,
    softBadge,
} from './glosaHelpers.js';

/**
 * Lista da Conciliação (uma página do paginator — a paginação fica na
 * página): prazo (badge com ícone + texto), guia (abre o painel de detalhes),
 * motivo truncado com a descrição completa no tooltip, status, recurso (nº
 * REC + status + prazo de resposta) e valor. A próxima ação é o botão da
 * linha; as demais ficam no menu "Mais ações".
 */
const props = defineProps({
    /** Linhas da página atual (`glosas.data` do paginator). */
    glosas: { type: Array, default: () => [] },
    today: { type: String, default: '' },
    dueSoonDays: { type: Number, default: 5 },
    busy: { type: Boolean, default: false },
    emptyText: { type: String, default: '' },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['action', 'details']);

const { tx } = useTrans(() => props.t);
const { money, date } = useLocaleFormat();

const ACTIONS = {
    appeal: { label: 'appeal_btn', icon: 'ti-message-circle-up', cls: 'btn-outline-warning', test: 'btn-appeal' },
    submit: { label: 'submit_appeal_btn', icon: 'ti-send', cls: 'btn-outline-info', test: 'btn-submit' },
    resolve: { label: 'resolve_appeal_btn', icon: 'ti-gavel', cls: 'btn-outline-primary', test: 'btn-resolve' },
    details: { label: 'details_btn', icon: 'ti-list-details', cls: 'btn-outline-secondary', test: 'btn-details' },
};

const rows = computed(() =>
    props.glosas.map((glosa) => {
        const next = nextAction(glosa);

        return {
            glosa,
            next,
            action: ACTIONS[next.kind],
            ref: glosaRef(glosa),
            deadline: deadlineBadge(glosa, props.today, props.dueSoonDays, tx),
            appeal: latestAppeal(glosa),
        };
    }),
);

function runAction(row) {
    if (row.next.kind === 'details') {
        emit('details', row.glosa);

        return;
    }

    emit('action', row.next.kind, row.glosa, row.next.appeal);
}
</script>

<template>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" :aria-busy="busy ? 'true' : 'false'">
            <thead class="table-light">
                <tr>
                    <th scope="col">{{ t.col_deadline }}</th>
                    <th scope="col">{{ t.col_guide }}</th>
                    <th scope="col" class="d-none d-lg-table-cell">{{ t.col_covenant }}</th>
                    <th scope="col">{{ t.col_reason }}</th>
                    <th scope="col" class="d-none d-lg-table-cell">{{ t.col_date }}</th>
                    <th scope="col">{{ t.col_status }}</th>
                    <th scope="col" class="d-none d-lg-table-cell">{{ t.col_appeal }}</th>
                    <th scope="col" class="text-end">{{ t.col_value }}</th>
                    <th scope="col" class="text-end">
                        <span class="visually-hidden">{{ t.col_actions }}</span>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="rows.length === 0">
                    <td colspan="9" class="text-center text-muted py-5" data-test="glosas-empty">
                        <i class="ti ti-checks fs-1 d-block mb-2" aria-hidden="true"></i>
                        <p class="fw-medium mb-1">{{ emptyText || t.empty }}</p>
                        <p class="small mb-0">{{ t.empty_hint }}</p>
                    </td>
                </tr>
                <tr v-for="row in rows" :key="row.glosa.id" data-test="glosa-row">
                    <td class="small text-nowrap" data-test="deadline-cell">
                        <template v-if="row.deadline">
                            <span class="badge" :class="row.deadline.cls">
                                <i class="ti me-1" :class="row.deadline.icon" aria-hidden="true"></i
                                >{{ row.deadline.text }}
                            </span>
                            <small v-if="row.glosa.deadline" class="text-muted d-block mt-1">{{
                                date(row.glosa.deadline)
                            }}</small>
                        </template>
                        <span v-else class="text-muted">{{ row.glosa.deadline ? date(row.glosa.deadline) : '—' }}</span>
                    </td>
                    <td class="text-nowrap" data-test="guide-cell">
                        <button
                            type="button"
                            class="btn btn-link btn-sm p-0 text-start fw-semibold"
                            :aria-label="tx('details_label', { code: row.ref })"
                            data-test="open-details"
                            @click="emit('details', row.glosa)"
                        >
                            <code class="small">{{ row.glosa.guide_number || t.no_guide }}</code>
                        </button>
                        <small v-if="row.glosa.claim_code" class="d-block text-muted">{{
                            tx('claim_code_label', { code: row.glosa.claim_code })
                        }}</small>
                    </td>
                    <td class="d-none d-lg-table-cell">{{ row.glosa.operator_name || t.no_covenant }}</td>
                    <td class="small">
                        <span class="badge badge-soft-secondary me-1">{{ row.glosa.reason_code }}</span>
                        <span
                            class="glosa-reason d-inline-block text-truncate align-middle"
                            :title="row.glosa.reason_text || undefined"
                            data-test="reason-text"
                            >{{ row.glosa.reason_text || '—' }}</span
                        >
                    </td>
                    <td class="d-none d-lg-table-cell small text-muted text-nowrap">
                        {{ date(row.glosa.identified_at) }}
                    </td>
                    <td class="text-nowrap">
                        <span class="badge fs-11" :class="softBadge(row.glosa.status_color)" data-test="glosa-status">
                            <i
                                class="ti me-1"
                                :class="GLOSA_ICONS[row.glosa.status] ?? 'ti-point'"
                                aria-hidden="true"
                            ></i
                            >{{ row.glosa.status_label }}
                        </span>
                    </td>
                    <td class="d-none d-lg-table-cell small" data-test="appeal-cell">
                        <template v-if="row.appeal">
                            <code class="small me-1">{{ row.appeal.appeal_number }}</code>
                            <span class="badge fs-11" :class="softBadge(row.appeal.status_color)">{{
                                row.appeal.status_label
                            }}</span>
                            <small v-if="awaitingResponse(row.appeal)" class="text-muted d-block mt-1">
                                {{ tx('appeal_response_until', { date: date(row.appeal.deadline) }) }}
                            </small>
                            <small v-if="row.glosa.appeals.length > 1" class="text-muted d-block">
                                {{ tx('appeals_previous', { count: row.glosa.appeals.length - 1 }) }}
                            </small>
                        </template>
                        <span v-else class="text-muted">—</span>
                    </td>
                    <td class="text-end fw-bold text-nowrap">{{ money(row.glosa.amount) }}</td>
                    <td class="text-end text-nowrap">
                        <div class="d-inline-flex align-items-center gap-1">
                            <button
                                type="button"
                                class="btn btn-sm"
                                :class="row.action.cls"
                                :data-test="row.action.test"
                                :disabled="busy && row.next.kind !== 'details'"
                                @click="runAction(row)"
                            >
                                <i class="ti me-1" :class="row.action.icon" aria-hidden="true"></i
                                >{{ t[row.action.label] }}
                            </button>
                            <ActionDropdown
                                v-if="row.next.kind !== 'details'"
                                :title="tx('more_actions', { code: row.ref })"
                                btn-class="ee-action-icon ee-action-icon--default"
                                icon="ti ti-dots-vertical"
                            >
                                <li>
                                    <button
                                        type="button"
                                        class="dropdown-item rounded-1"
                                        data-test="menu-details"
                                        @click="emit('details', row.glosa)"
                                    >
                                        <i class="ti ti-list-details me-1" aria-hidden="true"></i>{{ t.details_btn }}
                                    </button>
                                </li>
                            </ActionDropdown>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

<style scoped>
.glosa-reason {
    max-width: 16rem;
}
</style>
