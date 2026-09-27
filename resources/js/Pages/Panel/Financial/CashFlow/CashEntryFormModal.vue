<script setup>
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import MoneyInput from '@/Components/Panel/MoneyInput.vue';
import SearchSelect from '@/Components/Panel/SearchSelect.vue';

/**
 * Lançamento avulso do fluxo de caixa (POST store / PATCH update, JSON).
 *
 * Ordem pensada para o balcão: Tipo → Valor → Descrição → Data → Forma →
 * Categoria → Status (convênio e observações opcionais no fim). Enter salva;
 * "Salvar e lançar outro" mantém tipo/data/forma e volta o foco ao valor.
 *
 * Edição: pré-preenche com a LINHA da listagem (`entry`). Linha travada
 * (`lock_reason`) nunca deveria chegar aqui; se chegar, mostra o motivo e não
 * deixa salvar (o servidor recusa de todo jeito). Recebimento da agenda com
 * pagamento dividido (dinheiro + cartão): valor e forma ficam só leitura — o
 * servidor também recusa mudá-los (CashEntryRequest).
 *
 * Erros: todo retorno != 2xx aparece DENTRO do modal — campo a campo quando o
 * 422 traz `errors`, ou num alerta (período fechado, guia vinculada, 403/419/
 * 429/500, falha de rede).
 */
const props = defineProps({
    open:            { type: Boolean, required: true },
    entry:           { type: Object,  default: null }, // linha da listagem (edição) ou null (novo)
    categories:      { type: Array,   default: () => [] },
    covenants:       { type: Array,   default: () => [] },
    paymentMethods:  { type: Array,   default: () => [] }, // [{ value, label }]
    today:           { type: String,  default: '' },   // Y-m-d no fuso da clínica (servidor)
    canEditSchedule: { type: Boolean, default: false },
    t:               { type: Object,  default: () => ({}) },
});

const emit = defineEmits(['close', 'saved']);

/** Campos exibidos no formulário: erro de outra chave vira alerta geral. */
const FORM_FIELDS = ['type', 'amount', 'description', 'entry_date', 'payment_method', 'category_id', 'status', 'covenant_id', 'notes'];

const uid = useId();
const ids = {
    form:          `cash-entry-form-${uid}`,
    title:         `cash-entry-title-${uid}`,
    type:          `cash-entry-type-${uid}`,
    amount:        `cash-entry-amount-${uid}`,
    amountError:   `cash-entry-amount-error-${uid}`,
    scheduleLock:  `cash-entry-schedule-lock-${uid}`,
    description:   `cash-entry-description-${uid}`,
    date:          `cash-entry-date-${uid}`,
    paymentMethod: `cash-entry-payment-method-${uid}`,
    paymentError:  `cash-entry-payment-method-error-${uid}`,
    category:      `cash-entry-category-${uid}`,
    status:        `cash-entry-status-${uid}`,
    covenant:      `cash-entry-covenant-${uid}`,
    notes:         `cash-entry-notes-${uid}`,
};

const isEdit   = computed(() => !!props.entry?.id);
const isLocked = computed(() => !!props.entry?.lock_reason);
const lockHint = computed(() => (props.entry?.lock_reason === 'billing_claim'
    ? props.t.lock_billing_claim_hint
    : props.t.lock_closed_period_hint));

/** Recebimento da agenda com dinheiro + cartão: valor e forma só pela agenda. */
const isScheduleSplit = computed(() => isEdit.value && props.entry?.origin === 'schedule' && !!props.entry?.has_split);
const scheduleHref    = computed(() => route('panel.schedules.index', {
    date: props.entry?.schedule_date || props.entry?.entry_date,
}));

const saving            = ref(false);
const errors            = ref({});
const generalError      = ref('');
const confirmingDiscard = ref(false);
const initialSnapshot   = ref('');
const formEl            = ref(null);

/** "Hoje" local: toISOString() é UTC e depois das 21h (UTC-3) já é amanhã. */
function localToday() {
    const now = new Date();

    return [
        now.getFullYear(),
        String(now.getMonth() + 1).padStart(2, '0'),
        String(now.getDate()).padStart(2, '0'),
    ].join('-');
}

/**
 * `today` do servidor (APP_TIMEZONE) só vale enquanto o dia no aparelho não
 * virou desde que ele chegou: aba aberta ontem e usada hoje gravaria o
 * lançamento em ONTEM sem aviso (a prop só muda numa visita completa).
 */
let serverTodaySeenOn = localToday();
watch(() => props.today, () => { serverTodaySeenOn = localToday(); });

function defaultEntryDate() {
    const local = localToday();

    return props.today && serverTodaySeenOn === local ? props.today : local;
}

/** Formulário em branco; `keep` carrega tipo/data/forma do lançamento anterior. */
function blankForm(keep = {}) {
    return {
        type:           keep.type ?? 'income',
        amount:         null,
        description:    '',
        entry_date:     keep.entry_date ?? defaultEntryDate(),
        payment_method: keep.payment_method ?? '',
        category_id:    '',
        status:         'paid',
        covenant_id:    '',
        notes:          '',
    };
}

function formFromEntry(entry) {
    return {
        type:           entry.type ?? 'income',
        amount:         entry.amount ?? null,
        description:    entry.description ?? '',
        entry_date:     entry.entry_date || defaultEntryDate(),
        payment_method: entry.payment_method ?? '',
        category_id:    entry.category_id ?? '',
        status:         entry.status ?? 'paid',
        covenant_id:    entry.covenant_id ?? '',
        notes:          entry.notes ?? '',
    };
}

const form = ref(blankForm());

const isDirty = computed(() => JSON.stringify(form.value) !== initialSnapshot.value);

function resetState(values) {
    form.value              = values;
    errors.value            = {};
    generalError.value      = '';
    confirmingDiscard.value = false;
    initialSnapshot.value   = JSON.stringify(values);
}

// ── Foco: primeiro campo (tipo) ao abrir; valor no "lançar outro" ───────────
function focusFirstField() {
    formEl.value?.querySelector('input[type="radio"]:checked:not(:disabled)')?.focus();
}

function focusAmount() {
    document.getElementById(ids.amount)?.focus();
}

// ── Teclado: Esc fecha (ou cancela o "descartar?") ─────────────────────────
function onKeydown(event) {
    if (event.key !== 'Escape' || !props.open) return;

    event.preventDefault();
    if (confirmingDiscard.value) {
        confirmingDiscard.value = false;

        return;
    }
    requestClose();
}

watch(() => props.open, async (isOpen) => {
    // immediate: roda também no render SSR, onde não há `document`.
    if (typeof document === 'undefined') return;

    if (!isOpen) {
        document.removeEventListener('keydown', onKeydown);

        return;
    }

    resetState(props.entry ? formFromEntry(props.entry) : blankForm());
    document.addEventListener('keydown', onKeydown);
    await nextTick();
    focusFirstField();
}, { immediate: true });

onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));

// ── Opções ──────────────────────────────────────────────────────────────────
const typeOptions = computed(() => [
    { value: 'income',  label: props.t.types?.income ?? 'income',   icon: 'ti ti-arrow-down-left' },
    { value: 'expense', label: props.t.types?.expense ?? 'expense', icon: 'ti ti-arrow-up-right' },
]);

const statusOptions = computed(() => ['pending', 'paid', 'cancelled'].map((value) => ({
    value,
    label: props.t.statuses?.[value] ?? value,
})));

/**
 * Mantém visível a opção já gravada que saiu da lista (categoria/convênio
 * inativado depois do lançamento), com o nome vindo da própria linha.
 */
function withCurrentOption(options, id, name) {
    if (!id || options.some((o) => o.id === id)) return options;

    return [...options, { id, name: name || '—' }];
}

const filteredCategories = computed(() => withCurrentOption(
    props.categories.filter((c) => !form.value.type || c.type === form.value.type),
    form.value.category_id,
    props.entry?.category_id === form.value.category_id ? props.entry?.category_name : '',
));

const covenantOptions = computed(() => withCurrentOption(
    props.covenants,
    form.value.covenant_id,
    props.entry?.covenant_id === form.value.covenant_id ? props.entry?.covenant_name : '',
));

// ── Tipo × categoria: trocar o tipo limpa a categoria do outro tipo ─────────
/** Tipo da categoria (da lista, ou do próprio lançamento se ela saiu da lista). */
function categoryType(id) {
    const found = props.categories.find((c) => c.id === id);
    if (found) return found.type;

    return props.entry?.category_id === id ? props.entry?.type : null;
}

function setType(value) {
    form.value.type = value;

    const type = form.value.category_id ? categoryType(form.value.category_id) : null;
    if (type && type !== value) {
        form.value.category_id = '';
    }
}

// ── Envio ──────────────────────────────────────────────────────────────────
function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

function firstMessage(value) {
    return Array.isArray(value) ? String(value[0] ?? '') : String(value ?? '');
}

function applyErrorResponse(status, json) {
    if (status === 422 && json.errors && typeof json.errors === 'object') {
        errors.value = json.errors;

        const outsideForm = Object.keys(json.errors).find((key) => !FORM_FIELDS.includes(key));
        if (outsideForm) {
            generalError.value = firstMessage(json.errors[outsideForm]);
        }

        return;
    }

    if (status === 419) {
        generalError.value = props.t.session_expired ?? '';

        return;
    }

    generalError.value = json.message || props.t.form_save_error || '';
}

function buildPayload() {
    const data = { ...form.value };

    // Travado pela agenda: não reenvia a forma (evita apagar um valor legado).
    if (isScheduleSplit.value) delete data.payment_method;

    return isEdit.value ? { ...data, _method: 'PATCH' } : data;
}

/** "Salvar e lançar outro": mantém tipo/data/forma, limpa o resto, foco no valor. */
async function startAnother(previous) {
    resetState(blankForm({
        type:           previous.type,
        entry_date:     previous.entry_date,
        payment_method: previous.payment_method ?? '',
    }));
    await nextTick();
    focusAmount();
}

async function submit({ another = false } = {}) {
    if (saving.value || isLocked.value) return;

    const keepOpen = another && !isEdit.value;

    saving.value       = true;
    errors.value       = {};
    generalError.value = '';

    const url = isEdit.value
        ? route('panel.financial.cash-flow.update', props.entry.id)
        : route('panel.financial.cash-flow.store');
    const payload = buildPayload();

    try {
        const res = await fetch(url, {
            method:  'POST',
            headers: {
                Accept:         'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify(payload),
        });
        const json = await res.json().catch(() => ({}));

        if (!res.ok) {
            applyErrorResponse(res.status, json);

            return;
        }

        // entryDate = a data enviada (Y-m-d): a listagem avisa se ela caiu fora do filtro.
        emit('saved', { message: json.message ?? '', entry: json.data ?? null, entryDate: payload.entry_date, keepOpen });

        if (keepOpen) await startAnother(payload);
    } catch {
        generalError.value = props.t.network_error ?? '';
    } finally {
        saving.value = false;
    }
}

/**
 * Enter salva de qualquer campo (menos observações e botões). A lista da
 * categoria/convênio já consome o Enter para escolher a opção (preventDefault).
 */
function onFormKeydown(event) {
    if (event.key !== 'Enter' || event.defaultPrevented || event.isComposing) return;

    const tag = event.target?.tagName;
    if (tag === 'TEXTAREA' || tag === 'BUTTON' || tag === 'A') return;

    event.preventDefault();
    submit();
}

// ── Fechar sem perder o que foi digitado ───────────────────────────────────
function requestClose() {
    if (saving.value) return;

    if (isDirty.value && !confirmingDiscard.value) {
        confirmingDiscard.value = true;

        return;
    }

    confirmingDiscard.value = false;
    emit('close');
}

function hasError(field)   { return !!(errors.value[field] && errors.value[field].length); }
function firstError(field) { return firstMessage(errors.value[field]); }

/** aria-describedby: erro do campo + aviso da trava da agenda, quando houver. */
function describedBy(field, errorId) {
    return [
        hasError(field) ? errorId : null,
        isScheduleSplit.value ? ids.scheduleLock : null,
    ].filter(Boolean).join(' ') || undefined;
}

const amountDescribedBy  = computed(() => describedBy('amount', ids.amountError));
const paymentDescribedBy = computed(() => describedBy('payment_method', ids.paymentError));
</script>

<template>
    <CenteredModal :open="open" size="md" @close="requestClose">
        <template #header>
            <h5 :id="ids.title" class="modal-title mb-0">
                <i class="ti ti-cash-register me-1 text-primary" aria-hidden="true"></i>
                {{ isEdit ? t.form_title_edit : t.form_title_new }}
            </h5>
        </template>

        <div v-if="isLocked" class="alert alert-warning small d-flex gap-2 mb-3" role="alert" data-test="lock-alert">
            <i class="ti ti-lock mt-1" aria-hidden="true"></i>
            <span>{{ lockHint }}</span>
        </div>

        <div v-if="generalError" class="alert alert-danger small d-flex gap-2 mb-3" role="alert" data-test="form-error">
            <i class="ti ti-alert-circle mt-1" aria-hidden="true"></i>
            <span>{{ generalError }}</span>
        </div>

        <form
            :id="ids.form"
            ref="formEl"
            class="row g-3"
            :aria-labelledby="ids.title"
            novalidate
            @submit.prevent="submit()"
            @keydown="onFormKeydown"
        >
            <!-- 1. Tipo (segmentado) -->
            <fieldset class="col-12">
                <legend :id="ids.type" class="form-label fs-6 mb-1">
                    {{ t.form_type }} <span class="text-danger" aria-hidden="true">*</span>
                    <span class="visually-hidden">({{ t.form_required }})</span>
                </legend>
                <div class="btn-group w-100">
                    <template v-for="option in typeOptions" :key="option.value">
                        <input
                            :id="`${ids.form}-type-${option.value}`"
                            type="radio"
                            class="btn-check"
                            :name="`${ids.form}-type`"
                            :value="option.value"
                            :checked="form.type === option.value"
                            :disabled="isLocked"
                            @change="setType(option.value)"
                        >
                        <label
                            class="btn btn-sm"
                            :class="option.value === 'income' ? 'btn-outline-success' : 'btn-outline-danger'"
                            :for="`${ids.form}-type-${option.value}`"
                        >
                            <i :class="option.icon" class="me-1" aria-hidden="true"></i>{{ option.label }}
                        </label>
                    </template>
                </div>
                <div v-if="hasError('type')" class="invalid-feedback d-block">{{ firstError('type') }}</div>
            </fieldset>

            <!-- 2. Valor -->
            <div class="col-md-6">
                <label :for="ids.amount" class="form-label">
                    {{ t.form_amount }} <span class="text-danger" aria-hidden="true">*</span>
                </label>
                <MoneyInput
                    :id="ids.amount"
                    v-model="form.amount"
                    size=""
                    :invalid="hasError('amount')"
                    :disabled="isLocked"
                    :readonly="isScheduleSplit"
                    :aria-describedby="amountDescribedBy"
                    required
                    data-test="amount-input"
                />
                <div v-if="hasError('amount')" :id="ids.amountError" class="invalid-feedback d-block" data-test="amount-error">
                    {{ firstError('amount') }}
                </div>
            </div>

            <!-- 3. Descrição -->
            <div class="col-md-6">
                <label :for="ids.description" class="form-label">
                    {{ t.form_description }} <span class="text-danger" aria-hidden="true">*</span>
                </label>
                <input
                    :id="ids.description"
                    v-model="form.description"
                    type="text"
                    maxlength="255"
                    class="form-control"
                    :class="{ 'is-invalid': hasError('description') }"
                    :disabled="isLocked"
                    required
                    data-test="description-input"
                >
                <div class="invalid-feedback">{{ firstError('description') }}</div>
            </div>

            <!-- Agenda com pagamento dividido: valor e forma só pela agenda -->
            <div v-if="isScheduleSplit" class="col-12">
                <div :id="ids.scheduleLock" class="alert alert-info small d-flex flex-wrap align-items-center gap-2 mb-0 py-2" data-test="schedule-lock">
                    <i class="ti ti-calendar-event" aria-hidden="true"></i>
                    <span class="me-auto">{{ t.form_schedule_locked }}</span>
                    <Link v-if="canEditSchedule" :href="scheduleHref" class="alert-link" data-test="schedule-link">
                        {{ t.form_schedule_link }}
                    </Link>
                </div>
            </div>

            <!-- 4. Data -->
            <div class="col-md-6">
                <label :for="ids.date" class="form-label">
                    {{ t.form_date }} <span class="text-danger" aria-hidden="true">*</span>
                </label>
                <input
                    :id="ids.date"
                    v-model="form.entry_date"
                    type="date"
                    class="form-control"
                    :class="{ 'is-invalid': hasError('entry_date') }"
                    :disabled="isLocked"
                    required
                >
                <div class="invalid-feedback" data-test="entry-date-error">{{ firstError('entry_date') }}</div>
            </div>

            <!-- 5. Forma de pagamento -->
            <div class="col-md-6">
                <label :for="ids.paymentMethod" class="form-label">{{ t.form_payment_method }}</label>
                <select
                    :id="ids.paymentMethod"
                    v-model="form.payment_method"
                    class="form-select"
                    :class="{ 'is-invalid': hasError('payment_method') }"
                    :disabled="isLocked || isScheduleSplit"
                    :aria-describedby="paymentDescribedBy"
                    data-test="payment-method-input"
                >
                    <option value="">{{ t.form_payment_method_none }}</option>
                    <option v-for="method in paymentMethods" :key="method.value" :value="method.value">{{ method.label }}</option>
                </select>
                <div :id="ids.paymentError" class="invalid-feedback">{{ firstError('payment_method') }}</div>
            </div>

            <!-- 6. Categoria -->
            <div class="col-md-6">
                <label :id="ids.category" class="form-label">{{ t.form_category }}</label>
                <SearchSelect
                    v-model="form.category_id"
                    :options="filteredCategories"
                    :placeholder="t.form_category_none"
                    :invalid="hasError('category_id')"
                    :disabled="isLocked"
                    :aria-labelledby="ids.category"
                />
                <div v-if="hasError('category_id')" class="invalid-feedback d-block">{{ firstError('category_id') }}</div>
            </div>

            <!-- 7. Status -->
            <div class="col-md-6">
                <label :for="ids.status" class="form-label">{{ t.form_status }}</label>
                <select
                    :id="ids.status"
                    v-model="form.status"
                    class="form-select"
                    :class="{ 'is-invalid': hasError('status') }"
                    :disabled="isLocked"
                    data-test="status-input"
                >
                    <option v-for="option in statusOptions" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </option>
                </select>
                <div class="invalid-feedback">{{ firstError('status') }}</div>
            </div>

            <!-- Convênio (opcional) -->
            <div class="col-md-6">
                <label :id="ids.covenant" class="form-label">{{ t.form_covenant }}</label>
                <SearchSelect
                    v-model="form.covenant_id"
                    :options="covenantOptions"
                    :placeholder="t.form_covenant_none"
                    :invalid="hasError('covenant_id')"
                    :disabled="isLocked"
                    :aria-labelledby="ids.covenant"
                />
                <div v-if="hasError('covenant_id')" class="invalid-feedback d-block">{{ firstError('covenant_id') }}</div>
            </div>

            <div class="col-12">
                <label :for="ids.notes" class="form-label">{{ t.form_notes }}</label>
                <textarea
                    :id="ids.notes"
                    v-model="form.notes"
                    rows="2"
                    class="form-control"
                    :class="{ 'is-invalid': hasError('notes') }"
                    maxlength="2000"
                    :disabled="isLocked"
                ></textarea>
                <div class="invalid-feedback">{{ firstError('notes') }}</div>
            </div>
        </form>

        <template #footer>
            <template v-if="confirmingDiscard">
                <span class="me-auto small fw-medium align-self-center" role="alert" data-test="discard-prompt">
                    {{ t.form_discard_title }}
                </span>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-test="keep-editing" @click="confirmingDiscard = false">
                    {{ t.form_discard_keep }}
                </button>
                <button type="button" class="btn btn-danger btn-sm" data-test="discard" @click="requestClose">
                    {{ t.form_discard_confirm }}
                </button>
            </template>
            <template v-else>
                <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="saving" @click="requestClose">
                    {{ t.form_cancel }}
                </button>
                <button
                    v-if="!isEdit"
                    type="button"
                    class="btn btn-outline-primary btn-sm"
                    :disabled="saving"
                    data-test="submit-another"
                    @click="submit({ another: true })"
                >
                    <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ t.form_save_and_new }}
                </button>
                <button
                    type="submit"
                    class="btn btn-primary btn-sm"
                    :form="ids.form"
                    :disabled="saving || isLocked"
                    :aria-busy="saving ? 'true' : 'false'"
                    data-test="submit"
                >
                    <span v-if="saving" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                    <i v-else class="ti ti-check me-1" aria-hidden="true"></i>
                    {{ isEdit ? t.form_save : t.form_create }}
                </button>
            </template>
        </template>
    </CenteredModal>
</template>
