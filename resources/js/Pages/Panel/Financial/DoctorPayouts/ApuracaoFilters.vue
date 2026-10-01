<script setup>
import { computed, useId } from 'vue';
import PeriodFilter from '@/Components/Panel/PeriodFilter.vue';
import { RECEIPT_STATUSES, useDoctorPayoutFormat } from './useDoctorPayoutFormat.js';

/**
 * Filtros da apuração: médico, período (pela data do RECEBIMENTO, sem datas
 * futuras; a regra vale pela data do atendimento) e, com médico escolhido,
 * status (inclui "com recebimento" = tudo menos a previsão, as mesmas linhas
 * do resumo por tipo), tipo, situação de recebimento e alerta dos itens. Cada
 * troca emite `change` com o conjunto COMPLETO de filtros; a página visita a URL.
 */
const props = defineProps({
    filters: { type: Object, default: () => ({}) }, // { doctor, from, to, status, service_type, receipt, warning }
    doctors: { type: Array, default: () => [] }, // [{ id, name, record, active }]
    today: { type: String, default: '' },
    t: { type: Object, default: () => ({}) },
    shared: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['change']);

const ITEM_STATUSES = ['pending', 'awaiting', 'closed', 'partially_paid', 'paid'];
const SERVICE_TYPES = ['consultation', 'exam', 'procedure'];

const { doctorLabel, statusLabel, serviceTypeLabel, receiptStatusLabel, warningLabel } = useDoctorPayoutFormat(
    () => props.t,
);

/** Alertas do filtro, na ordem das traduções. */
const warnings = computed(() => Object.keys(props.t.warnings ?? {}));

const uid = useId();
const ids = {
    doctor: `dp-filter-doctor-${uid}`,
    status: `dp-filter-status-${uid}`,
    type: `dp-filter-type-${uid}`,
    receipt: `dp-filter-receipt-${uid}`,
    warning: `dp-filter-warning-${uid}`,
    periodHint: `dp-filter-period-hint-${uid}`,
};

const hasDoctor = computed(() => !!props.filters.doctor);
const hasListFilters = computed(
    () => !!props.filters.status || !!props.filters.service_type || !!props.filters.receipt || !!props.filters.warning,
);

function change(patch) {
    emit('change', {
        doctor: props.filters.doctor ?? '',
        from: props.filters.from ?? '',
        to: props.filters.to ?? '',
        status: props.filters.status ?? '',
        service_type: props.filters.service_type ?? '',
        receipt: props.filters.receipt ?? '',
        warning: props.filters.warning ?? '',
        ...patch,
    });
}
</script>

<template>
    <div class="card mb-3" data-test="apuracao-filters">
        <div class="card-body py-3">
            <div class="d-flex flex-wrap align-items-end gap-3">
                <div class="dp-filters__doctor">
                    <label :for="ids.doctor" class="form-label small mb-1">{{ t.filter_doctor }}</label>
                    <select
                        :id="ids.doctor"
                        class="form-select form-select-sm"
                        :value="filters.doctor ?? ''"
                        :disabled="disabled"
                        data-test="filter-doctor"
                        @change="change({ doctor: $event.target.value })"
                    >
                        <option value="">{{ t.filter_doctor_placeholder }}</option>
                        <option v-for="doctor in doctors" :key="doctor.id" :value="doctor.id">
                            {{ doctorLabel(doctor) }}
                        </option>
                    </select>
                </div>

                <div>
                    <PeriodFilter
                        :from="filters.from ?? ''"
                        :to="filters.to ?? ''"
                        :today="today"
                        :max="today"
                        :labels="shared?.period"
                        :disabled="disabled"
                        :aria-describedby="ids.periodHint"
                        @change="({ from, to }) => change({ from, to })"
                    />
                    <div :id="ids.periodHint" class="form-text small mt-1" data-test="period-hint">
                        {{ t.filter_period_hint }}
                    </div>
                </div>

                <template v-if="hasDoctor">
                    <div>
                        <label :for="ids.status" class="form-label small mb-1">{{ t.filter_status }}</label>
                        <select
                            :id="ids.status"
                            class="form-select form-select-sm"
                            :value="filters.status ?? ''"
                            :disabled="disabled"
                            data-test="filter-status"
                            @change="change({ status: $event.target.value })"
                        >
                            <option value="">{{ t.filter_status_all }}</option>
                            <option v-for="status in ITEM_STATUSES" :key="status" :value="status">
                                {{ statusLabel(status) }}
                            </option>
                            <option value="in_payout">{{ t.filter_status_in_payout }}</option>
                        </select>
                    </div>
                    <div>
                        <label :for="ids.type" class="form-label small mb-1">{{ t.filter_service_type }}</label>
                        <select
                            :id="ids.type"
                            class="form-select form-select-sm"
                            :value="filters.service_type ?? ''"
                            :disabled="disabled"
                            data-test="filter-type"
                            @change="change({ service_type: $event.target.value })"
                        >
                            <option value="">{{ t.filter_service_type_all }}</option>
                            <option v-for="type in SERVICE_TYPES" :key="type" :value="type">
                                {{ serviceTypeLabel(type) }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label :for="ids.receipt" class="form-label small mb-1">{{ t.filter_receipt }}</label>
                        <select
                            :id="ids.receipt"
                            class="form-select form-select-sm"
                            :value="filters.receipt ?? ''"
                            :disabled="disabled"
                            data-test="filter-receipt"
                            @change="change({ receipt: $event.target.value })"
                        >
                            <option value="">{{ t.filter_receipt_all }}</option>
                            <option v-for="status in RECEIPT_STATUSES" :key="status" :value="status">
                                {{ receiptStatusLabel(status) }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label :for="ids.warning" class="form-label small mb-1">{{ t.filter_warning }}</label>
                        <select
                            :id="ids.warning"
                            class="form-select form-select-sm"
                            :value="filters.warning ?? ''"
                            :disabled="disabled"
                            data-test="filter-warning"
                            @change="change({ warning: $event.target.value })"
                        >
                            <option value="">{{ t.filter_warning_all }}</option>
                            <option v-for="code in warnings" :key="code" :value="code">{{ warningLabel(code) }}</option>
                        </select>
                    </div>
                    <button
                        v-if="hasListFilters"
                        type="button"
                        class="btn btn-link btn-sm text-decoration-none px-1"
                        :disabled="disabled"
                        data-test="filters-clear"
                        @click="change({ status: '', service_type: '', receipt: '', warning: '' })"
                    >
                        <i class="ti ti-filter-off me-1" aria-hidden="true"></i>{{ t.filter_clear }}
                    </button>
                </template>
            </div>
        </div>
    </div>
</template>

<style scoped>
.dp-filters__doctor {
    min-width: 14rem;
}

@media (max-width: 575.98px) {
    .dp-filters__doctor {
        flex: 1 1 100%;
        min-width: 0;
    }
}
</style>
