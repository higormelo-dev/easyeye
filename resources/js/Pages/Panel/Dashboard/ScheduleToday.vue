<script setup>
import { computed } from 'vue';

const props = defineProps({
    items:        { type: Array,   default: () => [] },
    isRefreshing: { type: Boolean, default: false },
    t:            { type: Object,  required: true },
});

const activeCount = computed(() =>
    props.items.filter(i => i.is_active).length,
);

const badgeColorMap = {
    'bg-secondary':          { bg: '#e2e8f0', text: '#475569' },
    'bg-info text-dark':     { bg: '#e0f2fe', text: '#0369a1' },
    'bg-warning text-dark':  { bg: '#fef3c7', text: '#92400e' },
    'bg-purple text-white':  { bg: '#ede9fe', text: '#7c3aed' },
    'bg-orange text-white':  { bg: '#ffedd5', text: '#c2410c' },
    'bg-primary':            { bg: '#dbeafe', text: '#1d4ed8' },
    'bg-success':            { bg: '#dcfce7', text: '#166534' },
    'bg-danger':             { bg: '#fee2e2', text: '#991b1b' },
    'bg-dark':               { bg: '#f1f5f9', text: '#334155' },
};

function badgeStyle(badge) {
    const c = badgeColorMap[badge] ?? badgeColorMap['bg-secondary'];
    return { backgroundColor: c.bg, color: c.text, fontWeight: 600 };
}
</script>

<template>
    <div class="card h-100">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span class="d-flex align-items-center gap-2">
                <i class="ti ti-calendar-event text-primary"></i>
                {{ t.section_schedule_today }}
                <span v-if="activeCount > 0" class="badge bg-primary ms-1" style="font-size:.7rem;">
                    {{ activeCount }} {{ t.live_label }}
                </span>
            </span>
            <div class="d-flex align-items-center gap-2">
                <span v-if="isRefreshing" class="text-muted" style="font-size:.75rem;">
                    <i class="ti ti-loader-2 db-spin"></i>
                </span>
                <a :href="route('panel.schedules.index')" class="btn btn-sm btn-outline-primary">
                    {{ t.btn_see_schedule }} <i class="ti ti-arrow-right ms-1"></i>
                </a>
            </div>
        </div>

        <div class="card-body p-0">
            <div v-if="items.length === 0" class="text-center text-muted py-5">
                <i class="ti ti-calendar-off" style="font-size:2.5rem;display:block;margin-bottom:.75rem;"></i>
                {{ t.empty_schedules }}
            </div>

            <table v-else class="schedule-table table table-hover mb-0">
                <thead>
                    <tr>
                        <th style="width:60px;">{{ t.col_time }}</th>
                        <th>{{ t.col_name }}</th>
                        <th class="d-none d-md-table-cell">{{ t.col_doctor }}</th>
                        <th>{{ t.col_situation }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="item in items"
                        :key="item.id"
                        :class="item.is_active ? 'schedule-row--active' : ''"
                    >
                        <td class="fw-semibold" style="font-size:.9rem;letter-spacing:.02em;">
                            {{ item.time }}
                        </td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <span
                                    v-if="item.arrived"
                                    class="ti ti-circle-check text-success"
                                    title="Chegou"
                                ></span>
                                <span class="fw-medium" style="font-size:.875rem;">{{ item.name }}</span>
                            </div>
                        </td>
                        <td class="text-muted small d-none d-md-table-cell">
                            {{ item.doctor }}
                        </td>
                        <td>
                            <span class="badge rounded-pill px-2 py-1" :style="badgeStyle(item.badge)">
                                <i :class="`fa ${item.icon} me-1`" style="font-size:.65rem;"></i>
                                {{ item.label }}
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
