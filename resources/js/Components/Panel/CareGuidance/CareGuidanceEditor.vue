<script setup>
import { computed, reactive, ref, useId, watch } from 'vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import { makeTx } from '@/Support/recallRule.js';

/**
 * Editor de modelo de orientação pós-procedimento (Fase 6 / S14) — Configurações › Orientações pós-procedimento.
 * Título, procedimento do catálogo (opcional), local (texto livre), texto, esquema de colírios e PDFs anexos (só com o
 * modelo já salvo: o PDF pertence a um modelo existente). O servidor valida tudo de novo (PDF de verdade, 5 MB, 5 por
 * modelo, tenant); aqui a tela só guia.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    template: { type: Object, default: null },
    procedureSearchUrl: { type: String, default: '' },
    maxDrops: { type: Number, default: 10 },
    maxAttachments: { type: Number, default: 5 },
    busy: { type: Boolean, default: false },
    attachBusy: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
    t: { type: Object, required: true },
});

const emit = defineEmits(['close', 'save', 'attach', 'detach']);

const tx = makeTx(() => props.t);
const uid = useId();

let seq = 0;
const form = reactive({
    title: '',
    procedure_id: null,
    procedure_name: '',
    location_label: '',
    body: '',
    active: true,
    eye_drops: [],
});
const fileInput = ref(null);

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        const tpl = props.template;
        form.title = tpl?.title ?? '';
        form.procedure_id = tpl?.procedure_id ?? null;
        form.procedure_name = tpl?.procedure_name ?? '';
        form.location_label = tpl?.location_label ?? '';
        form.body = tpl?.body ?? '';
        form.active = tpl ? Boolean(tpl.active) : true;
        form.eye_drops = (tpl?.eye_drops ?? []).map((d) => ({ key: `d${++seq}`, ...d }));
        query.value = '';
        results.value = [];
        resultsOpen.value = false;
    },
);

// ── Procedimento (busca no catálogo) ──────────────────────────────────────
const query = ref('');
const results = ref([]);
const resultsOpen = ref(false);
let timer = null;

function onSearch() {
    clearTimeout(timer);
    timer = setTimeout(async () => {
        const q = query.value.trim();
        if (q.length < 2 || !props.procedureSearchUrl) {
            results.value = [];
            resultsOpen.value = false;

            return;
        }
        try {
            const res = await fetch(`${props.procedureSearchUrl}?q=${encodeURIComponent(q)}`, {
                headers: { Accept: 'application/json' },
            });
            results.value = res.ok ? await res.json() : [];
        } catch {
            results.value = [];
        }
        resultsOpen.value = results.value.length > 0;
    }, 250);
}

function pick(proc) {
    form.procedure_id = proc.id;
    form.procedure_name = proc.name;
    query.value = '';
    results.value = [];
    resultsOpen.value = false;
}

function clearProcedure() {
    form.procedure_id = null;
    form.procedure_name = '';
}

// ── Colírios ──────────────────────────────────────────────────────────────
function addDrop() {
    if (form.eye_drops.length >= props.maxDrops) return;
    form.eye_drops.push({ key: `d${++seq}`, name: '', how: '', times_per_day: '', days: '' });
}

function removeDrop(index) {
    form.eye_drops.splice(index, 1);
}

// ── PDFs ──────────────────────────────────────────────────────────────────
const attachments = computed(() => props.template?.attachments ?? []);
const canAttach = computed(() => attachments.value.length < props.maxAttachments);

function onFile(event) {
    const file = event.target.files?.[0];
    if (file) emit('attach', file);
    if (fileInput.value) fileInput.value.value = '';
}

const sizeLabel = (bytes) =>
    bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
const fieldError = (name) => props.errors?.[name]?.[0] ?? '';

function submit() {
    emit('save', {
        title: form.title.trim(),
        procedure_id: form.procedure_id,
        location_label: form.location_label.trim() || null,
        body: form.body,
        active: form.active,
        eye_drops: form.eye_drops
            .filter((d) => String(d.name ?? '').trim())
            .map((d) => ({
                name: String(d.name).trim(),
                how: String(d.how ?? '').trim() || null,
                times_per_day: d.times_per_day === '' || d.times_per_day == null ? null : Number(d.times_per_day),
                days: d.days === '' || d.days == null ? null : Number(d.days),
            })),
    });
}
</script>

<template>
    <CenteredModal :open="open" size="lg" @close="$emit('close')">
        <template #header>
            <h5 class="mb-0 fw-semibold">
                <i class="ti ti-clipboard-heart me-2 text-primary" aria-hidden="true"></i
                >{{ template ? t.settings.edit : t.settings.new }}
            </h5>
        </template>

        <form :id="`${uid}-form`" novalidate data-testid="guidance-editor" @submit.prevent="submit">
            <div class="row g-2 mb-2">
                <div class="col-12 col-md-8">
                    <label class="form-label small mb-1" :for="`${uid}-title`"
                        >{{ t.settings.fields.title }} <span class="text-danger" aria-hidden="true">*</span></label
                    >
                    <input
                        :id="`${uid}-title`"
                        v-model="form.title"
                        type="text"
                        maxlength="160"
                        required
                        class="form-control form-control-sm"
                        :class="{ 'is-invalid': fieldError('title') }"
                        data-testid="guidance-title"
                    />
                    <div v-if="fieldError('title')" class="invalid-feedback">{{ fieldError('title') }}</div>
                </div>
                <div class="col-12 col-md-4 d-flex align-items-end">
                    <div class="form-check form-switch mb-1">
                        <input :id="`${uid}-active`" v-model="form.active" class="form-check-input" type="checkbox" />
                        <label class="form-check-label" :for="`${uid}-active`">{{ t.settings.active }}</label>
                    </div>
                </div>

                <div class="col-12 col-md-6 position-relative">
                    <label class="form-label small mb-1" :for="`${uid}-proc`">{{ t.settings.fields.procedure }}</label>
                    <div
                        v-if="form.procedure_id"
                        class="d-flex align-items-center gap-2"
                        data-testid="guidance-procedure"
                    >
                        <span class="badge bg-primary-subtle text-primary-emphasis">{{ form.procedure_name }}</span>
                        <button type="button" class="btn btn-link btn-sm p-0" @click="clearProcedure">
                            {{ t.settings.any_procedure }}
                        </button>
                    </div>
                    <input
                        v-else
                        :id="`${uid}-proc`"
                        v-model="query"
                        type="text"
                        autocomplete="off"
                        class="form-control form-control-sm"
                        :placeholder="t.settings.any_procedure"
                        aria-describedby="guidance-proc-hint"
                        role="combobox"
                        :aria-expanded="resultsOpen ? 'true' : 'false'"
                        @input="onSearch"
                    />
                    <ul
                        v-if="resultsOpen"
                        class="list-group shadow-sm position-absolute w-100"
                        style="z-index: 1080"
                        role="listbox"
                    >
                        <li
                            v-for="proc in results"
                            :key="proc.id"
                            class="list-group-item list-group-item-action py-1 px-2 small"
                            role="option"
                            tabindex="0"
                            @mousedown.prevent="pick(proc)"
                            @keydown.enter.prevent="pick(proc)"
                        >
                            <span class="fw-semibold">{{ proc.name }}</span>
                            <span v-if="proc.code" class="text-body-secondary ms-1">({{ proc.code }})</span>
                        </li>
                    </ul>
                    <div id="guidance-proc-hint" class="form-text">{{ t.settings.fields.procedure_hint }}</div>
                    <div v-if="fieldError('procedure_id')" class="invalid-feedback d-block">
                        {{ fieldError('procedure_id') }}
                    </div>
                </div>

                <div class="col-12 col-md-6">
                    <label class="form-label small mb-1" :for="`${uid}-place`">{{ t.settings.fields.location }}</label>
                    <input
                        :id="`${uid}-place`"
                        v-model="form.location_label"
                        type="text"
                        maxlength="160"
                        class="form-control form-control-sm"
                        data-testid="guidance-location"
                    />
                    <div class="form-text">{{ t.settings.fields.location_hint }}</div>
                </div>

                <div class="col-12">
                    <label class="form-label small mb-1" :for="`${uid}-body`"
                        >{{ t.settings.fields.body }} <span class="text-danger" aria-hidden="true">*</span></label
                    >
                    <textarea
                        :id="`${uid}-body`"
                        v-model="form.body"
                        rows="7"
                        maxlength="8000"
                        class="form-control form-control-sm"
                        :class="{ 'is-invalid': fieldError('body') }"
                        data-testid="guidance-body"
                    ></textarea>
                    <div v-if="fieldError('body')" class="invalid-feedback">{{ fieldError('body') }}</div>
                    <div v-else class="form-text">{{ t.settings.fields.body_hint }}</div>
                </div>
            </div>

            <fieldset class="mb-2" data-testid="guidance-drops">
                <legend class="form-label small mb-1 d-flex justify-content-between w-100">
                    <span>{{ t.settings.fields.eye_drops }}</span>
                    <span class="text-body-secondary">{{ form.eye_drops.length }}/{{ maxDrops }}</span>
                </legend>
                <div v-for="(drop, index) in form.eye_drops" :key="drop.key" class="row g-1 mb-1 align-items-end">
                    <div class="col-12 col-md-4">
                        <label class="visually-hidden" :for="`${uid}-dn-${drop.key}`">{{
                            t.settings.fields.drop_name
                        }}</label>
                        <input
                            :id="`${uid}-dn-${drop.key}`"
                            v-model="drop.name"
                            type="text"
                            maxlength="120"
                            class="form-control form-control-sm"
                            :placeholder="t.settings.fields.drop_name"
                        />
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="visually-hidden" :for="`${uid}-dh-${drop.key}`">{{
                            t.settings.fields.drop_how
                        }}</label>
                        <input
                            :id="`${uid}-dh-${drop.key}`"
                            v-model="drop.how"
                            type="text"
                            maxlength="200"
                            class="form-control form-control-sm"
                            :placeholder="t.settings.fields.drop_how"
                        />
                    </div>
                    <div class="col-5 col-md-1">
                        <label class="visually-hidden" :for="`${uid}-dt-${drop.key}`">{{
                            t.settings.fields.drop_times
                        }}</label>
                        <input
                            :id="`${uid}-dt-${drop.key}`"
                            v-model="drop.times_per_day"
                            type="number"
                            min="1"
                            max="12"
                            class="form-control form-control-sm"
                            :placeholder="t.settings.fields.drop_times"
                            :title="t.settings.fields.drop_times"
                        />
                    </div>
                    <div class="col-5 col-md-2">
                        <label class="visually-hidden" :for="`${uid}-dd-${drop.key}`">{{
                            t.settings.fields.drop_days
                        }}</label>
                        <input
                            :id="`${uid}-dd-${drop.key}`"
                            v-model="drop.days"
                            type="number"
                            min="1"
                            max="90"
                            class="form-control form-control-sm"
                            :placeholder="t.settings.fields.drop_days"
                            :title="t.settings.fields.drop_days"
                        />
                    </div>
                    <div class="col-2 col-md-1 text-end">
                        <button
                            type="button"
                            class="btn btn-outline-danger btn-sm"
                            :aria-label="`${t.settings.fields.remove_drop}: ${drop.name}`"
                            @click="removeDrop(index)"
                        >
                            <i class="ti ti-trash" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
                <div v-if="fieldError('eye_drops')" class="invalid-feedback d-block">{{ fieldError('eye_drops') }}</div>
                <button
                    type="button"
                    class="btn btn-outline-primary btn-sm mt-1"
                    :disabled="form.eye_drops.length >= maxDrops"
                    data-testid="guidance-add-drop"
                    @click="addDrop"
                >
                    <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ t.settings.fields.add_drop }}
                </button>
            </fieldset>

            <fieldset class="mb-1" data-testid="guidance-attachments">
                <legend class="form-label small mb-1">{{ t.settings.fields.attachments }}</legend>
                <p v-if="!template" class="small text-body-secondary mb-0">{{ t.settings.save_first }}</p>
                <template v-else>
                    <ul v-if="attachments.length" class="list-unstyled small mb-2">
                        <li v-for="file in attachments" :key="file.id" class="d-flex align-items-center gap-2 mb-1">
                            <i class="ti ti-file-type-pdf text-danger" aria-hidden="true"></i>
                            <span class="text-break flex-grow-1"
                                >{{ file.name }}
                                <span class="text-body-secondary">({{ sizeLabel(file.size_bytes) }})</span></span
                            >
                            <button
                                type="button"
                                class="btn btn-outline-danger btn-sm"
                                :aria-label="`${t.settings.fields.remove_pdf}: ${file.name}`"
                                :disabled="attachBusy"
                                @click="$emit('detach', file)"
                            >
                                <i class="ti ti-x" aria-hidden="true"></i>
                            </button>
                        </li>
                    </ul>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <label
                            class="btn btn-outline-primary btn-sm mb-0"
                            :class="{ disabled: !canAttach || attachBusy }"
                        >
                            <i class="ti ti-paperclip me-1" aria-hidden="true"></i>{{ t.settings.fields.attach_pdf }}
                            <input
                                ref="fileInput"
                                type="file"
                                accept="application/pdf,.pdf"
                                class="visually-hidden"
                                :disabled="!canAttach || attachBusy"
                                data-testid="guidance-file"
                                @change="onFile"
                            />
                        </label>
                        <span class="form-text mt-0">{{
                            tx('settings.fields.attach_hint', { max: maxAttachments })
                        }}</span>
                    </div>
                    <div
                        v-if="fieldError('file')"
                        class="invalid-feedback d-block"
                        role="alert"
                        data-testid="guidance-file-error"
                    >
                        {{ fieldError('file') }}
                    </div>
                </template>
            </fieldset>
        </form>

        <template #footer>
            <button type="button" class="btn btn-light btn-sm" @click="$emit('close')">{{ t.settings.cancel }}</button>
            <button
                type="submit"
                :form="`${uid}-form`"
                class="btn btn-primary btn-sm"
                :disabled="busy"
                data-testid="guidance-save"
            >
                <span v-if="busy" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span
                >{{ t.settings.save }}
            </button>
        </template>
    </CenteredModal>
</template>
