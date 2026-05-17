<script setup>
defineProps({
    patients: { type: Array,  default: () => [] },
    t:        { type: Object, required: true },
});
</script>

<template>
    <div class="card mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span>
                <i class="ti ti-users me-2 text-primary"></i>
                {{ t.section_recent_patients }}
            </span>
            <a :href="route('panel.patients.index')" class="btn btn-sm btn-outline-primary">
                {{ t.btn_see_all }} <i class="ti ti-arrow-right ms-1"></i>
            </a>
        </div>

        <div class="card-body p-0">
            <div v-if="patients.length === 0" class="text-center text-muted py-4">
                <i class="ti ti-users" style="font-size:2rem;display:block;margin-bottom:.5rem;"></i>
                {{ t.empty_patients }}
            </div>

            <table v-else class="schedule-table table table-hover mb-0">
                <thead>
                    <tr>
                        <th>{{ t.col_name }}</th>
                        <th class="d-none d-md-table-cell">{{ t.col_phone }}</th>
                        <th class="d-none d-sm-table-cell">{{ t.col_code }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in patients" :key="p.id">
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div
                                    class="patient-initial"
                                    :style="{ background: p.color }"
                                >
                                    {{ p.initial }}
                                </div>
                                <span class="fw-medium" style="font-size:.875rem;">{{ p.name }}</span>
                            </div>
                        </td>
                        <td class="text-muted small d-none d-md-table-cell">{{ p.phone }}</td>
                        <td class="d-none d-sm-table-cell">
                            <code class="text-muted small">{{ p.code }}</code>
                        </td>
                        <td class="text-end">
                            <a :href="p.url" class="btn btn-xs btn-outline-secondary">
                                {{ t.btn_view }}
                            </a>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
