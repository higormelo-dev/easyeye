<script setup>
const props = defineProps({
    topEntities: { type: Array, default: () => [] },
    t:           { type: Object, required: true },
});

function formatDate(date) {
    return date ? new Date(date).toLocaleDateString('pt-BR') : '—';
}
</script>

<template>
    <div class="card mgr-chart-card">
        <div class="card-header">
            <i class="ti ti-trophy me-2"></i>{{ t.top_entities }}
        </div>
        <div class="card-body p-0">
            <table class="table mgr-table mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>{{ t.col_entity }}</th>
                        <th>{{ t.col_patients }}</th>
                        <th>{{ t.col_date }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="topEntities.length === 0">
                        <td colspan="4" class="text-center text-muted py-3">{{ t.no_entities }}</td>
                    </tr>
                    <tr v-for="(entity, i) in topEntities" :key="entity.id">
                        <td>
                            <span v-if="i === 0" class="text-warning"><i class="ti ti-trophy"></i></span>
                            <span v-else class="text-muted">{{ i + 1 }}</span>
                        </td>
                        <td>
                            <div class="fw-semibold">{{ entity.name }}</div>
                            <small class="text-muted">{{ entity.code }}</small>
                        </td>
                        <td>
                            <span class="fw-bold">{{ Number(entity.patients_count).toLocaleString('pt-BR') }}</span>
                        </td>
                        <td>
                            <small>{{ formatDate(entity.created_at) }}</small>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
