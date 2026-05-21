<script setup>
import { ref, computed, watch } from 'vue';

const props = defineProps({
    doctor: { type: String, default: 'tudo' },
    t:      { type: Object, required: true },
});

const emit = defineEmits(['schedule-from-waiting', 'update:count']);

const open    = ref(false);
const entries = ref([]);
const loading = ref(false);

const count = computed(() => entries.value.length);

watch(count, (v) => emit('update:count', v));

async function fetch() {
    loading.value = true;
    try {
        const params = props.doctor !== 'tudo' ? `?doctor_id=${props.doctor}` : '';
        const res = await window.fetch(route('panel.waiting-list.index') + params, {
            headers: { Accept: 'application/json' },
        });
        const data = await res.json();
        entries.value = data.data ?? [];
    } catch { /**/ }
    loading.value = false;
}

function toggle() {
    open.value = !open.value;
    if (open.value) fetch();
}

async function remove(entry) {
    entries.value = entries.value.filter(e => e.id !== entry.id);
    await window.fetch(`/panel/waiting-list/${entry.id}`, {
        method: 'DELETE',
        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
    });
}

async function move(index, dir) {
    const arr  = [...entries.value];
    const swap = index + dir;
    if (swap < 0 || swap >= arr.length) return;
    [arr[index], arr[swap]] = [arr[swap], arr[index]];
    entries.value = arr;

    await window.fetch(route('panel.waiting-list.reorder'), {
        method: 'PATCH',
        headers: {
            'Content-Type': 'application/json',
            'Accept':       'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify({ ids: arr.map(e => e.id) }),
    });
}

function scheduleFromWaiting(entry) {
    emit('schedule-from-waiting', entry);
}

defineExpose({ fetch, toggle, open, count });
</script>

<template>
    <div v-if="open" class="border border-warning rounded mb-3">

        <!-- Header -->
        <div class="d-flex align-items-center justify-content-between px-3 py-2 bg-warning rounded-top">
            <span class="fw-semibold small">
                <i class="fas fa-hourglass-half me-2"></i>{{ t.waiting_title }}
                <span class="badge bg-dark ms-2">{{ count }}</span>
            </span>
            <button type="button" class="btn-close" @click="open = false"></button>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="text-center py-3 text-muted small">
            <span class="spinner-border spinner-border-sm me-1"></span>{{ t.loading }}
        </div>

        <!-- Empty -->
        <p v-else-if="!loading && entries.length === 0"
           class="text-muted text-center py-3 mb-0 small">
            <i class="fas fa-check-circle me-1"></i>{{ t.waiting_empty }}
        </p>

        <!-- List -->
        <div v-else class="list-group list-group-flush rounded-bottom">
            <div v-for="(entry, index) in entries"
                 :key="entry.id"
                 class="list-group-item px-3 py-2">
                <div class="d-flex align-items-center gap-2">

                    <!-- Position badge -->
                    <span class="badge bg-secondary rounded-pill flex-shrink-0"
                          style="min-width:1.6rem;">
                        {{ index + 1 }}
                    </span>

                    <!-- Reorder buttons -->
                    <div class="d-flex flex-column flex-shrink-0" style="gap:0;">
                        <button type="button"
                                class="btn p-0 lh-1 border-0 text-muted"
                                style="font-size:.75rem;"
                                :disabled="index === 0"
                                @click="move(index, -1)">
                            <i class="fas fa-caret-up"></i>
                        </button>
                        <button type="button"
                                class="btn p-0 lh-1 border-0 text-muted"
                                style="font-size:.75rem;"
                                :disabled="index === entries.length - 1"
                                @click="move(index, 1)">
                            <i class="fas fa-caret-down"></i>
                        </button>
                    </div>

                    <!-- Info -->
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold text-truncate small">{{ entry.full_name }}</div>
                        <div class="text-muted" style="font-size:.75rem;">
                            <span>{{ entry.doctor_name }}</span>
                            <span v-if="entry.covenant_name"> &middot; {{ entry.covenant_name }}</span>
                            <span v-if="entry.visit_name"> &middot; {{ entry.visit_name }}</span>
                        </div>
                        <div v-if="entry.preferred_date_from" class="text-muted" style="font-size:.7rem;">
                            <i class="fas fa-calendar me-1"></i>
                            {{ entry.preferred_date_from }}
                            <span v-if="entry.preferred_date_until">
                                {{ t.waiting_until }} {{ entry.preferred_date_until }}
                            </span>
                        </div>
                        <p v-if="entry.notes"
                           class="mb-0 mt-1 text-muted fst-italic"
                           style="font-size:.7rem;">
                            {{ entry.notes }}
                        </p>
                    </div>

                    <!-- Actions -->
                    <div class="d-flex gap-1 flex-shrink-0">
                        <button type="button"
                                class="btn btn-sm btn-info"
                                :title="t.waiting_schedule_btn"
                                @click="scheduleFromWaiting(entry)">
                            <i class="fas fa-calendar-plus"></i>
                        </button>
                        <button type="button"
                                class="btn btn-sm btn-outline-danger"
                                :title="t.waiting_remove_btn"
                                @click="remove(entry)">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>

                </div>
            </div>
        </div>

    </div>
</template>
