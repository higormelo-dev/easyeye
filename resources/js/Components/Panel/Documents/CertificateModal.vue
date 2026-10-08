<script setup>
import { computed, reactive, ref, useId, watch } from 'vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import Cid10Picker from '@/Components/Panel/Cid10Picker.vue';
import TinyMceEditor from '@/Components/Panel/TinyMceEditor.vue';
import DocumentDateField from './DocumentDateField.vue';
import { documentTexts, td, fill, currentTime, documentDatePayload, dateInRange } from '@/Support/clinicalDocuments.js';

/**
 * Atestados oftalmológicos (Fase 2 / B1) — um modal, quatro tipos:
 *  - attendance: comparecimento com horário de/até (chegada → agora) ou
 *    período, e dilatação pupilar (não dirigir/operar máquinas por N h);
 *  - companion: acompanhante (nome, documento opcional, data, horário);
 *  - medical: afastamento em dias + CID OPCIONAL que só vai com a
 *    autorização do paciente (Res. CFM 1.658/2002, art. 5º);
 *  - visual: aptidão visual — AV sem/com correção do PRONTUÁRIO salvo,
 *    conclusão e finalidade.
 * Todos com data do documento (data alternativa — B3). O servidor revalida
 * tudo; os erros dele voltam por `serverErrors`.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    kind: { type: String, default: 'attendance' }, // attendance | companion | medical | visual
    dateConfig: { type: Object, default: () => ({}) },
    arrivedAt: { type: String, default: '' },
    dilationHours: { type: Object, default: () => ({ default: 4, min: 1, max: 24 }) },
    // { without: { right, left }, with: { right, left } } — rótulos da AV
    acuity: { type: Object, default: () => ({ without: {}, with: {} }) },
    acuityUnsaved: { type: Boolean, default: false },
    cid10SearchUrl: { type: String, default: '' },
    dayExtensionUrl: { type: String, default: '' },
    busy: { type: Boolean, default: false },
    serverErrors: { type: Object, default: () => ({}) },
    t: { type: Object, default: null },
});

const emit = defineEmits(['close', 'submit']);

const texts = documentTexts(props.t);
const tt = (path, fallback) => td(texts, path, fallback);
const uid = useId();

const PERIODS = ['morning', 'afternoon', 'evening', 'full_day'];
const CONCLUSIONS = ['fit', 'fit_with_correction', 'unfit'];
const PURPOSES = ['work', 'sport', 'public_exam', 'other'];

const blank = () => ({
    document_date: '',
    date: '',
    time_from: '',
    time_to: '',
    period: '',
    dilation: false,
    dilation_hours: props.dilationHours?.default ?? 4,
    companion_name: '',
    companion_document: '',
    days: 1,
    start_date: '',
    cids: [],
    cid_authorized: false,
    conclusion: '',
    purpose: '',
    purpose_other: '',
    content: '',
});
const form = reactive(blank());
const daysPreview = ref('');
const touched = ref(false);
// Horários vieram da chegada do paciente (o aviso "pré-preenchido" só aparece aí).
const prefilled = ref(false);

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        Object.assign(form, blank());
        touched.value = false;
        daysPreview.value = '';
        // Comparecimento/acompanhante: chegada do paciente → hora atual (o
        // dia, em branco, segue a data do documento). Só um PAR válido: sem
        // chegada (ou chegada >= agora) os dois ficam em branco — antes o fim
        // vinha preenchido sozinho e a emissão travava.
        if (props.kind === 'attendance' || props.kind === 'companion') {
            const now = currentTime();
            const arrived = /^\d{2}:\d{2}$/.test(props.arrivedAt ?? '') ? props.arrivedAt : '';
            const valid = arrived && arrived < now;
            form.time_from = valid ? arrived : '';
            form.time_to = valid ? now : '';
            prefilled.value = Boolean(valid);
        }
        if (props.kind === 'medical') refreshDays();
    },
);

const title = computed(
    () =>
        ({
            attendance: tt('certificates.attendance_title', 'Atestado de comparecimento'),
            companion: tt('certificates.companion_title', 'Atestado de acompanhante'),
            medical: tt('certificates.medical_title', 'Atestado médico'),
            visual: tt('certificates.visual_title', 'Atestado de aptidão visual'),
        })[props.kind],
);
const icon = computed(
    () =>
        ({
            attendance: 'ti-user-check',
            companion: 'ti-users',
            medical: 'ti-stethoscope',
            visual: 'ti-eye-check',
        })[props.kind],
);

const hasVisit = computed(() => props.kind === 'attendance' || props.kind === 'companion');

// Horário OU período: escolher um período limpa os horários; digitar um
// horário limpa o período (o documento usa um ou outro).
watch(
    () => form.period,
    (period) => {
        if (!period) return;
        form.time_from = '';
        form.time_to = '';
    },
);
watch(
    () => [form.time_from, form.time_to],
    ([from, to]) => {
        if (from || to) form.period = '';
    },
);
const hasAcuity = computed(() => ['without', 'with'].some((k) => props.acuity?.[k]?.right || props.acuity?.[k]?.left));

// ── Validação na tela (o servidor revalida) ─────────────────────────────
const errors = computed(() => {
    const e = {};
    if (hasVisit.value) {
        if (Boolean(form.time_from) !== Boolean(form.time_to))
            e.time_to = tt('certificates.time_order', 'O horário final deve ser depois do inicial.');
        else if (form.time_from && form.time_to && form.time_to <= form.time_from)
            e.time_to = tt('certificates.time_order', 'O horário final deve ser depois do inicial.');
        if (form.date && !dateInRange(form.date, props.dateConfig)) e.date = tt('date.format_error', 'Data inválida.');
    }
    if (props.kind === 'attendance' && form.dilation) {
        const h = Number(form.dilation_hours);
        if (!Number.isInteger(h) || h < (props.dilationHours?.min ?? 1) || h > (props.dilationHours?.max ?? 24))
            e.dilation_hours = `${props.dilationHours?.min ?? 1}–${props.dilationHours?.max ?? 24}`;
    }
    if (props.kind === 'companion' && form.companion_name.trim().length < 3)
        e.companion_name = tt('certificates.required', 'obrigatório');
    if (props.kind === 'medical') {
        const d = Number(form.days);
        if (!Number.isInteger(d) || d < 1 || d > 365) e.days = '1–365';
        if (form.cids.length && !form.cid_authorized)
            e.cid_authorized = tt(
                'certificates.cid_authorization_required',
                'Para incluir o CID, confirme que o paciente autorizou (Res. CFM 1.658/2002, art. 5º).',
            );
    }
    if (props.kind === 'visual') {
        if (!form.conclusion) e.conclusion = tt('certificates.required', 'obrigatório');
        if (!form.purpose) e.purpose = tt('certificates.required', 'obrigatório');
        if (form.purpose === 'other' && !form.purpose_other.trim())
            e.purpose_other = tt('certificates.required', 'obrigatório');
    }
    if (form.document_date && !dateInRange(form.document_date, props.dateConfig)) e.document_date = ' ';
    return e;
});
const fieldError = (name) => props.serverErrors?.[name]?.[0] ?? (touched.value ? errors.value[name] : '') ?? '';
// Aptidão sem AV no prontuário (ou com AV mudada e não salva): não emite.
const visualBlocked = computed(() => props.kind === 'visual' && (!hasAcuity.value || props.acuityUnsaved));
const blocked = computed(() => Object.keys(errors.value).length > 0 || visualBlocked.value);

// ── Atestado médico: dias por extenso (servidor) ────────────────────────
let daysTimer = null;
function refreshDays() {
    clearTimeout(daysTimer);
    daysTimer = setTimeout(async () => {
        const days = Number(form.days);
        if (!props.dayExtensionUrl || !Number.isInteger(days) || days < 1 || days > 365) {
            daysPreview.value = '';
            return;
        }
        try {
            const res = await fetch(props.dayExtensionUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify({ days }),
            });
            daysPreview.value = res.ok ? ((await res.json())?.display ?? '') : '';
        } catch {
            daysPreview.value = '';
        }
    }, 300);
}

// "2026-10-07" → "07/10/2026" (o atestado médico espera d/m/Y no "a partir de").
const toBr = (iso) => (iso ? iso.split('-').reverse().join('/') : '');

function submit() {
    touched.value = true;
    if (blocked.value) return;

    const payload = { ...documentDatePayload(form.document_date, props.dateConfig?.today) };
    const content = (form.content || '').trim();
    if (content) payload.content = content;

    if (hasVisit.value) {
        if (form.date) payload.date = form.date;
        if (form.time_from && form.time_to) {
            payload.time_from = form.time_from;
            payload.time_to = form.time_to;
        } else if (form.period) payload.period = form.period;
    }
    if (props.kind === 'attendance' && form.dilation) {
        payload.dilation = true;
        payload.dilation_hours = Number(form.dilation_hours);
    }
    if (props.kind === 'companion') {
        payload.companion_name = form.companion_name.trim();
        if (form.companion_document.trim()) payload.companion_document = form.companion_document.trim();
    }
    if (props.kind === 'medical') {
        payload.days = Number(form.days);
        if (form.start_date) payload.date = toBr(form.start_date);
        if (form.cids.length) {
            payload.cid = form.cids[0].code;
            payload.cid_authorized = form.cid_authorized;
        }
    }
    if (props.kind === 'visual') {
        payload.conclusion = form.conclusion;
        payload.purpose = form.purpose;
        if (form.purpose === 'other') payload.purpose_other = form.purpose_other.trim();
    }

    emit('submit', payload);
}
</script>

<template>
    <CenteredModal :open="open" size="lg" @close="$emit('close')">
        <template #header>
            <h5 class="mb-0 fw-semibold" data-testid="certificate-title">
                <i class="ti me-2 text-primary" :class="icon" aria-hidden="true"></i>{{ title }}
            </h5>
        </template>

        <form :id="`${uid}-form`" class="ee-cert" novalidate @submit.prevent="submit">
            <!-- Acompanhante -->
            <div v-if="kind === 'companion'" class="row g-2 mb-2">
                <div class="col-12 col-md-7">
                    <label class="form-label small mb-1" :for="`${uid}-cn`"
                        >{{ tt('certificates.companion_name', 'Nome do acompanhante') }}
                        <span class="text-danger" aria-hidden="true">*</span></label
                    >
                    <input
                        :id="`${uid}-cn`"
                        v-model="form.companion_name"
                        type="text"
                        maxlength="160"
                        required
                        class="form-control form-control-sm"
                        :class="{ 'is-invalid': fieldError('companion_name') }"
                        data-testid="cert-companion-name"
                    />
                    <div v-if="fieldError('companion_name')" class="invalid-feedback">
                        {{ fieldError('companion_name') }}
                    </div>
                </div>
                <div class="col-12 col-md-5">
                    <label class="form-label small mb-1" :for="`${uid}-cd`">{{
                        tt('certificates.companion_document', 'Documento do acompanhante (opcional)')
                    }}</label>
                    <input
                        :id="`${uid}-cd`"
                        v-model="form.companion_document"
                        type="text"
                        maxlength="40"
                        class="form-control form-control-sm"
                        :placeholder="tt('certificates.companion_doc_ph', 'RG, CPF ou outro')"
                    />
                </div>
            </div>

            <!-- Dia + horário (comparecimento e acompanhante) -->
            <fieldset v-if="hasVisit" class="mb-2">
                <div class="row g-2 align-items-end">
                    <div class="col-12 col-sm-4">
                        <label class="form-label small mb-1" :for="`${uid}-vd`">{{
                            tt('certificates.visit_date', 'Dia do comparecimento')
                        }}</label>
                        <input
                            :id="`${uid}-vd`"
                            v-model="form.date"
                            type="date"
                            class="form-control form-control-sm"
                            :class="{ 'is-invalid': fieldError('date') }"
                            :min="dateConfig?.min || undefined"
                            :max="dateConfig?.max || undefined"
                            :aria-describedby="`${uid}-vd-hint`"
                        />
                    </div>
                    <div class="col-6 col-sm-4">
                        <label class="form-label small mb-1" :for="`${uid}-tf`">{{
                            tt('certificates.time_from', 'Das')
                        }}</label>
                        <input
                            :id="`${uid}-tf`"
                            v-model="form.time_from"
                            type="time"
                            class="form-control form-control-sm"
                            data-testid="cert-time-from"
                        />
                    </div>
                    <div class="col-6 col-sm-4">
                        <label class="form-label small mb-1" :for="`${uid}-tt`">{{
                            tt('certificates.time_to', 'Às')
                        }}</label>
                        <input
                            :id="`${uid}-tt`"
                            v-model="form.time_to"
                            type="time"
                            class="form-control form-control-sm"
                            :class="{ 'is-invalid': fieldError('time_to') }"
                            :aria-describedby="prefilled ? `${uid}-times` : undefined"
                            data-testid="cert-time-to"
                        />
                    </div>
                </div>
                <div v-if="fieldError('time_to')" class="invalid-feedback d-block" role="alert">
                    {{ fieldError('time_to') }}
                </div>
                <small :id="`${uid}-vd-hint`" class="form-text d-block">{{
                    tt('certificates.visit_date_hint', 'Dia em branco = data do documento.')
                }}</small>
                <small v-if="prefilled" :id="`${uid}-times`" class="form-text d-block">{{
                    tt(
                        'certificates.times_hint',
                        'Pré-preenchido pela chegada do paciente e pela hora atual — ajuste se precisar.',
                    )
                }}</small>
                <div class="mt-2" style="max-width: 16rem">
                    <label class="form-label small mb-1" :for="`${uid}-pe`">{{
                        tt('certificates.period', 'Ou período/turno')
                    }}</label>
                    <select
                        :id="`${uid}-pe`"
                        v-model="form.period"
                        class="form-select form-select-sm"
                        data-testid="cert-period"
                    >
                        <option value="">{{ tt('certificates.period_none', 'Sem período') }}</option>
                        <option v-for="p in PERIODS" :key="p" :value="p">
                            {{ tt(`certificates.periods.${p}`, p) }}
                        </option>
                    </select>
                </div>
            </fieldset>

            <!-- Dilatação pupilar -->
            <div v-if="kind === 'attendance'" class="ee-cert__box mb-2">
                <div class="form-check">
                    <input
                        :id="`${uid}-dil`"
                        v-model="form.dilation"
                        class="form-check-input"
                        type="checkbox"
                        data-testid="cert-dilation"
                    />
                    <label class="form-check-label" :for="`${uid}-dil`">{{
                        tt('certificates.dilation', 'Paciente fez dilatação pupilar')
                    }}</label>
                </div>
                <div v-if="form.dilation" class="d-flex align-items-center gap-2 flex-wrap mt-2">
                    <label class="small mb-0" :for="`${uid}-dh`">{{
                        tt('certificates.dilation_hours', 'Recomendar não dirigir nem operar máquinas por')
                    }}</label>
                    <input
                        :id="`${uid}-dh`"
                        v-model.number="form.dilation_hours"
                        type="number"
                        :min="dilationHours?.min ?? 1"
                        :max="dilationHours?.max ?? 24"
                        step="1"
                        class="form-control form-control-sm ee-cert__hours"
                        :class="{ 'is-invalid': fieldError('dilation_hours') }"
                        data-testid="cert-dilation-hours"
                    />
                    <span class="small">{{ tt('certificates.hours', 'horas') }}</span>
                </div>
                <small v-if="form.dilation" class="form-text d-block mt-1">{{
                    fill(
                        tt(
                            'certificates.dilation_preview',
                            'Sai no atestado: "recomenda-se não dirigir veículos nem operar máquinas pelas próximas :hours horas".',
                        ),
                        { hours: form.dilation_hours },
                    )
                }}</small>
            </div>

            <!-- Atestado médico -->
            <template v-if="kind === 'medical'">
                <div class="row g-2 mb-2">
                    <div class="col-6 col-sm-4">
                        <label class="form-label small mb-1" :for="`${uid}-days`">{{
                            tt('certificates.days', 'Dias de afastamento')
                        }}</label>
                        <input
                            :id="`${uid}-days`"
                            v-model.number="form.days"
                            type="number"
                            min="1"
                            max="365"
                            step="1"
                            class="form-control form-control-sm"
                            :class="{ 'is-invalid': fieldError('days') }"
                            data-testid="cert-days"
                            @input="refreshDays"
                        />
                    </div>
                    <div class="col-6 col-sm-4">
                        <label class="form-label small mb-1" :for="`${uid}-sd`">{{
                            tt('certificates.start_date', 'A partir de')
                        }}</label>
                        <input
                            :id="`${uid}-sd`"
                            v-model="form.start_date"
                            type="date"
                            class="form-control form-control-sm"
                        />
                        <small class="form-text">{{
                            tt('certificates.start_date_hint', 'Em branco = data do documento.')
                        }}</small>
                    </div>
                </div>
                <div v-if="daysPreview" class="alert alert-info py-1 px-2 small mb-2" role="status">
                    <strong>{{ tt('certificates.days_preview', 'Pré-visualização') }}:</strong> {{ daysPreview }}
                </div>
                <div class="ee-cert__box mb-2">
                    <Cid10Picker
                        v-if="cid10SearchUrl"
                        v-model="form.cids"
                        :search-url="cid10SearchUrl"
                        :multiple="false"
                        :max-items="1"
                        :label="tt('certificates.cid', 'CID-10 (opcional)')"
                        :invalid="Boolean(fieldError('cid'))"
                    />
                    <div v-if="fieldError('cid')" class="invalid-feedback d-block">{{ fieldError('cid') }}</div>
                    <div v-if="form.cids.length" class="form-check mt-2">
                        <input
                            :id="`${uid}-cidok`"
                            v-model="form.cid_authorized"
                            class="form-check-input"
                            type="checkbox"
                            :class="{ 'is-invalid': fieldError('cid_authorized') }"
                            data-testid="cert-cid-authorized"
                        />
                        <label class="form-check-label fw-semibold" :for="`${uid}-cidok`">{{
                            tt('certificates.cid_authorized', 'O paciente autorizou a inclusão do CID neste atestado')
                        }}</label>
                        <div v-if="fieldError('cid_authorized')" class="invalid-feedback d-block" role="alert">
                            {{ fieldError('cid_authorized') }}
                        </div>
                    </div>
                    <small class="form-text d-block mt-1">{{
                        tt(
                            'certificates.cid_hint',
                            'Res. CFM 1.658/2002, art. 5º: o diagnóstico só entra no atestado a pedido ou com a concordância do paciente.',
                        )
                    }}</small>
                </div>
            </template>

            <!-- Aptidão visual -->
            <template v-if="kind === 'visual'">
                <div class="ee-cert__box mb-2">
                    <div class="small fw-semibold mb-1">
                        {{ tt('certificates.acuity', 'Acuidade visual (do prontuário)') }}
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-1 ee-cert__acuity" data-testid="cert-acuity">
                            <thead>
                                <tr>
                                    <th scope="col"><span class="visually-hidden">—</span></th>
                                    <th scope="col">{{ tt('certificates.without', 'Sem correção') }}</th>
                                    <th scope="col">{{ tt('certificates.with', 'Com correção') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="eye in ['right', 'left']" :key="eye">
                                    <th scope="row">
                                        {{
                                            eye === 'right' ? tt('certificates.od', 'OD') : tt('certificates.oe', 'OE')
                                        }}
                                    </th>
                                    <td>{{ acuity?.without?.[eye] || '—' }}</td>
                                    <td>{{ acuity?.with?.[eye] || '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-if="!hasAcuity" class="alert alert-warning py-1 px-2 small mb-0" role="alert">
                        <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i
                        >{{
                            tt(
                                'certificates.acuity_missing',
                                'Registre a acuidade visual no prontuário antes de emitir.',
                            )
                        }}
                    </div>
                    <div
                        v-else-if="acuityUnsaved"
                        class="alert alert-warning py-1 px-2 small mb-0"
                        role="alert"
                        data-testid="cert-acuity-unsaved"
                    >
                        <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i
                        >{{
                            tt(
                                'certificates.acuity_unsaved',
                                'A acuidade visual mudou e ainda não foi salva — salve o prontuário antes de emitir.',
                            )
                        }}
                    </div>
                </div>
                <fieldset class="mb-2">
                    <legend class="form-label small mb-1">
                        {{ tt('certificates.conclusion', 'Conclusão') }}
                        <span class="text-danger" aria-hidden="true">*</span>
                    </legend>
                    <div class="d-flex gap-3 flex-wrap">
                        <label v-for="c in CONCLUSIONS" :key="c" class="form-check mb-0">
                            <input
                                v-model="form.conclusion"
                                class="form-check-input"
                                type="radio"
                                :value="c"
                                :data-testid="`cert-conclusion-${c}`"
                            />
                            {{ tt(`certificates.conclusions.${c}`, c) }}
                        </label>
                    </div>
                    <div v-if="fieldError('conclusion')" class="invalid-feedback d-block">
                        {{ fieldError('conclusion') }}
                    </div>
                </fieldset>
                <div class="row g-2 mb-2">
                    <div class="col-12 col-sm-5">
                        <label class="form-label small mb-1" :for="`${uid}-pu`"
                            >{{ tt('certificates.purpose', 'Finalidade') }}
                            <span class="text-danger" aria-hidden="true">*</span></label
                        >
                        <select
                            :id="`${uid}-pu`"
                            v-model="form.purpose"
                            class="form-select form-select-sm"
                            :class="{ 'is-invalid': fieldError('purpose') }"
                            data-testid="cert-purpose"
                        >
                            <option value="" disabled>—</option>
                            <option v-for="p in PURPOSES" :key="p" :value="p">
                                {{ tt(`certificates.purposes.${p}`, p) }}
                            </option>
                        </select>
                    </div>
                    <div v-if="form.purpose === 'other'" class="col-12 col-sm-7">
                        <label class="form-label small mb-1" :for="`${uid}-po`">{{
                            tt('certificates.purpose_other', 'Qual finalidade?')
                        }}</label>
                        <input
                            :id="`${uid}-po`"
                            v-model="form.purpose_other"
                            type="text"
                            maxlength="120"
                            class="form-control form-control-sm"
                            :class="{ 'is-invalid': fieldError('purpose_other') }"
                        />
                    </div>
                </div>
            </template>

            <!-- Observações -->
            <div class="mb-2">
                <label class="form-label small mb-1" :for="`${uid}-obs`">{{
                    tt('certificates.observations', 'Observações (opcional)')
                }}</label>
                <TinyMceEditor
                    v-if="kind === 'attendance' && open"
                    v-model="form.content"
                    :height="200"
                    :placeholder="tt('certificates.observations_ph', 'Texto livre acrescentado ao final do atestado…')"
                />
                <textarea
                    v-else
                    :id="`${uid}-obs`"
                    v-model="form.content"
                    class="form-control form-control-sm"
                    rows="3"
                    maxlength="5000"
                    :placeholder="tt('certificates.observations_ph', 'Texto livre acrescentado ao final do atestado…')"
                ></textarea>
            </div>

            <DocumentDateField
                v-model="form.document_date"
                :config="dateConfig"
                :error="serverErrors?.document_date?.[0] ?? ''"
                :t="t"
            />
        </form>

        <template #footer>
            <button type="button" class="btn btn-secondary btn-sm" @click="$emit('close')">
                {{ tt('certificates.cancel', 'Cancelar') }}
            </button>
            <button
                type="button"
                class="btn btn-primary btn-sm"
                :disabled="busy || (touched && blocked) || visualBlocked"
                data-testid="certificate-emit"
                @click="submit"
            >
                <span v-if="busy" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                <i v-else class="ti ti-printer me-1" aria-hidden="true"></i
                >{{ tt('certificates.emit', 'Emitir atestado') }}
            </button>
        </template>
    </CenteredModal>
</template>

<style scoped>
.ee-cert__box {
    border: 1px solid var(--bs-border-color);
    border-radius: 0.4rem;
    padding: 0.5rem 0.6rem;
    background: var(--bs-tertiary-bg);
}
.ee-cert__hours {
    width: 5rem;
}
.ee-cert__acuity th,
.ee-cert__acuity td {
    text-align: center;
    font-size: 0.85rem;
}
</style>
