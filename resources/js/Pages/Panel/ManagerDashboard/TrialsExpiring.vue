<script setup>
import { computed } from 'vue';

const props = defineProps({
    trialsExpiring: { type: Array, default: () => [] },
    t:              { type: Object, required: true },
});

function daysLeft(trialEndsAt) {
    const diff = Math.floor((new Date(trialEndsAt) - new Date()) / 86_400_000);
    return diff;
}

function scoreClass(score) {
    if (score >= 70) return 'high';
    if (score >= 40) return 'mid';
    return 'low';
}
</script>

<template>
    <div class="card mgr-chart-card h-100">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span>
                <i class="ti ti-alert-triangle me-2 text-warning"></i>{{ t.trials_expiring }}
            </span>
            <span class="badge bg-warning text-dark">{{ trialsExpiring.length }}</span>
        </div>
        <div class="card-body p-0">
            <div v-if="trialsExpiring.length === 0" class="text-center text-muted py-4">
                <i class="ti ti-mood-happy fs-1 d-block mb-2"></i>
                {{ t.no_trials_expiring }}
            </div>
            <table v-else class="table mgr-table mb-0">
                <thead>
                    <tr>
                        <th>{{ t.col_entity }}</th>
                        <th>{{ t.col_plan }}</th>
                        <th>{{ t.col_expires }}</th>
                        <th>{{ t.col_activation }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="trial in trialsExpiring" :key="trial.id">
                        <td>
                            <div class="fw-semibold">{{ trial.entity?.name ?? '—' }}</div>
                            <small class="text-muted">{{ trial.entity?.code }}</small>
                        </td>
                        <td>{{ trial.plan?.name ?? '—' }}</td>
                        <td>
                            <span
                                :class="['badge', daysLeft(trial.trial_ends_at) <= 2 ? 'bg-danger' : 'bg-warning text-dark']"
                            >
                                {{ daysLeft(trial.trial_ends_at) }}d
                            </span>
                        </td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="score-bar">
                                    <div
                                        :class="['score-bar-fill', `score-${scoreClass(trial.activation_score)}`]"
                                        :style="`width:${trial.activation_score}%;`"
                                    ></div>
                                </div>
                                <span
                                    :class="['fw-semibold', `text-score-${scoreClass(trial.activation_score)}`]"
                                    style="font-size:.8rem;"
                                >
                                    {{ trial.activation_score }}%
                                </span>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
