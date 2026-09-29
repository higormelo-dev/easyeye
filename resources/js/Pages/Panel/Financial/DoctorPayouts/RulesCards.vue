<script setup>
import { computed } from 'vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup  from '@/Components/Panel/ActionIconGroup.vue';
import StatusBadge      from '@/Components/Panel/StatusBadge.vue';
import { useDoctorPayoutFormat } from './useDoctorPayoutFormat.js';

/**
 * Regras de repasse em cards — mesmos dados e ações da RulesTable, usando o
 * MESMO paginator (a página só alterna a vista).
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
    <div v-if="rows.length === 0" class="text-center text-muted py-5" data-test="rules-empty">
        <i class="ti ti-adjustments-off fs-1 d-block mb-2" aria-hidden="true"></i>
        <p class="mb-0">{{ emptyText }}</p>
    </div>

    <ul v-else class="row g-3 list-unstyled mb-0" :aria-label="t.rules_title">
        <li v-for="rule in rows" :key="rule.id" class="col-12 col-sm-6 col-xl-4" data-test="rule-card" :data-id="rule.id">
            <div class="card card-body h-100 mb-0">
                <div class="d-flex align-items-start justify-content-between gap-2">
                    <div>
                        <p class="fw-semibold mb-0">
                            <i :class="serviceTypeIcon(rule.service_type)" class="me-1 text-primary" aria-hidden="true"></i>{{ serviceTypeLabel(rule.service_type) }}
                        </p>
                        <p class="fs-5 fw-bold mb-0 text-body" data-test="rule-calculation">{{ ruleLabel(rule) }}</p>
                    </div>
                    <StatusBadge :active="!!rule.active" :label-active="t.active" :label-inactive="t.inactive" />
                </div>

                <dl class="small text-muted mt-3 mb-2">
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_scope_doctor }}:</dt>
                        <dd class="mb-0 text-break">{{ rule.doctor_name || t.all_doctors }}</dd>
                    </div>
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_scope_item }}:</dt>
                        <dd class="mb-0 text-break">{{ rule.item_name || t.item_any }}</dd>
                    </div>
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_scope_payer }}:</dt>
                        <dd class="mb-0 text-break">{{ payerLabel(rule) }}<template v-if="rule.covenant_name"> · {{ rule.covenant_name }}</template></dd>
                    </div>
                    <div class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.col_validity }}:</dt>
                        <dd class="mb-0">{{ validityLabel(rule.valid_from, rule.valid_until) }}</dd>
                    </div>
                    <div v-if="rule.notes" class="d-flex gap-1">
                        <dt class="fw-semibold">{{ t.form_notes }}:</dt>
                        <dd class="mb-0 text-break">{{ rule.notes }}</dd>
                    </div>
                </dl>

                <hr class="my-2 mt-auto">

                <ActionIconGroup align="end" gap="tight">
                    <ActionIconButton icon="ti ti-edit" :title="t.rules_edit" data-test="rule-edit" @click="emit('edit', rule)" />
                    <ActionIconButton icon="ti ti-trash" variant="danger" :title="t.rules_delete" data-test="rule-delete" @click="emit('delete', rule)" />
                </ActionIconGroup>
            </div>
        </li>
    </ul>
</template>
