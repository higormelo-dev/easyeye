<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

/**
 * Orientações pós-procedimento de UM procedimento do prontuário (Fase 6 / S14): o que já foi entregue (PDF e anexos) e,
 * para o médico, a emissão a partir de um modelo da clínica — com ajuste do texto, do local e dos colírios para este
 * paciente. A emissão é congelada pelo servidor; mudar o modelo depois não altera o que foi entregue. Os textos vêm do
 * servidor (`t`), no idioma do usuário.
 */
const props = defineProps({
    // GET (estado) e POST (emitir) do procedimento.
    url: { type: String, required: true },
});

const { dateTime } = useLocaleFormat();

const state = reactive({
    loading: true,
    failed: false,
    canIssue: false,
    templates: [],
    issues: [],
    maxDrops: 10,
    t: null,
});
const form = reactive({ template_id: '', body: '', location_label: '', eye_drops: [] });
const busy = ref(false);
const error = ref('');
const notice = ref('');
let seq = 0;

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const selected = computed(() => state.templates.find((tpl) => tpl.id === form.template_id) ?? null);

async function load() {
    state.loading = true;
    state.failed = false;

    try {
        const res = await fetch(props.url, { headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() } });
        if (!res.ok) throw new Error(String(res.status));
        const data = (await res.json()).data;
        state.canIssue = Boolean(data.can_issue);
        state.templates = data.templates ?? [];
        state.issues = data.issues ?? [];
        state.maxDrops = data.max_drops ?? 10;
        state.t = data.t;
        if (state.templates.length === 1) form.template_id = state.templates[0].id;
    } catch (e) {
        console.error('Failed to load care guidance:', e);
        state.failed = true;
    } finally {
        state.loading = false;
    }
}

// Escolher o modelo traz o texto, o local e os colírios dele para ajustar.
watch(
    () => form.template_id,
    () => {
        const tpl = selected.value;
        form.body = tpl?.body ?? '';
        form.location_label = tpl?.location_label ?? '';
        form.eye_drops = (tpl?.eye_drops ?? []).map((drop) => ({ key: `d${++seq}`, ...drop }));
        error.value = '';
        notice.value = '';
    },
    { immediate: true },
);

function addDrop() {
    if (form.eye_drops.length >= state.maxDrops) return;
    form.eye_drops.push({ key: `d${++seq}`, name: '', how: '', times_per_day: '', days: '' });
}

async function issue() {
    if (busy.value || !form.template_id) return;
    busy.value = true;
    error.value = '';
    notice.value = '';

    try {
        const res = await fetch(props.url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({
                template_id: form.template_id,
                body: form.body,
                location_label: form.location_label || null,
                eye_drops: form.eye_drops
                    .filter((drop) => String(drop.name ?? '').trim())
                    .map((drop) => ({
                        name: String(drop.name).trim(),
                        how: String(drop.how ?? '').trim() || null,
                        times_per_day:
                            drop.times_per_day === '' || drop.times_per_day == null ? null : Number(drop.times_per_day),
                        days: drop.days === '' || drop.days == null ? null : Number(drop.days),
                    })),
            }),
        });
        const json = await res.json().catch(() => ({}));

        if (!res.ok) {
            error.value = json.errors
                ? (Object.values(json.errors).flat()[0] ?? json.message)
                : (json.message ?? state.t.errors.generic);

            return;
        }

        if (!state.issues.some((item) => item.id === json.data.id)) state.issues.unshift(json.data);
        notice.value = json.message;
        window.open(json.data.pdf_url, '_blank', 'noopener');
    } catch (e) {
        console.error('Care guidance issue error:', e);
        error.value = state.t?.errors?.generic ?? '';
    } finally {
        busy.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div class="border rounded p-2 mt-2 bg-light recall-form" data-testid="guidance-panel">
        <div v-if="state.loading" class="text-muted small">
            <span class="spinner-border spinner-border-sm me-2"></span>…
        </div>
        <div v-else-if="state.failed" class="text-danger small" role="alert" data-testid="guidance-load-error">
            <i class="fas fa-exclamation-triangle me-1"></i>{{ state.t?.issue?.load_error ?? '—' }}
            <button type="button" class="btn btn-link btn-sm p-0 ms-2" @click="load">↻</button>
        </div>

        <template v-else>
            <h6 class="small fw-semibold mb-1">{{ state.t.issue.previous }}</h6>
            <p v-if="!state.issues.length" class="small text-muted mb-2" data-testid="guidance-no-issues">
                {{ state.t.issue.no_previous }}
            </p>
            <ul v-else class="list-unstyled small mb-2" data-testid="guidance-issues">
                <li v-for="item in state.issues" :key="item.id" class="mb-1" :data-testid="`guidance-issue-${item.id}`">
                    <i class="fas fa-file-medical me-1 text-primary"></i
                    ><span class="fw-semibold">{{ item.title }}</span>
                    <span class="text-muted">
                        · {{ dateTime(item.issued_at)
                        }}<template v-if="item.doctor_name"> · {{ item.doctor_name }}</template></span
                    >
                    <a :href="item.pdf_url" target="_blank" rel="noopener" class="ms-2">{{ state.t.issue.pdf }}</a>
                    <a
                        v-for="file in item.attachments"
                        :key="file.index"
                        :href="file.url"
                        target="_blank"
                        rel="noopener"
                        class="ms-2"
                    >
                        <i class="fas fa-paperclip"></i> {{ file.name }}
                    </a>
                </li>
            </ul>

            <template v-if="state.canIssue">
                <p v-if="!state.templates.length" class="small text-muted mb-0" data-testid="guidance-no-templates">
                    {{ state.t.issue.none }}
                </p>

                <div v-else data-testid="guidance-form">
                    <label class="form-label small fw-semibold mb-1" for="guidance-template">{{
                        state.t.issue.choose
                    }}</label>
                    <select id="guidance-template" v-model="form.template_id" class="form-select form-select-sm mb-2">
                        <option value="" disabled>—</option>
                        <option v-for="tpl in state.templates" :key="tpl.id" :value="tpl.id">
                            {{ tpl.title }}<template v-if="tpl.procedure_name"> — {{ tpl.procedure_name }}</template>
                        </option>
                    </select>

                    <details v-if="selected" class="mb-2">
                        <summary class="small fw-semibold">{{ state.t.issue.adjust }}</summary>
                        <label class="form-label small mb-1 mt-2" for="guidance-body">{{ state.t.issue.body }}</label>
                        <textarea
                            id="guidance-body"
                            v-model="form.body"
                            rows="6"
                            maxlength="8000"
                            class="form-control form-control-sm"
                        ></textarea>
                        <label class="form-label small mb-1 mt-2" for="guidance-place">{{
                            state.t.issue.location
                        }}</label>
                        <input
                            id="guidance-place"
                            v-model="form.location_label"
                            type="text"
                            maxlength="160"
                            class="form-control form-control-sm"
                        />
                        <div class="small fw-semibold mt-2 mb-1">{{ state.t.issue.eye_drops }}</div>
                        <div v-for="(drop, index) in form.eye_drops" :key="drop.key" class="row g-1 mb-1">
                            <div class="col-5">
                                <input
                                    v-model="drop.name"
                                    type="text"
                                    maxlength="120"
                                    class="form-control form-control-sm"
                                    :placeholder="state.t.settings.fields.drop_name"
                                    :aria-label="state.t.settings.fields.drop_name"
                                />
                            </div>
                            <div class="col-3">
                                <input
                                    v-model="drop.how"
                                    type="text"
                                    maxlength="200"
                                    class="form-control form-control-sm"
                                    :placeholder="state.t.settings.fields.drop_how"
                                    :aria-label="state.t.settings.fields.drop_how"
                                />
                            </div>
                            <div class="col-2">
                                <input
                                    v-model="drop.times_per_day"
                                    type="number"
                                    min="1"
                                    max="12"
                                    class="form-control form-control-sm"
                                    :aria-label="state.t.settings.fields.drop_times"
                                    :title="state.t.settings.fields.drop_times"
                                />
                            </div>
                            <div class="col-1">
                                <input
                                    v-model="drop.days"
                                    type="number"
                                    min="1"
                                    max="90"
                                    class="form-control form-control-sm"
                                    :aria-label="state.t.settings.fields.drop_days"
                                    :title="state.t.settings.fields.drop_days"
                                />
                            </div>
                            <div class="col-1 text-end">
                                <button
                                    type="button"
                                    class="btn btn-outline-danger btn-sm"
                                    :aria-label="`${state.t.settings.fields.remove_drop}: ${drop.name}`"
                                    @click="form.eye_drops.splice(index, 1)"
                                >
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="btn btn-outline-secondary btn-sm"
                            :disabled="form.eye_drops.length >= state.maxDrops"
                            @click="addDrop"
                        >
                            <i class="fas fa-plus me-1"></i>{{ state.t.settings.fields.add_drop }}
                        </button>
                    </details>

                    <p class="small text-muted mb-2">{{ state.t.issue.frozen_hint }}</p>
                    <p v-if="error" class="text-danger small mb-2" role="alert" data-testid="guidance-error">
                        {{ error }}
                    </p>
                    <p v-if="notice" class="text-success small mb-2" role="status" data-testid="guidance-notice">
                        {{ notice }}
                    </p>

                    <button
                        type="button"
                        class="btn btn-primary btn-sm"
                        :disabled="busy || !form.template_id"
                        data-testid="guidance-issue"
                        @click="issue"
                    >
                        <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span
                        >{{ busy ? state.t.issue.issuing : state.t.issue.issue }}
                    </button>
                </div>
            </template>
        </template>
    </div>
</template>
