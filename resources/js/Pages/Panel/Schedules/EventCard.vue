<script setup>
defineProps({
    item: { type: Object, required: true },
    t:    { type: Object, required: true },
});

const typeKeyMap = {
    meeting:     'event_meeting',
    maintenance: 'event_maintenance',
    personal:    'event_personal',
    training:    'event_training',
    other:       'event_other',
};
</script>

<template>
    <div class="card mb-2 border-0 shadow-sm"
         :style="{ borderLeft: `4px solid ${item.color} !important`, background: '#f8f9fa' }">
        <div class="card-body py-2 px-3">
            <div class="d-flex align-items-center gap-3">

                <!-- Time range -->
                <div class="text-center flex-shrink-0" style="min-width:48px;">
                    <div class="fw-bold" :style="{ fontSize: '.9rem', color: item.color }">
                        {{ item.starts_at_fmt }}
                    </div>
                    <div class="text-muted" style="font-size:.7rem;">{{ item.ends_at_fmt }}</div>
                </div>

                <!-- Icon -->
                <div class="flex-shrink-0 text-center" style="width:36px;">
                    <i class="fas fa-calendar-check fa-lg" :style="{ color: item.color, opacity: '.7' }"></i>
                </div>

                <!-- Content -->
                <div class="flex-grow-1 min-w-0">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="fw-semibold text-truncate" style="font-size:.9rem;">{{ item.title }}</span>
                        <span class="badge rounded-pill"
                              :style="{ background: item.color, fontSize: '.68rem', opacity: '.85' }">
                            {{ t[typeKeyMap[item.event_type]] ?? item.event_type }}
                        </span>
                    </div>
                    <div v-if="item.doctor_name" class="text-muted" style="font-size:.78rem;">
                        <i class="fas fa-user-md me-1"></i>{{ item.doctor_name }}
                    </div>
                    <div v-if="item.notes" class="text-muted fst-italic text-truncate" style="font-size:.75rem;">
                        {{ item.notes }}
                    </div>
                </div>

                <!-- Badge -->
                <div class="flex-shrink-0">
                    <span class="badge bg-secondary" style="font-size:.68rem;">{{ t.event_badge }}</span>
                </div>

            </div>
        </div>
    </div>
</template>
