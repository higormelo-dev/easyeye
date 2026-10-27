<script setup>
import { computed, ref, watch, onBeforeUnmount } from 'vue';
import { useForm } from '@inertiajs/vue3';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import { requestJson, fill } from '@/Support/clinicalSafety.js';
import { latestRequest } from '@/Support/latestRequest.js';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
const props = defineProps({
    open: { type: Boolean, required: true },
    patient: { type: Object, default: null },
    labels: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['close', 'merged']);
const tt = (key) => props.labels[key] ?? '';
const { date } = useLocaleFormat();
const form = useForm({ target_id: null, confirm: true });
const sourceId = ref('');
const candidates = ref([]);
const loading = ref(false);
const error = ref('');
const loadFailed = ref(false);
const request = latestRequest();
let version = 0;
const groups = computed(() =>
    ['duplicate', 'homonym'].map((kind) => ({ kind, items: candidates.value.filter((item) => item.kind === kind) })),
);
const selected = computed(() => candidates.value.find((item) => item.id === sourceId.value));
function close() {
    if (!form.processing) emit('close');
}
async function loadCandidates() {
    if (!props.patient?.id || !props.open) return;
    const req = request.begin();
    loading.value = true;
    loadFailed.value = false;
    error.value = '';
    candidates.value = [];
    sourceId.value = '';
    try {
        const res = await requestJson(route('panel.patients.merge-candidates', props.patient.id), {
            signal: req.signal,
        });
        if (!req.current()) return;
        if (!res.ok) throw new Error();
        candidates.value = res.data.data ?? [];
    } catch {
        if (req.current()) {
            error.value = tt('error');
            loadFailed.value = true;
        }
    } finally {
        if (req.current()) loading.value = false;
    }
}
async function submit() {
    if (!selected.value || loading.value || form.processing || !props.open || !props.patient?.id) return;
    const mine = version;
    const source = selected.value.id;
    form.target_id = props.patient.id;
    form.confirm = true;
    form.processing = true;
    error.value = '';
    try {
        // O cadastro aberto é o destino mantido (§1/§13 do plano).
        // A rota recebe a origem; nunca inverter os dois ids.
        const res = await requestJson(route('panel.patients.merge', source), {
            method: 'POST',
            body: { target_id: form.target_id, confirm: true },
        });
        if (mine !== version) return;
        if (!res.ok) {
            error.value = res.data.message || res.data.errors?.target_id?.[0] || tt('error');
            return;
        }
        window.Swal?.fire({ icon: 'success', text: res.data.message });
        emit('merged', res.data);
        emit('close');
    } catch {
        if (mine === version) error.value = tt('error');
    } finally {
        if (mine === version) form.processing = false;
    }
}
watch(
    () => [props.open, props.patient?.id],
    () => {
        version += 1;
        request.cancel();
        sourceId.value = '';
        candidates.value = [];
        error.value = '';
        loading.value = false;
        loadFailed.value = false;
        form.processing = false;
        form.target_id = props.patient?.id ?? null;
        if (props.open) loadCandidates();
    },
    { immediate: true },
);
onBeforeUnmount(() => {
    version += 1;
    request.cancel();
});
</script>
<template>
    <CenteredModal density="default" :open="open" :close-label="tt('cancel')" @close="close">
        <template #header
            ><h5 class="mb-0">{{ tt('title') }}</h5></template
        >
        <form data-testid="patient-merge-modal" :aria-busy="loading || form.processing" @submit.prevent="submit">
            <p class="fw-semibold text-break">{{ patient?.code }} — {{ patient?.full_name }}</p>
            <p class="alert alert-warning">{{ fill(tt('hint'), { code: patient?.code }) }}</p>
            <p v-if="error" role="alert" class="alert alert-danger text-break">{{ error }}</p>
            <p v-if="loading" role="status">{{ tt('loading') }}</p>
            <button v-if="loadFailed" type="button" class="btn btn-outline-secondary mb-2" @click="loadCandidates">
                {{ tt('retry') }}
            </button>
            <div data-testid="patient-merge-candidates">
                <p v-if="!loading && !loadFailed && !candidates.length" class="text-muted">{{ tt('none') }}</p>
                <fieldset
                    v-for="group in groups.filter((group) => group.items.length)"
                    :key="group.kind"
                    :disabled="form.processing"
                    class="mb-3"
                >
                    <legend class="fs-6 fw-semibold">
                        {{ tt(group.kind === 'duplicate' ? 'duplicates' : 'homonyms') }}
                    </legend>
                    <label
                        v-for="candidate in group.items"
                        :key="candidate.id"
                        class="d-flex gap-2 align-items-start border rounded p-2 mb-2 text-break"
                    >
                        <input
                            v-model="sourceId"
                            class="form-check-input flex-shrink-0"
                            type="radio"
                            name="patient-merge-source"
                            :value="candidate.id"
                        />
                        <span
                            >{{ candidate.code }} — {{ candidate.full_name }} —
                            {{ candidate.birth_date ? date(candidate.birth_date) : tt('birth_date_unknown') }}</span
                        >
                    </label>
                </fieldset>
            </div>
            <p v-if="selected" data-testid="patient-merge-direction" class="small text-break">
                {{ fill(tt('direction'), { source: selected.code, target: patient?.code }) }}
            </p>
            <div class="d-flex flex-wrap gap-2 justify-content-end mt-3">
                <button type="button" class="btn btn-outline-secondary" :disabled="form.processing" @click="close">
                    {{ tt('cancel') }}
                </button>
                <button
                    type="submit"
                    data-testid="patient-merge-confirm"
                    class="btn btn-danger"
                    :disabled="!selected || loading || form.processing"
                >
                    {{ form.processing ? tt('processing') : tt('confirm') }}
                </button>
            </div>
        </form>
    </CenteredModal>
</template>
