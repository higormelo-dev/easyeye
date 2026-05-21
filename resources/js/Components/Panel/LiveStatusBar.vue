<script setup>
import { computed } from 'vue';

const props = defineProps({
    isRefreshing: { type: Boolean, default: false },
    lastUpdated:  { type: Date,    default: () => new Date() },
    t:            { type: Object,  required: true },
});

const emit = defineEmits(['refresh']);

const lastUpdatedTime = computed(() => {
    const locale = window.sessionLocale ?? 'pt-BR';
    return props.lastUpdated.toLocaleTimeString(locale, {
        hour:   '2-digit',
        minute: '2-digit',
        second: '2-digit',
    });
});
</script>

<template>
    <div class="db-live-bar d-flex align-items-center gap-2 mb-3">
        <span class="badge db-live-badge d-flex align-items-center gap-1">
            <span class="db-live-dot"></span>
            {{ t.live_label }}
        </span>
        <span class="text-muted" style="font-size:.78rem;">
            <template v-if="isRefreshing">
                <i class="ti ti-loader-2 db-spin me-1"></i>
                {{ t.live_refreshing }}
            </template>
            <template v-else>
                {{ t.last_updated_at }} {{ lastUpdatedTime }}
            </template>
        </span>
        <button
            class="btn btn-xs btn-outline-secondary ms-auto"
            :disabled="isRefreshing"
            @click="emit('refresh')"
        >
            <i class="ti ti-refresh me-1" :class="{ 'db-spin': isRefreshing }"></i>
            {{ t.btn_refresh }}
        </button>
    </div>
</template>
