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

            <div class="row g-2">
                <div v-for="step in activation" :key="step.key" class="col-6 col-md-4 col-lg-3">
                    <div
                        class="d-flex align-items-center gap-2 p-2 rounded"
                        :class="step.done ? 'bg-success bg-opacity-10' : 'bg-light'"
                    >
                        <i :class="step.done ? 'ti ti-circle-check text-success' : 'ti ti-circle text-muted'"></i>
                        <span class="small" :class="step.done ? 'text-success fw-medium' : 'text-muted'">
                            {{ step.label }}
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
