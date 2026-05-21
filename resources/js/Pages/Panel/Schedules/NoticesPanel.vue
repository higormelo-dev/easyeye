<script setup>
import { ref, watch, onMounted } from 'vue';

defineProps({
    t: { type: Object, required: true },
});

const emit = defineEmits(['update:unread-count']);

const open        = ref(false);
const notices     = ref([]);
const unreadCount = ref(0);
const loading     = ref(false);
const showForm    = ref(false);
const saving      = ref(false);

const newContent = ref('');
const newUrgent  = ref(false);
const newExpires = ref('');

async function fetchNotices() {
    loading.value = true;
    try {
        const res  = await fetch(route('panel.notices.index'), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await res.json();
        notices.value     = data.data ?? [];
        unreadCount.value = data.unread_count ?? 0;
    } catch { /**/ }
    loading.value = false;
}

function toggle() {
    open.value = !open.value;
    if (open.value && notices.value.length === 0) fetchNotices();
}

async function markRead(notice) {
    if (notice.is_read) return;
    notice.is_read    = true;
    unreadCount.value = Math.max(0, unreadCount.value - 1);
    await fetch(`/panel/notices/${notice.id}/read`, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
    });
}

async function deleteNotice(notice) {
    notices.value = notices.value.filter(n => n.id !== notice.id);
    if (!notice.is_read) unreadCount.value = Math.max(0, unreadCount.value - 1);
    await fetch(`/panel/notices/${notice.id}`, {
        method: 'DELETE',
        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
    });
}

async function saveNotice() {
    if (!newContent.value.trim()) return;
    saving.value = true;
    try {
        await fetch(route('panel.notices.store'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept':       'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify({
                content:    newContent.value,
                priority:   newUrgent.value ? 1 : 0,
                expires_at: newExpires.value || null,
            }),
        });
        newContent.value = '';
        newUrgent.value  = false;
        newExpires.value = '';
        showForm.value   = false;
        await fetchNotices();
    } catch { /**/ }
    saving.value = false;
}

watch(unreadCount, (v) => emit('update:unread-count', v));

onMounted(fetchNotices);

defineExpose({ toggle, open, unreadCount });
</script>

<template>
    <div v-if="open" class="border border-primary rounded mb-3">

        <!-- Header -->
        <div class="d-flex align-items-center justify-content-between px-3 py-2 bg-primary text-white rounded-top">
            <span class="fw-semibold small">
                <i class="fas fa-bullhorn me-1"></i>{{ t.notices_title }}
                <span v-if="unreadCount > 0"
                      class="badge bg-warning text-dark ms-1"
                      style="font-size:.65rem;">
                    {{ unreadCount }} {{ t.notices_unread }}
                </span>
            </span>
            <div class="d-flex gap-2">
                <button type="button"
                        class="btn btn-sm btn-light py-0"
                        @click="showForm = !showForm">
                    <i class="fas fa-plus me-1"></i>
                    <span style="font-size:.8rem;">{{ t.notices_new }}</span>
                </button>
                <button type="button" class="btn-close btn-close-white" @click="open = false"></button>
            </div>
        </div>

        <!-- New notice form -->
        <div v-if="showForm" class="p-3 border-bottom bg-light">
            <textarea
                v-model="newContent"
                class="form-control form-control-sm mb-2"
                rows="2"
                :placeholder="t.notices_placeholder"
                maxlength="1000"
            ></textarea>
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <div class="form-check form-check-inline mb-0">
                    <input id="notice-urgent" v-model="newUrgent" type="checkbox" class="form-check-input">
                    <label for="notice-urgent" class="form-check-label small text-danger fw-semibold">
                        <i class="fas fa-exclamation-triangle me-1"></i>{{ t.notices_urgent }}
                    </label>
                </div>
                <div class="d-flex align-items-center gap-1">
                    <label class="small text-muted mb-0">{{ t.notices_expires }}</label>
                    <input v-model="newExpires" type="date" class="form-control form-control-sm" style="max-width:150px;">
                </div>
                <div class="ms-auto d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary"
                            @click="showForm = false">{{ t.notices_cancel }}</button>
                    <button type="button"
                            class="btn btn-sm btn-primary"
                            :disabled="saving || !newContent.trim()"
                            @click="saveNotice">
                        <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
                        {{ t.notices_publish }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="text-center py-3 text-muted small">
            <span class="spinner-border spinner-border-sm me-1"></span>{{ t.notices_loading }}
        </div>

        <!-- Empty -->
        <p v-else-if="!loading && notices.length === 0"
           class="text-muted text-center py-3 mb-0 small">
            <i class="fas fa-check-circle me-1"></i>{{ t.notices_empty }}
        </p>

        <!-- List -->
        <div class="list-group list-group-flush rounded-bottom" style="max-height:300px;overflow-y:auto;">
            <div v-for="notice in notices"
                 :key="notice.id"
                 class="list-group-item px-3 py-2"
                 :class="{
                     'border-start border-danger border-3': notice.is_urgent,
                     'bg-light': !notice.is_read,
                 }">
                <div class="d-flex gap-2 align-items-start">

                    <!-- Urgency / pin icon -->
                    <div class="flex-shrink-0 pt-1" style="width:20px;">
                        <i v-if="notice.is_urgent"
                           class="fas fa-exclamation-triangle text-danger"
                           :title="t.notices_urgent"></i>
                        <i v-else-if="notice.pinned"
                           class="fas fa-thumbtack text-primary"></i>
                    </div>

                    <!-- Content -->
                    <div class="flex-grow-1 min-w-0">
                        <p class="mb-1 small" style="white-space:pre-wrap;">{{ notice.content }}</p>
                        <div class="text-muted" style="font-size:.72rem;">
                            {{ notice.author }} &middot; {{ notice.created_at }}
                            <span v-if="notice.expires_at" class="ms-1">
                                &middot; {{ t.notices_expires_at }} {{ notice.expires_at }}
                            </span>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex-shrink-0 d-flex gap-1">
                        <button v-if="!notice.is_read"
                                type="button"
                                class="btn btn-xs btn-outline-success py-0 px-1"
                                style="font-size:.72rem;"
                                @click="markRead(notice)">
                            <i class="fas fa-check"></i> {{ t.notices_read_btn }}
                        </button>
                        <span v-else class="text-success" style="font-size:.72rem;padding:.1rem .25rem;">
                            <i class="fas fa-check-double"></i>
                        </span>
                        <button type="button"
                                class="btn btn-xs btn-outline-danger py-0 px-1"
                                style="font-size:.72rem;"
                                @click="deleteNotice(notice)">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>

                </div>
            </div>
        </div>

    </div>
</template>
