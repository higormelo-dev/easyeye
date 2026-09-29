<script setup>
import { computed, useId } from 'vue';
import PeriodFilter from '@/Components/Panel/PeriodFilter.vue';
import { useDoctorPayoutFormat } from './useDoctorPayoutFormat.js';

/**
 * Filtros da apuração: médico, período (sem datas futuras — só entra o que
 * foi realizado) e, com médico escolhido, status e tipo dos itens. Cada troca
 * emite `change` com o conjunto COMPLETO de filtros; a página visita a URL.
 */
const props = defineProps({
    filters:  { type: Object,  default: () => ({}) },   // { doctor, from, to, status, service_type }
    doctors:  { type: Array,   default: () => [] },     // [{ id, name, record, active }]
    today:    { type: String,  default: '' },
    t:        { type: Object,  default: () => ({}) },
    shared:   { type: Object,  default: () => ({}) },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['change']);

const ITEM_STATUSES = ['pending', 'closed', 'paid'];
const SERVICE_TYPES = ['consultation', 'exam', 'procedure'];

const { doctorLabel, statusLabel, serviceTypeLabel } = useDoctorPayoutFormat(() => props.t);

const uid = useId();
const ids = {
    doctor: `dp-filter-doctor-${uid}`,
    status: `dp-filter-status-${uid}`,
    type:   `dp-filter-type-${uid}`,
};

const hasDoctor      = computed(() => !!props.filters.doctor);
const hasListFilters = computed(() => !!props.filters.status || !!props.filters.service_type);

function change(patch) {
    emit('change', {
        doctor:       props.filters.doctor ?? '',
        from:         props.filters.from ?? '',
        to:           props.filters.to ?? '',
        status:       props.filters.status ?? '',
        service_type: props.filters.service_type ?? '',
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
                        <option v-for="doctor in doctors" :key="doctor.id" :value="doctor.id">{{ doctorLabel(doctor) }}</option>
                    </select>
                </div>

                <PeriodFilter
                    :from="filters.from ?? ''"
                    :to="filters.to ?? ''"
                    :today="today"
                    :max="today"
                    :labels="shared?.period"
                    :disabled="disabled"
                    @change="({ from, to }) => change({ from, to })"
                />

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
                            <option v-for="status in ITEM_STATUSES" :key="status" :value="status">{{ statusLabel(status) }}</option>
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
                            <option v-for="type in SERVICE_TYPES" :key="type" :value="type">{{ serviceTypeLabel(type) }}</option>
                        </select>
                    </div>
                    <button
                        v-if="hasListFilters"
                        type="button"
                        class="btn btn-link btn-sm text-decoration-none px-1"
                        :disabled="disabled"
                        data-test="filters-clear"
                        @click="change({ status: '', service_type: '' })"
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
