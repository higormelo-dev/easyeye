<script setup>
import { computed } from 'vue';
import { resolveEventPalette } from '@/Support/eventPalette.js';
const props = defineProps({
    item: { type: Object, required: true },
    t: { type: Object, required: true },
});

const palette = computed(() =>
    resolveEventPalette({ color: props.item.type === 'block' ? '#4b5563' : props.item.color }),
);

const typeKeyMap = {
    meeting: 'event_meeting',
    maintenance: 'event_maintenance',
    personal: 'event_personal',
    training: 'event_training',
    other: 'event_other',
};
</script>

<template>
    <div
        class="card mb-2 border-0 shadow-sm event-card"
        :class="{ 'event-card--block': item.type === 'block' }"
        :style="{ borderLeft: `4px solid ${palette.backgroundColor} !important` }"
        :data-row-type="item.type"
    >
        <div class="card-body py-2 px-3">
            <div class="d-flex align-items-center gap-3">
                <!-- Time range -->
                <div class="text-center flex-shrink-0" style="min-width: 48px">
                    <div class="fw-bold" :style="{ fontSize: '.9rem', color: 'var(--bs-body-color)' }">
                        {{ item.starts_at_fmt }}
                    </div>
                    <div class="text-muted" style="font-size: 0.7rem">{{ item.ends_at_fmt }}</div>
                </div>

                <!-- Icon -->
                <div class="flex-shrink-0 text-center" style="width: 36px">
                    <i
                        class="fa-lg"
                        :class="item.type === 'block' ? 'ti ti-lock fs-4' : 'fas fa-calendar-check'"
                        :style="{ color: palette.backgroundColor }"
                        aria-hidden="true"
                    ></i>
                </div>

                <!-- Content -->
                <div class="flex-grow-1 min-w-0">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="fw-semibold text-truncate" style="font-size: 0.9rem">{{ item.title }}</span>
                        <span
                            class="badge rounded-pill"
                            :style="{
                                background: palette.backgroundColor,
                                color: palette.textColor,
                                fontSize: '.68rem',
                            }"
                        >
                            {{ item.type_label ?? t[typeKeyMap[item.event_type]] ?? item.event_type }}
                        </span>
                    </div>
                    <div v-if="item.doctor_name" class="text-muted" style="font-size: 0.78rem">
                        <i class="fas fa-user-md me-1"></i>{{ item.doctor_name }}
                    </div>
                    <div v-if="item.resource_name" class="text-muted" style="font-size: 0.78rem">
                        <i class="ti ti-door me-1"></i>{{ item.resource_name }}
                    </div>
                    <div v-if="item.notes" class="text-muted fst-italic text-truncate" style="font-size: 0.75rem">
                        {{ item.notes }}
                    </div>
                </div>

                <!-- Badge -->
                <div class="flex-shrink-0">
                    <span class="badge bg-secondary" style="font-size: 0.68rem">{{
                        item.type === 'block' ? (t.block_badge ?? 'Bloqueio') : t.event_badge
                    }}</span>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.event-card {
    background: var(--bs-tertiary-bg, #f8f9fa);
}

.event-card--block {
    background-image: repeating-linear-gradient(
        135deg,
        transparent 0,
        transparent 10px,
        rgba(108, 117, 125, 0.07) 10px,
        rgba(108, 117, 125, 0.07) 20px
    );
}
</style>
