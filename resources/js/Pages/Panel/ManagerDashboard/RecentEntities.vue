<script setup>
const props = defineProps({
    recentEntities: { type: Array, default: () => [] },
    t:              { type: Object, required: true },
});

const SUB_BADGE = {
    trial:     'badge-soft-info rounded text-info border border-info fs-13 fw-medium',
    active:    'badge-soft-success rounded text-success border border-success fs-13 fw-medium',
    expired:   'badge-soft-danger rounded text-danger border border-danger fs-13 fw-medium',
    cancelled: 'badge-soft-secondary rounded fs-13 fw-medium',
    past_due:  'badge-soft-warning rounded text-warning border border-warning fs-13 fw-medium',
};

const SUB_LABEL = {
    trial: 'Trial', active: 'Ativo', expired: 'Expirado', cancelled: 'Cancelado', past_due: 'Em atraso',
};

function scoreClass(score) {
    if (score >= 70) return 'high';
    if (score >= 40) return 'mid';
    return 'low';
}

function formatDate(date) {
    return date ? new Date(date).toLocaleDateString('pt-BR') : '—';
}
</script>

<template>
    <div class="card mgr-chart-card h-100">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span><i class="ti ti-building-plus me-2"></i>{{ t.recent_entities }}</span>
            <a :href="route('manager.entities.index')" class="btn btn-sm btn-outline-primary">
                {{ t.view_all }}
            </a>
        </div>
        <div class="card-body p-0">
            <div v-if="recentEntities.length === 0" class="text-center text-muted py-4">
                {{ t.no_entities }}
            </div>
            <table v-else class="table mgr-table mb-0">
                <thead>
                    <tr>
                        <th>{{ t.col_entity }}</th>
                        <th>{{ t.col_date }}</th>
                        <th>{{ t.col_subscription }}</th>
                        <th>{{ t.col_activation }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="entity in recentEntities" :key="entity.id">
                        <td>
                            <div class="fw-semibold">{{ entity.name }}</div>
                            <small class="text-muted">{{ entity.code }}</small>
                        </td>
                        <td>
                            <small>{{ formatDate(entity.created_at) }}</small>
                        </td>
                        <td>
                            <template v-if="entity.latest_sub">
                                <span :class="['badge', SUB_BADGE[entity.latest_sub.status?.value ?? entity.latest_sub.status]]">
                                    {{ SUB_LABEL[entity.latest_sub.status?.value ?? entity.latest_sub.status] ?? entity.latest_sub.status }}
                                </span>
                                <small v-if="entity.latest_sub.plan" class="d-block text-muted mt-1">
                                    {{ entity.latest_sub.plan.name }}
                                </small>
                            </template>
                            <span v-else class="text-muted">—</span>
                        </td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="score-bar">
                                    <div
                                        :class="['score-bar-fill', `score-${scoreClass(entity.activation_score)}`]"
                                        :style="`width:${entity.activation_score}%;`"
                                    ></div>
                                </div>
                                <span
                                    :class="['fw-semibold', `text-score-${scoreClass(entity.activation_score)}`]"
                                    style="font-size:.8rem;"
                                >
                                    {{ entity.activation_score }}%
                                </span>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
