<script setup>
import { computed } from 'vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

/** Aba "Histórico": cada mudança de estado do caso, quem fez e por quê. */
const props = defineProps({
    section: { type: Object, required: true },
    surgicalCase: { type: Object, required: true },
    t: { type: Object, required: true },
});

const { dateTime } = useLocaleFormat();
const ui = computed(() => props.t.ui?.history ?? {});
</script>

<template>
    <div>
        <p v-if="!section.entries.length" class="text-muted mb-0">{{ ui.empty }}</p>
        <ol v-else class="list-group list-group-flush">
            <li v-for="(entry, i) in section.entries" :key="i" class="list-group-item px-0" data-testid="history-entry">
                <div class="d-flex flex-wrap gap-2 align-items-baseline">
                    <span class="fw-medium">
                        <template v-if="entry.from">{{ entry.from }} → </template>{{ entry.to }}
                    </span>
                    <span class="text-muted small"
                        >{{ dateTime(entry.at) }}<template v-if="entry.by"> · {{ entry.by }}</template></span
                    >
                </div>
                <div v-if="entry.reason" class="small text-muted">{{ entry.reason }}</div>
            </li>
        </ol>
    </div>
</template>
