<script setup>
defineProps({
    stats:        { type: Object,  required: true },
    isRefreshing: { type: Boolean, default: false },
    t:            { type: Object,  required: true },
});
</script>

<template>
    <div class="card h-100">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span>
                <i class="ti ti-chart-bar me-2 text-warning"></i>
                {{ t.section_day_summary }}
            </span>
            <span v-if="isRefreshing" class="text-muted" style="font-size:.75rem;">
                <i class="ti ti-loader-2 db-spin"></i>
            </span>
        </div>

        <div class="card-body d-flex flex-column gap-2 p-3">
            <div class="day-summary-stat day-summary-stat--total">
                <div class="ds-value ds-value--total">{{ stats.today_count }}</div>
                <div class="ds-label">{{ t.summary_total }}</div>
            </div>

            <div class="day-summary-stat day-summary-stat--attended">
                <div class="ds-value ds-value--attended">{{ stats.attended_today }}</div>
                <div class="ds-label">{{ t.summary_attended }}</div>
            </div>

            <div class="day-summary-stat day-summary-stat--pending">
                <div class="ds-value ds-value--pending">{{ stats.pending_today }}</div>
                <div class="ds-label">{{ t.summary_pending }}</div>
            </div>

            <div class="day-summary-stat day-summary-stat--cancelled">
                <div class="ds-value ds-value--cancelled">{{ stats.cancelled_today }}</div>
                <div class="ds-label">{{ t.summary_cancelled }}</div>
            </div>
        </div>
    </div>
</template>
