<script setup>
import { computed } from 'vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup  from '@/Components/Panel/ActionIconGroup.vue';
import StatusBadge      from '@/Components/Panel/StatusBadge.vue';
import { useDoctorPayoutFormat } from './useDoctorPayoutFormat.js';

/**
 * Regras de repasse em tabela: escopo (médico, serviço, item, pagador),
 * cálculo, vigência, situação e ações. Sem médico = vale para todos; sem
 * item = qualquer item do tipo.
 */
const props = defineProps({
    rules:     { type: Array,  default: () => [] },
    t:         { type: Object, default: () => ({}) },
    emptyText: { type: String, default: '' },
});

const emit = defineEmits(['edit', 'delete']);

const { ruleLabel, validityLabel, serviceTypeLabel, serviceTypeIcon } = useDoctorPayoutFormat(() => props.t);

const rows = computed(() => props.rules ?? []);

const payerLabel = (rule) => props.t.payer_scopes?.[rule.payer_scope] ?? rule.payer_scope;
</script>

<template>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <caption class="visually-hidden">{{ t.rules_title }}</caption>
            <thead class="table-light">
                <tr>
                    <th scope="col">{{ t.col_scope_doctor }}</th>
                    <th scope="col">{{ t.col_scope_service }}</th>
                    <th scope="col">{{ t.col_scope_item }}</th>
                    <th scope="col">{{ t.col_scope_payer }}</th>
                    <th scope="col">{{ t.col_calculation }}</th>
                    <th scope="col">{{ t.col_validity }}</th>
                    <th scope="col">{{ t.col_active }}</th>
                    <th scope="col" class="text-end">{{ t.col_actions }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="rows.length === 0">
                    <td colspan="8" class="text-center text-muted py-5" data-test="rules-empty">
                        <i class="ti ti-adjustments-off fs-1 d-block mb-2" aria-hidden="true"></i>{{ emptyText }}
                    </td>
                </tr>
                <tr v-for="rule in rows" :key="rule.id" :class="{ 'text-muted': !rule.active }" data-test="rule-row" :data-id="rule.id">
                    <td>
                        <span :class="rule.doctor_id ? 'fw-medium' : 'fst-italic'">{{ rule.doctor_name || t.all_doctors }}</span>
                        <div v-if="rule.notes" class="small text-muted text-truncate rules-table__notes" :title="rule.notes">{{ rule.notes }}</div>
                    </td>
                    <td class="text-nowrap">
                        <i :class="serviceTypeIcon(rule.service_type)" class="me-1 text-muted" aria-hidden="true"></i>{{ serviceTypeLabel(rule.service_type) }}
                    </td>
                    <td :class="{ 'fst-italic': !rule.item_name }">{{ rule.item_name || t.item_any }}</td>
                    <td>
                        {{ payerLabel(rule) }}
                        <div v-if="rule.covenant_name" class="small text-muted">{{ rule.covenant_name }}</div>
                    </td>
                    <td class="small" data-test="rule-calculation">{{ ruleLabel(rule) }}</td>
                    <td class="small text-nowrap">{{ validityLabel(rule.valid_from, rule.valid_until) }}</td>
                    <td>
                        <StatusBadge :active="!!rule.active" :label-active="t.active" :label-inactive="t.inactive" />
                    </td>
                    <td class="text-end">
                        <ActionIconGroup align="end" gap="tight">
                            <ActionIconButton icon="ti ti-edit" :title="t.rules_edit" data-test="rule-edit" @click="emit('edit', rule)" />
                            <ActionIconButton icon="ti ti-trash" variant="danger" :title="t.rules_delete" data-test="rule-delete" @click="emit('delete', rule)" />
                        </ActionIconGroup>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

<style scoped>
.rules-table__notes {
    max-width: 16rem;
}
</style>
