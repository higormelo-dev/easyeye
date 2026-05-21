<script setup>
import { ref, computed, watch } from 'vue';

const props = defineProps({
    modelValue: { type: String, default: '' }, // YYYY-MM-DD
    locale:     { type: String, default: 'pt-BR' },
});

const emit = defineEmits(['update:modelValue']);

const today = new Date().toISOString().substring(0, 10);

function parseDate(str) {
    if (!str) return new Date();
    return new Date(str + 'T12:00:00');
}

const viewYear  = ref(parseDate(props.modelValue).getFullYear());
const viewMonth = ref(parseDate(props.modelValue).getMonth()); // 0-based

watch(() => props.modelValue, (v) => {
    if (!v) return;
    const d = parseDate(v);
    viewYear.value  = d.getFullYear();
    viewMonth.value = d.getMonth();
});

function prevMonth() {
    if (viewMonth.value === 0) { viewMonth.value = 11; viewYear.value--; }
    else viewMonth.value--;
}
function nextMonth() {
    if (viewMonth.value === 11) { viewMonth.value = 0; viewYear.value++; }
    else viewMonth.value++;
}

const monthLabel = computed(() =>
    new Date(viewYear.value, viewMonth.value, 1)
        .toLocaleDateString(props.locale, { month: 'long' })
        .replace(/^\w/, c => c.toUpperCase())
);

// Abbreviations Dom Seg Ter Qua Qui Sex Sáb anchored on 2023-01-01 (Sunday)
const dayAbbrevs = computed(() =>
    Array.from({ length: 7 }, (_, i) =>
        new Date(2023, 0, 1 + i)
            .toLocaleDateString(props.locale, { weekday: 'short' })
            .replace(/\.$/, '')
            .substring(0, 3)
            .replace(/^\w/, c => c.toUpperCase())
    )
);

// Grid: leading empty cells + day cells
const cells = computed(() => {
    const first = new Date(viewYear.value, viewMonth.value, 1);
    const daysInMonth = new Date(viewYear.value, viewMonth.value + 1, 0).getDate();
    const startDow = first.getDay(); // 0=Sun

    const grid = [];
    for (let i = 0; i < startDow; i++) grid.push(null);
    for (let d = 1; d <= daysInMonth; d++) {
        const month = String(viewMonth.value + 1).padStart(2, '0');
        const day   = String(d).padStart(2, '0');
        grid.push(`${viewYear.value}-${month}-${day}`);
    }
    // Pad to full weeks
    while (grid.length % 7 !== 0) grid.push(null);
    return grid;
});

function select(dateStr) {
    if (!dateStr) return;
    emit('update:modelValue', dateStr);
}
</script>

<template>
    <div class="mini-cal">
        <!-- Header -->
        <div class="mini-cal-header">
            <button type="button" class="mini-cal-nav" @click="prevMonth">&#8249;</button>
            <span class="mini-cal-title">{{ monthLabel }} {{ viewYear }}</span>
            <button type="button" class="mini-cal-nav" @click="nextMonth">&#8250;</button>
        </div>

        <!-- Day abbreviation row -->
        <div class="mini-cal-grid">
            <div v-for="abbr in dayAbbrevs" :key="abbr" class="mini-cal-dow">{{ abbr }}</div>

            <!-- Day cells -->
            <div
                v-for="(cell, i) in cells"
                :key="i"
                class="mini-cal-cell"
                :class="{
                    'is-today':    cell === today,
                    'is-selected': cell === modelValue && cell !== today,
                    'is-empty':    cell === null,
                }"
                @click="select(cell)"
            >
                <span v-if="cell">{{ Number(cell.substring(8)) }}</span>
            </div>
        </div>
    </div>
</template>

<style scoped>
.mini-cal {
    user-select: none;
    font-size: .8rem;
}

.mini-cal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: .5rem;
}

.mini-cal-title {
    font-weight: 700;
    font-size: .82rem;
    text-transform: capitalize;
}

.mini-cal-nav {
    background: none;
    border: none;
    padding: 0 .3rem;
    font-size: 1.2rem;
    line-height: 1;
    cursor: pointer;
    color: #555;
}
.mini-cal-nav:hover { color: #000; }

.mini-cal-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 2px;
}

.mini-cal-dow {
    text-align: center;
    font-size: .68rem;
    font-weight: 700;
    color: #888;
    padding: .15rem 0 .3rem;
}

.mini-cal-cell {
    display: flex;
    align-items: center;
    justify-content: center;
    aspect-ratio: 1;
    border-radius: 50%;
    cursor: pointer;
    font-size: .78rem;
    color: #333;
    transition: background .15s;
}
.mini-cal-cell:not(.is-empty):hover {
    background: #e9ecef;
}
.mini-cal-cell.is-empty {
    cursor: default;
}
.mini-cal-cell.is-today {
    background: #dc3545;
    color: #fff;
    font-weight: 700;
}
.mini-cal-cell.is-today:hover {
    background: #bb2d3b;
}
.mini-cal-cell.is-selected {
    background: #adb5bd;
    color: #fff;
    font-weight: 600;
}
.mini-cal-cell.is-selected:hover {
    background: #6c757d;
}
</style>
