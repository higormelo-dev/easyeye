<script setup>
import { computed, ref, watch } from 'vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import SearchSelect from '@/Components/Panel/SearchSelect.vue';
import ReasonField from '@/Components/Panel/ReasonField.vue';
import ModalityPicker from './ModalityPicker.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { useTrans } from '@/composables/useTrans.js';
import { addMonthsNoOverflow, choice, toDateInput } from '@/utils/billingPeriods.js';
import { useSubscriptionRequest } from './useSubscriptionRequest.js';

/**
 * Nova assinatura para uma empresa ("inserir empresa"): trial, cobrança
 * automática ou cortesia. Substitui a vigente — exceto na cobrança
 * automática de quem já tem plano PAGO vigente: aí é troca de plano
 * (PlanChangeService), com a prévia antes de confirmar — upgrade (fatura da
 * diferença proporcional; o plano muda quando paga) ou downgrade (agendado
 * para o fim do período pago).
 *
 * Empresa com cobrança automática: o envio só é liberado com a prévia
 * carregada — se ela falha (rede/422), o erro aparece com "Tentar de novo"
 * (sem prévia o servidor faria a troca com o botão dizendo "Criar"). Na
 * troca o gateway é o da assinatura paga: o campo some e nada é enviado (o
 * servidor recusa outro gateway com 422).
 *
 * Upgrade: "Enviar a cobrança à clínica agora" (marcado por padrão) — depois
 * de criada a fatura da diferença, e-mail + WhatsApp aos contatos de cobrança
 * com o link para pagar em Minha assinatura (send-charge). Se o envio falhar,
 * o upgrade continua feito e o aviso diz para enviar pelo detalhe.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    plans: { type: Array, default: () => [] }, // [{ id, name, active, default_cycle, prices: [...] }]
    billingCycles: { type: Array, default: () => [] }, // [{ value, label, period_label, months }]
    gateways: { type: Array, default: () => [] },
    trialDays: { type: Number, default: 7 },
    // Pré-seleção vinda da linha/tela de origem: { entity_id, plan_id, mode }
    preset: { type: Object, default: () => ({}) },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close', 'saved']);

const { money, date } = useLocaleFormat();
const { tx } = useTrans(() => props.t);
const { tx: txCharge } = useTrans(() => props.t.charge_notice ?? {});
const { saving, errors, message, send, reset } = useSubscriptionRequest(() => props.t.request_failed);

const MODES = ['trial', 'gateway', 'complimentary'];
// Acesso sem cobrança: exige justificativa (auditoria).
const QUICK_PERIODS = [
    { unit: 'months', n: 1 },
    { unit: 'months', n: 3 },
    { unit: 'months', n: 6 },
    { unit: 'years', n: 1 },
];

const form = ref({});
const notifyClinic = ref(true);
const entityOptions = ref([]);
const selectedEntity = ref(null);

function today() {
    return toDateInput(new Date());
}

function resetForm() {
    reset();
    form.value = {
        entity_id: props.preset.entity_id ?? '',
        plan_id: props.preset.plan_id ?? props.plans.find((p) => p.active)?.id ?? '',
        mode: props.preset.mode ?? 'complimentary',
        billing_cycle: '',
        gateway: '',
        trial_days: props.trialDays,
        starts_at: today(),
        ends_at: '',
        reason: '',
    };
    selectedEntity.value = null;
    notifyClinic.value = true;
    syncCycle();
    applyQuickPeriod(QUICK_PERIODS[0]);
}

async function loadEntities(query = {}) {
    const url = route('manager.subscriptions.entities', query);
    const { data } = await window.axios.get(url, { headers: { Accept: 'application/json' } });

    return Array.isArray(data?.data) ? data.data : [];
}

watch(
    () => props.open,
    async (isOpen) => {
        if (!isOpen) return;

        resetForm();

        try {
            entityOptions.value = await loadEntities();

            if (form.value.entity_id && !entityOptions.value.some((e) => e.id === form.value.entity_id)) {
                entityOptions.value = [...(await loadEntities({ id: form.value.entity_id })), ...entityOptions.value];
            }

            selectedEntity.value = entityOptions.value.find((e) => e.id === form.value.entity_id) ?? null;
        } catch {
            entityOptions.value = [];
        }
    },
);

function onEntitySelected(option) {
    selectedEntity.value = option ?? null;
}

// ── Plano e ciclo ───────────────────────────────────────────────────────────
const planOptions = computed(() =>
    props.plans
        .filter((p) => p.active || p.id === form.value.plan_id)
        .map((p) => ({ id: p.id, name: p.active ? p.name : `${p.name} ${props.t.plan_inactive_suffix}` })),
);

const selectedPlan = computed(() => props.plans.find((p) => p.id === form.value.plan_id) ?? null);
const planPrices = computed(() => Object.fromEntries((selectedPlan.value?.prices ?? []).map((p) => [p.cycle, p])));

// Trial e cobrança automática: só os ciclos que o plano vende (com preço).
const cycleOptions = computed(() =>
    props.billingCycles
        .filter((c) => planPrices.value[c.value])
        .map((c) => ({ value: c.value, label: `${c.label} — ${money(planPrices.value[c.value].price)}` })),
);

function syncCycle() {
    const offered = cycleOptions.value.map((c) => c.value);
    if (!offered.includes(form.value.billing_cycle)) {
        form.value.billing_cycle = selectedPlan.value?.default_cycle ?? offered[0] ?? '';
    }
}

watch(() => form.value.plan_id, syncCycle);
watch(() => form.value.mode, syncCycle);

const cyclePrice = computed(() => planPrices.value[form.value.billing_cycle] ?? null);

// ── Período ─────────────────────────────────────────────────────────────────
function periodLabel(period) {
    return choice(props.t[`period_chip_${period.unit}`], period.n, { n: period.n });
}

function applyQuickPeriod(period) {
    const start = new Date(`${form.value.starts_at || today()}T00:00:00`);
    const months = period.unit === 'years' ? period.n * 12 : period.n;
    form.value.ends_at = toDateInput(addMonthsNoOverflow(start, months));
}

function isQuickActive(period) {
    const start = new Date(`${form.value.starts_at || today()}T00:00:00`);
    const months = period.unit === 'years' ? period.n * 12 : period.n;

    return form.value.ends_at === toDateInput(addMonthsNoOverflow(start, months));
}

// ── Situação atual da empresa ───────────────────────────────────────────────
const current = computed(() => selectedEntity.value?.current ?? null);

const currentSummary = computed(() => {
    if (!current.value) return '';

    const until = current.value.open_ended
        ? props.t.company_current_no_end
        : tx('period_until', { date: date(current.value.access_ends_at) });

    return `${tx('company_current', {
        plan: current.value.plan_name ?? '—',
        modality: props.t.modality?.[current.value.modality] ?? current.value.modality,
        status: current.value.status_label,
    })} · ${until}`;
});

const replacesGatewayBilling = computed(() => current.value?.modality === 'gateway' && current.value?.has_access);

// ── Troca de plano de quem já paga (prévia do servidor) ─────────────────────
const preview = ref({ loading: false, loaded: false, change: null, gateway: null, error: '' });
const change = computed(() => (form.value.mode === 'gateway' ? preview.value.change : null));
let previewSeq = 0;

// Cobrança automática para empresa que já cobra pelo gateway: pode ser troca
// de plano — só o servidor sabe (prévia). Sem a prévia, nada é enviado.
const needsPreview = computed(() => {
    const f = form.value;

    return (
        f.mode === 'gateway' &&
        !!f.entity_id &&
        !!f.plan_id &&
        !!f.billing_cycle &&
        current.value?.modality === 'gateway'
    );
});
const previewPending = computed(
    () => needsPreview.value && (preview.value.loading || !preview.value.loaded || !!preview.value.error),
);
// Escolher gateway só numa contratação nova (a prévia disse que não é troca).
const showGatewayField = computed(() => !needsPreview.value || (!previewPending.value && !change.value));

async function loadPreview() {
    const f = form.value;
    const seq = ++previewSeq;

    if (!needsPreview.value) {
        preview.value = { loading: false, loaded: false, change: null, gateway: null, error: '' };

        return;
    }

    preview.value = { ...preview.value, loading: true, loaded: false, change: null, error: '' };
    try {
        const url = route('manager.subscriptions.change-preview', {
            entity_id: f.entity_id,
            plan_id: f.plan_id,
            billing_cycle: f.billing_cycle,
        });
        const { data } = await window.axios.get(url, { headers: { Accept: 'application/json' } });
        if (seq !== previewSeq) return;
        preview.value = {
            loading: false,
            loaded: true,
            change: data?.data?.change ?? null,
            gateway: data?.data?.gateway ?? null,
            error: '',
        };
    } catch (e) {
        if (seq !== previewSeq) return;
        preview.value = {
            loading: false,
            loaded: false,
            change: null,
            gateway: null,
            error: e?.response?.data?.message ?? props.t.change_preview_failed ?? props.t.request_failed,
        };
    }
}

watch(
    () => [form.value.mode, form.value.entity_id, form.value.plan_id, form.value.billing_cycle, current.value?.id],
    () => loadPreview(),
);

function cycleLabelOf(value) {
    return props.billingCycles.find((c) => c.value === value)?.label ?? value ?? '';
}

const changeText = computed(() => {
    const c = change.value;
    if (!c) return '';

    if (c.type === 'upgrade') {
        return tx('change_preview_upgrade', {
            amount: money(c.amount_now),
            days: c.remaining_days,
            next_amount: money(c.new_amount),
            next: date(c.next_charge_at),
        });
    }

    if (c.type === 'scheduled') {
        return tx('change_preview_scheduled', {
            plan: c.plan?.name ?? '',
            cycle: cycleLabelOf(c.cycle),
            date: date(c.effective_at),
            amount: money(c.new_amount),
        });
    }

    return props.t.change_preview_same;
});

const submitLabel = computed(() => {
    if (change.value?.type === 'upgrade') return props.t.btn_change_upgrade;
    if (change.value?.type === 'scheduled') return props.t.btn_change_schedule;

    return props.t.btn_create;
});

// ── Envio ───────────────────────────────────────────────────────────────────
const needsReason = computed(() => form.value.mode === 'complimentary');
const reasonField = ref(null);

const canSubmit = computed(() => {
    if (saving.value || !form.value.entity_id || !form.value.plan_id) return false;
    // Troca de plano: só com a prévia carregada sem erro (e não "o mesmo plano").
    if (previewPending.value || (form.value.mode === 'gateway' && change.value?.type === 'current')) return false;

    // Opcional fora das modalidades sem cobrança, mas se escrita vale o mínimo.
    return reasonField.value?.valid ?? !needsReason.value;
});

function payload() {
    const f = form.value;
    const base = { entity_id: f.entity_id, plan_id: f.plan_id, mode: f.mode, reason: f.reason || undefined };

    switch (f.mode) {
        case 'trial':
            return { ...base, trial_days: f.trial_days, billing_cycle: f.billing_cycle || undefined };
        case 'gateway':
            // Na troca de plano o gateway é o da assinatura paga: não vai.
            return {
                ...base,
                billing_cycle: f.billing_cycle,
                gateway: showGatewayField.value ? f.gateway || undefined : undefined,
            };
        default:
            return { ...base, starts_at: f.starts_at, ends_at: f.ends_at };
    }
}

async function submit() {
    if (!canSubmit.value) return;

    const wasUpgrade = change.value?.type === 'upgrade';
    const data = await send('post', route('manager.subscriptions.store'), payload());
    if (!data) return;

    let message = data.message;
    const invoiceId = data.data?.invoice_id;

    if (wasUpgrade && notifyClinic.value && invoiceId && data.data?.id) {
        message = `${message} ${await sendChargeAfterUpgrade(data.data.id, invoiceId)}`;
    }

    emit('saved', message);
    emit('close');
}

/** Envia a fatura da diferença à clínica; o upgrade já está feito mesmo se falhar. */
async function sendChargeAfterUpgrade(subscriptionId, invoiceId) {
    try {
        const { data } = await window.axios.post(
            route('manager.subscriptions.invoices.send-charge', { subscription: subscriptionId, invoice: invoiceId }),
            {},
            { headers: { Accept: 'application/json' } },
        );

        return data?.message ?? '';
    } catch (e) {
        return txCharge('after_create_failed', {
            error: e?.response?.data?.message ?? props.t.request_failed ?? '',
        });
    }
}

function close() {
    if (!saving.value) emit('close');
}
</script>

<template>
    <CenteredModal :open="open" size="lg" @close="close">
        <template #header>
            <h5 class="mb-0 fw-semibold">
                <i class="ti ti-file-plus me-2 text-primary" aria-hidden="true"></i>{{ t.create_title }}
            </h5>
        </template>

        <form class="d-grid gap-3" novalidate @submit.prevent="submit">
            <!-- Empresa -->
            <div>
                <label class="form-label fw-medium"
                    >{{ t.field_company }} <span class="text-danger" aria-hidden="true">*</span></label
                >
                <SearchSelect
                    v-model="form.entity_id"
                    :options="entityOptions"
                    value-key="id"
                    label-key="name"
                    :remote-search-url="`${route('manager.subscriptions.entities')}?search=__Q__`"
                    :placeholder="t.company_search_placeholder"
                    :invalid="!!errors.entity_id"
                    show-sub-label
                    @option-selected="onEntitySelected"
                />
                <div v-if="errors.entity_id" class="invalid-feedback d-block">{{ errors.entity_id }}</div>

                <div v-if="selectedEntity" class="small mt-2" data-test="company-status">
                    <template v-if="current">
                        <div class="text-muted">{{ currentSummary }}</div>
                        <div v-if="!change" class="text-warning-emphasis mt-1">
                            <i class="ti ti-replace me-1" aria-hidden="true"></i>{{ t.company_replace_warning }}
                            <template v-if="replacesGatewayBilling"> {{ t.company_replace_gateway }}</template>
                        </div>
                    </template>
                    <div v-else class="text-muted">{{ t.company_none }}</div>
                    <div v-if="!selectedEntity.active" class="text-danger mt-1">
                        <i class="ti ti-lock me-1" aria-hidden="true"></i>{{ t.company_blocked }}
                    </div>
                </div>
            </div>

            <!-- Plano -->
            <div>
                <label class="form-label fw-medium"
                    >{{ t.field_plan }} <span class="text-danger" aria-hidden="true">*</span></label
                >
                <SearchSelect
                    v-model="form.plan_id"
                    :options="planOptions"
                    :placeholder="t.select_placeholder"
                    :clearable="false"
                    :invalid="!!errors.plan_id"
                />
                <div v-if="errors.plan_id" class="invalid-feedback d-block">{{ errors.plan_id }}</div>
            </div>

            <!-- Modalidade -->
            <ModalityPicker v-model="form.mode" :modes="MODES" name="sub-create-mode" :legend="t.field_mode" :t="t" />

            <!-- Trial -->
            <div v-if="form.mode === 'trial'" class="row g-3">
                <div class="col-sm-5">
                    <label for="sub-trial-days" class="form-label">{{ t.field_trial_days }}</label>
                    <input
                        id="sub-trial-days"
                        v-model.number="form.trial_days"
                        type="number"
                        min="1"
                        max="365"
                        class="form-control"
                        :class="{ 'is-invalid': errors.trial_days }"
                        aria-describedby="sub-trial-hint"
                    />
                    <div v-if="errors.trial_days" class="invalid-feedback">{{ errors.trial_days }}</div>
                    <div id="sub-trial-hint" class="form-text">{{ tx('trial_days_hint', { days: trialDays }) }}</div>
                </div>
                <div class="col-sm-7">
                    <label class="form-label">{{ t.field_cycle }}</label>
                    <SearchSelect
                        v-model="form.billing_cycle"
                        :options="cycleOptions"
                        value-key="value"
                        label-key="label"
                        :clearable="false"
                    />
                </div>
            </div>

            <!-- Cobrança automática: ciclo (com preço) e gateway -->
            <div v-if="form.mode === 'gateway'" class="row g-3">
                <div class="col-sm-7">
                    <label class="form-label"
                        >{{ t.field_cycle }} <span class="text-danger" aria-hidden="true">*</span></label
                    >
                    <SearchSelect
                        v-model="form.billing_cycle"
                        :options="cycleOptions"
                        value-key="value"
                        label-key="label"
                        :clearable="false"
                        :invalid="!!errors.billing_cycle"
                    />
                    <div v-if="errors.billing_cycle" class="invalid-feedback d-block">{{ errors.billing_cycle }}</div>
                    <div v-else-if="cyclePrice" class="form-text">
                        {{ tx('gateway_charge_note', { amount: money(cyclePrice.price), cycle: cyclePrice.label }) }}
                    </div>
                </div>

                <div v-if="showGatewayField" class="col-sm-5" data-test="gateway-field">
                    <label class="form-label">{{ t.field_gateway }}</label>
                    <SearchSelect
                        v-model="form.gateway"
                        :options="gateways"
                        value-key="value"
                        label-key="label"
                        :placeholder="t.gateway_default"
                    />
                    <div class="form-text">{{ t.gateway_hint }}</div>
                </div>
            </div>

            <!-- Troca de plano de quem já paga: o que acontece, antes de confirmar -->
            <div
                v-if="form.mode === 'gateway' && preview.loading"
                class="small text-muted"
                role="status"
                data-test="plan-change-loading"
            >
                <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span
                >{{ t.change_preview_loading }}
            </div>
            <div
                v-else-if="change"
                class="alert small mb-0"
                :class="
                    change.type === 'upgrade'
                        ? 'alert-primary text-primary-emphasis'
                        : change.type === 'scheduled'
                          ? 'alert-info text-info-emphasis'
                          : 'alert-secondary'
                "
                role="note"
                data-test="plan-change-preview"
                :data-type="change.type"
            >
                <strong v-if="change.type !== 'current'" class="d-block mb-1">{{
                    change.type === 'upgrade' ? t.change_preview_title_upgrade : t.change_preview_title_scheduled
                }}</strong>
                {{ changeText }}
                <span v-if="change.reason === 'small_difference'" class="d-block mt-1">{{
                    t.change_preview_small
                }}</span>
                <span v-if="change.type === 'upgrade'" class="d-block mt-1">{{ t.change_preview_unpaid_note }}</span>
                <span v-if="change.type !== 'current' && preview.gateway" class="d-block mt-1">{{
                    tx('change_preview_gateway_kept', { gateway: preview.gateway })
                }}</span>
                <div v-if="change.type === 'upgrade'" class="form-check mt-2 mb-0" data-test="plan-change-notify">
                    <input
                        id="scm-notify-clinic"
                        v-model="notifyClinic"
                        class="form-check-input"
                        type="checkbox"
                        data-test="plan-change-notify-input"
                    />
                    <label class="form-check-label" for="scm-notify-clinic">{{ t.charge_notice?.after_create }}</label>
                    <small class="d-block text-body-secondary">{{ t.charge_notice?.after_create_hint }}</small>
                </div>
            </div>
            <div
                v-else-if="form.mode === 'gateway' && preview.error"
                class="alert alert-danger small mb-0 d-flex flex-wrap align-items-center gap-2"
                role="alert"
                data-test="plan-change-error"
            >
                <span class="flex-grow-1">{{ preview.error }}</span>
                <button
                    type="button"
                    class="btn btn-sm btn-outline-danger"
                    data-test="plan-change-retry"
                    @click="loadPreview"
                >
                    <i class="ti ti-refresh me-1" aria-hidden="true"></i>{{ t.change_preview_retry }}
                </button>
            </div>

            <!-- Período da cortesia -->
            <div v-if="form.mode === 'complimentary'" class="row g-3">
                <div class="col-sm-6">
                    <label for="sub-starts" class="form-label">{{ t.field_starts_at }}</label>
                    <input
                        id="sub-starts"
                        v-model="form.starts_at"
                        type="date"
                        :max="today()"
                        class="form-control"
                        :class="{ 'is-invalid': errors.starts_at }"
                    />
                    <div v-if="errors.starts_at" class="invalid-feedback">{{ errors.starts_at }}</div>
                </div>
                <div class="col-sm-6">
                    <label for="sub-ends" class="form-label"
                        >{{ t.field_ends_at }} <span class="text-danger" aria-hidden="true">*</span></label
                    >
                    <input
                        id="sub-ends"
                        v-model="form.ends_at"
                        type="date"
                        :min="form.starts_at"
                        class="form-control"
                        :class="{ 'is-invalid': errors.ends_at }"
                    />
                    <div v-if="errors.ends_at" class="invalid-feedback">{{ errors.ends_at }}</div>
                </div>
                <div class="col-12">
                    <span class="form-label small d-block mb-1">{{ t.quick_period }}</span>
                    <div class="d-flex flex-wrap gap-2" role="group" :aria-label="t.quick_period">
                        <button
                            v-for="period in QUICK_PERIODS"
                            :key="`${period.unit}-${period.n}`"
                            type="button"
                            class="btn btn-sm"
                            :class="isQuickActive(period) ? 'btn-primary' : 'btn-outline-secondary'"
                            :aria-pressed="isQuickActive(period)"
                            @click="applyQuickPeriod(period)"
                        >
                            {{ periodLabel(period) }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Justificativa -->
            <ReasonField
                ref="reasonField"
                v-model="form.reason"
                :required="needsReason"
                :label="needsReason ? t.field_reason : t.field_reason_optional"
                :hint="t.reason_hint"
                :placeholder="t.reason_placeholder"
                :error="errors.reason"
            />

            <div v-if="message" class="alert alert-danger small d-flex gap-2 mb-0" role="alert">
                <i class="ti ti-alert-circle mt-1" aria-hidden="true"></i><span>{{ message }}</span>
            </div>
        </form>

        <template #footer>
            <button type="button" class="btn btn-light" :disabled="saving" @click="close">{{ t.btn_cancel }}</button>
            <button type="button" class="btn btn-primary" :disabled="!canSubmit" @click="submit">
                <span v-if="saving" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                {{ submitLabel }}
            </button>
        </template>
    </CenteredModal>
</template>
