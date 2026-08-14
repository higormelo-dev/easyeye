<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    stats:        { type: Object,  required: true },
    isDoctor:     { type: Boolean, default: false },
    rule:         { type: String,  default: '' },
    isRefreshing: { type: Boolean, default: false },
    t:            { type: Object,  required: true },
});

// ── Atalhos: indicador → lista relacionada ───────────────────────────────────
// `today` aponta pra agenda (panel.schedules.index), rota restrita a
// admin/doctor/secretary — 'financial' não tem acesso, então fica sem link
// (evita atalho que estoura 403 via EnsureEntityRole).
const canOpenSchedule = computed(() => props.rule !== 'financial');

const row1 = computed(() => [
    {
        key:     'patients',
        icon:    'ti ti-users',
        value:   props.stats.total_patients,
        label:   props.t.kpi_patients,
        variant: 'patients',
        url:     route('panel.patients.index'),
    },
    {
        key:     'today',
        icon:    'ti ti-calendar-check',
        value:   props.stats.today_count,
        label:   props.t.kpi_today,
        variant: 'today',
        url:     canOpenSchedule.value ? route('panel.schedules.index') : null,
    },
    {
        key:     'doctors',
        icon:    'ti ti-stethoscope',
        value:   props.stats.total_doctors,
        label:   props.t.kpi_doctors,
        variant: 'doctors',
        url:     route('panel.doctors.index'),
    },
    {
        key:     'surgeries',
        icon:    'ti ti-scalpel',
        value:   null,
        label:   props.t.kpi_surgeries,
        variant: 'surgeries',
        soon:    true,
    },
]);

const row2 = computed(() => {
    const base = [
        {
            key:     'exams',
            icon:    'ti ti-eye',
            value:   null,
            label:   props.t.kpi_exams_pending,
            variant: 'exams',
            soon:    true,
        },
    ];

    if (!props.isDoctor) {
        base.push(
            {
                key:     'guides',
                icon:    'ti ti-file-invoice',
                value:   null,
                label:   props.t.kpi_guides_waiting,
                variant: 'guides',
                soon:    true,
            },
            {
                key:     'receivable',
                icon:    'ti ti-currency-dollar',
                value:   null,
                label:   props.t.kpi_receivable,
                variant: 'receivable',
                soon:    true,
            },
            {
                key:     'satisfaction',
                icon:    'ti ti-star',
                value:   null,
                label:   props.t.kpi_satisfaction,
                variant: 'satisfaction',
                soon:    true,
            },
        );
    }

    return base;
});
</script>

<template>
    <!-- Row 1 -->
    <div class="row g-3 mb-3">
        <div v-for="kpi in row1" :key="kpi.key" class="col-6 col-md-3">
            <component
                :is="kpi.url ? Link : 'div'"
                :href="kpi.url ?? undefined"
                :title="kpi.url ? (t.kpi_open_list ?? 'Ver lista').replace(':label', kpi.label) : null"
                :class="[
                    'card stat-card h-100',
                    `stat-card--${kpi.variant}`,
                    kpi.soon ? 'stat-card-mock' : '',
                    kpi.url ? 'stat-card--clickable' : '',
                    isRefreshing && !kpi.soon ? 'stat-card--refreshing' : '',
                ]"
            >
                <div class="card-body d-flex align-items-center gap-3 p-3">
                    <div :class="`stat-icon stat-icon--${kpi.variant}`">
                        <i :class="kpi.icon"></i>
                    </div>
                    <div>
                        <div :class="['stat-value', kpi.soon ? 'stat-value-mock' : '']">
                            <template v-if="kpi.soon">—</template>
                            <template v-else-if="isRefreshing">
                                <span class="stat-skeleton"></span>
                            </template>
                            <template v-else>{{ kpi.value }}</template>
                        </div>
                        <div class="stat-label">{{ kpi.label }}</div>
                        <span v-if="kpi.soon" class="stat-badge-soon">{{ t.kpi_coming_soon }}</span>
                    </div>
                    <i v-if="kpi.url" class="ti ti-chevron-right ms-auto text-muted stat-card-chevron"></i>
                </div>
            </component>
        </div>
    </div>

    <!-- Row 2 -->
    <div class="row g-3 mb-4">
        <div v-for="kpi in row2" :key="kpi.key" class="col-6 col-md-3">
            <div :class="['card stat-card h-100', `stat-card--${kpi.variant}`, kpi.soon ? 'stat-card-mock' : '']">
                <div class="card-body d-flex align-items-center gap-3 p-3">
                    <div :class="`stat-icon stat-icon--${kpi.variant}`">
                        <i :class="kpi.icon"></i>
                    </div>
                    <div>
                        <div :class="['stat-value', kpi.soon ? 'stat-value-mock' : '']">
                            {{ kpi.soon ? '—' : kpi.value }}
                        </div>
                        <div class="stat-label">{{ kpi.label }}</div>
                        <span v-if="kpi.soon" class="stat-badge-soon">{{ t.kpi_coming_soon }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
