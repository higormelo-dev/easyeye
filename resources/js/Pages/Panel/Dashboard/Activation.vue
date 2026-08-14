<script setup>
import { computed } from 'vue';

const props = defineProps({
    activation:      { type: Array,  required: true },
    activationScore: { type: Number, required: true },
    t:               { type: Object, required: true },
});

const color = computed(() => {
    const s = props.activationScore;
    if (s >= 80) return 'success';
    if (s >= 50) return 'info';
    if (s >= 30) return 'warning';
    return 'danger';
});
</script>

<template>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-4">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <div>
                    <h6 class="mb-0 fw-semibold">
                        <i :class="`fas fa-rocket text-${color} me-2`"></i>
                        {{ t.activation_title }}
                    </h6>
                    <p class="text-muted small mb-0">{{ t.activation_subtitle }}</p>
                </div>
                <div class="text-end">
                    <span :class="`fs-4 fw-bold text-${color}`">{{ activationScore }}%</span>
                    <div class="text-muted" style="font-size:.7rem;">{{ t.activation_done }}</div>
                </div>
            </div>

            <div class="progress mb-3" style="height:8px;border-radius:4px;">
                <div class="progress-bar" :class="`bg-${color}`" :style="`width:${activationScore}%`" />
            </div>

            <div class="d-flex align-items-center gap-2 flex-wrap">
                <div
                    v-for="step in activation"
                    :key="step.key"
                    class="d-flex align-items-center gap-1 px-3 py-2 rounded border flex-shrink-0"
                    :class="step.done ? 'border-success bg-success bg-opacity-10' : 'bg-white'"
                >
                    <i class="flex-shrink-0 fs-16" :class="step.done ? 'ti ti-circle-check text-success' : 'ti ti-circle text-muted'"></i>
                    <span class="small text-nowrap" :class="step.done ? 'text-success fw-medium' : 'text-muted'">
                        {{ step.label }}
                        <span v-if="!step.done && !step.required" class="text-muted fst-italic">({{ t.activation_optional ?? 'opcional' }})</span>
                    </span>
                    <span v-if="!step.done" class="fw-semibold small" style="color:#0d6efd">+{{ step.weight }}%</span>
                </div>
            </div>
        </div>
    </div>
</template>
