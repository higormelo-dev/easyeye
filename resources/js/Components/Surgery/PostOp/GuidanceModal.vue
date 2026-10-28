<script setup>
import { computed, ref, watch } from 'vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import TinyMceEditor from '@/Components/Panel/TinyMceEditor.vue';
import { firstError, postOpRequest } from './usePostOpApi.js';

/**
 * Colírios (estruturados) + orientações pós-operatórias (texto editável) →
 * documento congelado. Conflito de alergia volta do servidor (422) com a alergia
 * e a reação; o médico só segue com um motivo, que fica no documento.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    section: { type: Object, required: true },
    eyeOptions: { type: Array, default: () => [] },
    t: { type: Object, required: true },
});

const emit = defineEmits(['close', 'saved']);

const ui = computed(() => props.t.ui ?? {});
const saving = ref(false);
const error = ref('');
const conflicts = ref([]);
const reason = ref('');
const html = ref('');
const drops = ref([]);
const search = ref('');
const found = ref([]);
let timer = null;

const blankDrop = () => ({
    name: '',
    medicine_id: null,
    posology: '',
    duration: '',
    taper: '',
    eye: props.eyeOptions[0]?.value ?? 'od',
});

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        html.value = props.section.guidance_defaults?.html ?? '';
        drops.value = [blankDrop()];
        reason.value = '';
        conflicts.value = [];
        error.value = '';
        search.value = '';
        found.value = [];
    },
);

function onSearch() {
    clearTimeout(timer);
    if (search.value.trim().length < 2) {
        found.value = [];
        return;
    }
    timer = setTimeout(async () => {
        const res = await fetch(`${props.section.urls.medicine_search}?q=${encodeURIComponent(search.value.trim())}`, {
            headers: { Accept: 'application/json' },
        });
        found.value = res.ok ? await res.json() : [];
    }, 300);
}

function pick(medicine) {
    const last = drops.value[drops.value.length - 1];
    const target = last && !last.name ? last : drops.value[drops.value.push(blankDrop()) - 1];
    target.name = medicine.name;
    target.medicine_id = medicine.id;
    target.posology = [medicine.dosage, medicine.frequency].filter(Boolean).join(' · ') || target.posology;
    target.duration = medicine.duration ?? target.duration;
    search.value = '';
    found.value = [];
}

async function submit() {
    if (saving.value) return;
    saving.value = true;
    error.value = '';

    const result = await postOpRequest('POST', props.section.urls.guidance_store, {
        guidance_html: html.value,
        eyedrops: drops.value.filter((d) => d.name || d.posology),
        allergy_override_reason: reason.value || null,
    });

    saving.value = false;

    if (result.ok) {
        emit('saved', result.data);
        emit('close');
        return;
    }

    conflicts.value = result.data?.allergy_conflicts ?? [];
    error.value = firstError(result, ui.value.error_generic);
}
</script>

<template>
    <CenteredModal :open="open" size="xl" @close="emit('close')">
        <template #header>
            <h5 class="mb-0">{{ ui.guidance_title }}</h5>
        </template>

        <div class="row g-3">
            <div class="col-12">
                <label class="form-label" for="g-search">{{ ui.guidance_search }}</label>
                <input
                    id="g-search"
                    v-model="search"
                    type="search"
                    class="form-control"
                    autocomplete="off"
                    @input="onSearch"
                />
                <ul v-if="found.length" class="list-group mt-1" :aria-label="ui.guidance_search">
                    <li v-for="m in found" :key="m.id" class="list-group-item p-0">
                        <button
                            type="button"
                            class="btn btn-link text-start text-decoration-none w-100 px-3 py-2"
                            @click="pick(m)"
                        >
                            {{ m.name }} <small class="text-muted">{{ m.active_ingredient }}</small>
                        </button>
                    </li>
                </ul>
            </div>

            <div class="col-12">
                <h6 class="mb-2">{{ ui.guidance_eyedrops }}</h6>
                <div v-for="(d, i) in drops" :key="i" class="row g-2 mb-2 align-items-end" data-testid="g-drop">
                    <div class="col-md-3">
                        <label class="form-label small" :for="`g-n-${i}`">{{ ui.g_name }}</label>
                        <input :id="`g-n-${i}`" v-model="d.name" class="form-control" maxlength="150" />
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small" :for="`g-p-${i}`">{{ ui.g_posology }}</label>
                        <input :id="`g-p-${i}`" v-model="d.posology" class="form-control" maxlength="255" />
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small" :for="`g-d-${i}`">{{ ui.g_duration }}</label>
                        <input :id="`g-d-${i}`" v-model="d.duration" class="form-control" maxlength="120" />
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small" :for="`g-t-${i}`">{{ ui.g_taper }}</label>
                        <input :id="`g-t-${i}`" v-model="d.taper" class="form-control" maxlength="255" />
                    </div>
                    <div class="col-md-1">
                        <label class="form-label small" :for="`g-e-${i}`">{{ ui.f_eye }}</label>
                        <select :id="`g-e-${i}`" v-model="d.eye" class="form-select">
                            <option v-for="e in eyeOptions" :key="e.value" :value="e.value">
                                {{ e.short ?? e.value }}
                            </option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <button
                            type="button"
                            class="btn btn-outline-danger w-100"
                            :title="ui.g_remove"
                            :aria-label="ui.g_remove"
                            @click="drops.splice(i, 1)"
                        >
                            <i class="ti ti-trash" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary" @click="drops.push(blankDrop())">
                    <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ ui.g_add }}
                </button>
            </div>

            <div class="col-12">
                <h6 class="mb-2">{{ ui.guidance_orientations }}</h6>
                <TinyMceEditor v-model="html" :disabled="saving" />
            </div>

            <div v-if="conflicts.length" class="col-12">
                <div class="alert alert-danger" role="alert" data-testid="g-conflicts">
                    <strong class="d-block mb-1"
                        ><i class="ti ti-alert-octagon me-1" aria-hidden="true"></i>{{ ui.allergy_title }}</strong
                    >
                    <ul class="mb-2">
                        <li v-for="(c, i) in conflicts" :key="i">
                            {{ c.message ?? c.label ?? c.substance ?? JSON.stringify(c) }}
                        </li>
                    </ul>
                    <label class="form-label" for="g-reason">{{ ui.allergy_reason }}</label>
                    <input id="g-reason" v-model="reason" class="form-control" maxlength="500" />
                    <small class="text-muted">{{ ui.allergy_hint }}</small>
                </div>
            </div>
            <div v-if="error && !conflicts.length" class="col-12">
                <div class="alert alert-danger mb-0" role="alert">{{ error }}</div>
            </div>
        </div>

        <template #footer>
            <button type="button" class="btn btn-outline-secondary" @click="emit('close')">{{ ui.cancel }}</button>
            <button type="button" class="btn btn-primary" :disabled="saving" data-testid="g-issue" @click="submit">
                <i class="ti ti-file-text me-1" aria-hidden="true"></i>{{ ui.guidance_issue }}
            </button>
        </template>
    </CenteredModal>
</template>
