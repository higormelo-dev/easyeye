<script setup>
import { ref, watch, onBeforeUnmount } from 'vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import { requestJson } from '@/Support/clinicalSafety.js';
import { latestRequest } from '@/Support/latestRequest.js';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
const props = defineProps({
    open: { type: Boolean, required: true },
    patient: { type: Object, default: null },
    url: { type: String, default: '' },
    t: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['close']);
const tt = (key, fallback = '') => props.t[key] ?? fallback;
const { dateTime } = useLocaleFormat();
const entries = ref([]);
const meta = ref({ current_page: 0, last_page: 1 });
const loading = ref(false);
const error = ref('');
const request = latestRequest();
function valueText(value) {
    if (value === null || value === undefined || value === '') return tt('audit_value_empty');
    if (typeof value === 'boolean') return tt(value ? 'audit_value_yes' : 'audit_value_no');
    return String(value);
}
async function load(page = 1) {
    if (!props.open || !props.patient?.id || !props.url || loading.value) return;
    const req = request.begin();
    loading.value = true;
    error.value = '';
    const url = props.url.replace('__ID__', encodeURIComponent(props.patient.id));
    try {
        const res = await requestJson(`${url}${url.includes('?') ? '&' : '?'}page=${page}`, { signal: req.signal });
        if (!req.current()) return;
        if (!res.ok) throw new Error();
        entries.value = page === 1 ? (res.data.entries ?? []) : [...entries.value, ...(res.data.entries ?? [])];
        meta.value = res.data.meta ?? { current_page: page, last_page: page };
    } catch {
        if (req.current()) error.value = tt('audit_error');
    } finally {
        if (req.current()) loading.value = false;
    }
}
watch(
    () => [props.open, props.patient?.id, props.url],
    () => {
        request.cancel();
        entries.value = [];
        meta.value = { current_page: 0, last_page: 1 };
        loading.value = false;
        error.value = '';
        if (props.open) load();
    },
    { immediate: true },
);
onBeforeUnmount(request.cancel);
</script>
<template>
    <CenteredModal :open="open" :close-label="tt('close')" @close="emit('close')">
        <template #header>
            <h5 class="mb-1">{{ tt('audit_title') }}</h5>
            <div class="small text-muted text-break">
                {{ patient?.code }} — {{ patient?.person?.full_name ?? patient?.full_name }}
            </div>
        </template>
        <div data-testid="patient-audit-modal" :aria-busy="loading">
            <p v-if="error" role="alert" class="alert alert-danger">{{ error }}</p>
            <p v-if="!loading && !error && !entries.length" data-testid="patient-audit-empty" class="text-muted">
                {{ tt('audit_empty') }}
            </p>
            <article
                v-for="entry in entries"
                :key="entry.id"
                data-testid="patient-audit-entry"
                class="border rounded p-3 mb-2 text-break"
            >
                <div class="d-flex align-items-center flex-wrap gap-2 mb-2">
                    <span class="badge bg-secondary">{{ tt('audit_event_' + entry.event, entry.event) }}</span>
                    <time :datetime="entry.at">{{ dateTime(entry.at) }}</time>
                </div>
                <p class="small mb-2">
                    <strong>{{ tt('audit_target') }}:</strong> {{ tt('audit_target_' + entry.target, entry.target) }}
                    {{ entry.target_code }}
                    <br />
                    <strong>{{ tt('audit_who') }}:</strong>
                    {{
                        entry.actor_name ||
                        tt(entry.actor_kind === 'integrator' ? 'audit_actor_integrator' : 'audit_actor_system')
                    }}
                </p>
                <ul v-if="entry.changes?.length" class="small mb-0 ps-3">
                    <li v-for="(change, index) in entry.changes" :key="index">
                        <strong>{{ tt('audit_field_' + change.field, change.field) }}:</strong>
                        <span v-if="change.redacted">{{ tt('audit_value_hidden') }}</span>
                        <span v-else>{{ valueText(change.old) }} → {{ valueText(change.new) }}</span>
                    </li>
                </ul>
            </article>
            <p v-if="loading" role="status" class="text-muted">{{ tt('audit_loading') }}</p>
            <button
                v-if="meta.current_page < meta.last_page"
                type="button"
                data-testid="patient-audit-more"
                class="btn btn-outline-secondary"
                :disabled="loading"
                @click="load(meta.current_page + 1)"
            >
                {{ tt('audit_load_more') }}
            </button>
        </div>
    </CenteredModal>
</template>
