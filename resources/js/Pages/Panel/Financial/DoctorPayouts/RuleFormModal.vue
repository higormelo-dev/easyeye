<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';
import MoneyInput     from '@/Components/Panel/MoneyInput.vue';
import { useDoctorPayoutFormat } from './useDoctorPayoutFormat.js';
import {
    ALL_TYPES, CALCULATIONS, PAYER_SCOPES, SERVICE_TYPES,
    decodeItem, emptyRuleForm, encodeItem, itemKindsFor, itemOptionsFor, ruleToForm, rulePayload,
} from './ruleForm.js';

/**
 * Criar/editar regra de repasse (a listagem já traz a regra completa — sem
 * fetch ao abrir, como em AccessControl/Roles/RoleFormModal).
 *
 * - "Todos os tipos" só ao criar (o servidor cria uma regra por tipo).
 * - "Aplicar a" lista só os itens do tipo de serviço escolhido; trocar o tipo
 *   limpa um item que não pertence mais a ele. Vai no máximo UM id de item.
 * - Percentual ou valor fixo conforme o cálculo; convênio só com pagador
 *   "Convênio". Erros do servidor aparecem em cada campo.
 */
const props = defineProps({
    open:    { type: Boolean, required: true },
    rule:    { type: Object,  default: null },
    options: { type: Object,  default: () => ({}) },   // { doctors, visit_types, procedures, exam_types, covenants }
    routes:  { type: Object,  required: true },        // { store, update } — update com __ID__
    t:       { type: Object,  default: () => ({}) },
});

const emit = defineEmits(['close']);

const { doctorLabel, serviceTypeLabel } = useDoctorPayoutFormat(() => props.t);

const KIND_LABEL_KEYS = { visit_type: 'form_visit_type', procedure: 'form_procedure', exam_type: 'form_exam_type' };

const isEdit = computed(() => !!props.rule);
const title  = computed(() => (isEdit.value ? props.t.rules_edit : props.t.rules_new));

const form = useForm(emptyRuleForm());

const serviceTypes = computed(() => (isEdit.value ? SERVICE_TYPES : [...SERVICE_TYPES, ALL_TYPES]));
const isAllTypes   = computed(() => form.service_type === ALL_TYPES);

/** Item da regra em edição: pode ter sido desativado e não vir nas listas. */
const currentItem = computed(() => {
    const rule = props.rule;
    if (!rule?.item_kind || !rule.item_id || rule.service_type !== form.service_type) return null;

    return { kind: rule.item_kind, id: rule.item_id, name: rule.item_name ?? rule.item_id };
});

const itemGroups = computed(() => itemKindsFor(form.service_type)
    .map((kind) => {
        const options = itemOptionsFor(kind, form.service_type, props.options);
        const current = currentItem.value;
        const extra   = current?.kind === kind && !options.some((option) => option.id === current.id)
            ? [{ id: current.id, name: current.name }]
            : [];

        return { kind, label: props.t[KIND_LABEL_KEYS[kind]] ?? kind, options: [...extra, ...options] };
    })
    .filter((group) => group.options.length > 0));

function itemAvailable(value) {
    if (!value) return true;

    const { kind, id } = decodeItem(value);

    return itemGroups.value.some((group) => group.kind === kind && group.options.some((option) => option.id === id));
}

watch(() => form.service_type, () => {
    if (isAllTypes.value || !itemAvailable(form.item)) form.item = '';
});

/** Convênios "de verdade" (particular já é o pagador "Particular"); o da regra editada sempre aparece. */
const covenants = computed(() => {
    const list = (props.options.covenants ?? []).filter((covenant) => !covenant.particular || covenant.id === form.covenant_id);
    const rule = props.rule;

    if (rule?.covenant_id && !list.some((covenant) => covenant.id === rule.covenant_id)) {
        return [{ id: rule.covenant_id, name: rule.covenant_name ?? rule.covenant_id }, ...list];
    }

    return list;
});

const itemError = computed(() => form.errors.visit_type_id || form.errors.procedure_id || form.errors.exam_type_id || '');

const err = (field) => form.errors?.[field] ?? '';

/** aria-describedby: dicas do campo + mensagem de erro, quando houver. */
function describedBy(field, ...hints) {
    const parts = [...hints, err(field) ? `rule_${field}_error` : null].filter(Boolean);

    return parts.length ? parts.join(' ') : undefined;
}

function resetForm() {
    form.reset();
    form.clearErrors();
    Object.assign(form, props.rule ? ruleToForm(props.rule) : emptyRuleForm());
}

watch(() => props.open, (isOpen) => { if (isOpen) resetForm(); });

function submit() {
    if (form.processing) return;

    const options = { preserveScroll: true, onSuccess: () => emit('close') };

    form.transform((data) => rulePayload(data));

    if (isEdit.value) {
        form.put(props.routes.update.replace('__ID__', props.rule.id), options);
    } else {
        form.post(props.routes.store, options);
    }
}
</script>

<template>
    <OffcanvasPanel :open="open" :width="640" @close="$emit('close')">
        <template #header>
            <h5 class="mb-0 fw-semibold">
                <i class="ti ti-adjustments-dollar me-2 text-primary" aria-hidden="true"></i>{{ title }}
            </h5>
        </template>

        <form novalidate data-test="rule-form" @submit.prevent="submit">
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label class="form-label" for="rule_doctor_id">{{ t.form_doctor }}</label>
                    <select
                        id="rule_doctor_id"
                        v-model="form.doctor_id"
                        class="form-select"
                        :class="{ 'is-invalid': err('doctor_id') }"
                        :aria-invalid="err('doctor_id') ? 'true' : undefined"
                        :aria-describedby="describedBy('doctor_id')"
                    >
                        <option value="">{{ t.all_doctors }}</option>
                        <option v-for="doctor in options.doctors ?? []" :key="doctor.id" :value="doctor.id">{{ doctorLabel(doctor) }}</option>
                    </select>
                    <div v-if="err('doctor_id')" id="rule_doctor_id_error" class="invalid-feedback d-block">{{ err('doctor_id') }}</div>
                </div>

                <div class="col-12 col-md-6">
                    <label class="form-label" for="rule_service_type">{{ t.form_service_type }}</label>
                    <select
                        id="rule_service_type"
                        v-model="form.service_type"
                        class="form-select"
                        :class="{ 'is-invalid': err('service_type') }"
                        :aria-invalid="err('service_type') ? 'true' : undefined"
                        :aria-describedby="describedBy('service_type', isAllTypes ? 'rule_all_types_hint' : null)"
                    >
                        <option v-for="type in serviceTypes" :key="type" :value="type">{{ serviceTypeLabel(type) }}</option>
                    </select>
                    <div v-if="isAllTypes" id="rule_all_types_hint" class="form-text" data-test="all-types-hint">{{ t.form_all_types_hint }}</div>
                    <div v-if="err('service_type')" id="rule_service_type_error" class="invalid-feedback d-block">{{ err('service_type') }}</div>
                </div>

                <div v-if="!isAllTypes" class="col-12">
                    <label class="form-label" for="rule_item">{{ t.form_item_kind }}</label>
                    <select
                        id="rule_item"
                        v-model="form.item"
                        class="form-select"
                        :class="{ 'is-invalid': itemError }"
                        :aria-invalid="itemError ? 'true' : undefined"
                        :aria-describedby="itemError ? 'rule_item_error' : undefined"
                    >
                        <option value="">{{ t.form_item_any }}</option>
                        <optgroup v-for="group in itemGroups" :key="group.kind" :label="group.label" :data-kind="group.kind">
                            <option v-for="option in group.options" :key="option.id" :value="encodeItem(group.kind, option.id)">{{ option.name }}</option>
                        </optgroup>
                    </select>
                    <div v-if="itemError" id="rule_item_error" class="invalid-feedback d-block">{{ itemError }}</div>
                </div>

                <div class="col-12 col-md-6">
                    <label class="form-label" for="rule_payer_scope">{{ t.form_payer_scope }}</label>
                    <select
                        id="rule_payer_scope"
                        v-model="form.payer_scope"
                        class="form-select"
                        :class="{ 'is-invalid': err('payer_scope') }"
                        :aria-invalid="err('payer_scope') ? 'true' : undefined"
                        :aria-describedby="describedBy('payer_scope')"
                    >
                        <option v-for="scope in PAYER_SCOPES" :key="scope" :value="scope">{{ t.payer_scopes?.[scope] ?? scope }}</option>
                    </select>
                    <div v-if="err('payer_scope')" id="rule_payer_scope_error" class="invalid-feedback d-block">{{ err('payer_scope') }}</div>
                </div>

                <div v-if="form.payer_scope === 'covenant'" class="col-12 col-md-6">
                    <label class="form-label" for="rule_covenant_id">{{ t.form_covenant }}</label>
                    <select
                        id="rule_covenant_id"
                        v-model="form.covenant_id"
                        class="form-select"
                        :class="{ 'is-invalid': err('covenant_id') }"
                        :aria-invalid="err('covenant_id') ? 'true' : undefined"
                        :aria-describedby="describedBy('covenant_id')"
                    >
                        <option value="">{{ t.form_covenant_any }}</option>
                        <option v-for="covenant in covenants" :key="covenant.id" :value="covenant.id">{{ covenant.name }}</option>
                    </select>
                    <div v-if="err('covenant_id')" id="rule_covenant_id_error" class="invalid-feedback d-block">{{ err('covenant_id') }}</div>
                </div>

                <fieldset class="col-12">
                    <legend class="form-label fs-6 mb-2">{{ t.form_calculation }}</legend>
                    <!-- O fieldset/legend já agrupa e nomeia as opções (radios nativos). -->
                    <div class="btn-group">
                        <template v-for="calculation in CALCULATIONS" :key="calculation">
                            <input
                                :id="`rule_calculation_${calculation}`"
                                v-model="form.calculation"
                                type="radio"
                                class="btn-check"
                                name="rule_calculation"
                                :value="calculation"
                                :data-test="`calculation-${calculation}`"
                            >
                            <label class="btn btn-outline-primary btn-sm" :for="`rule_calculation_${calculation}`">{{ t.calculations?.[calculation] ?? calculation }}</label>
                        </template>
                    </div>
                    <div v-if="err('calculation')" id="rule_calculation_error" class="invalid-feedback d-block">{{ err('calculation') }}</div>
                </fieldset>

                <div v-if="form.calculation === 'percentage'" class="col-12 col-md-6">
                    <label class="form-label" for="rule_percentage">{{ t.form_percentage }}</label>
                    <input
                        id="rule_percentage"
                        v-model="form.percentage"
                        type="number"
                        min="0"
                        max="100"
                        step="0.01"
                        inputmode="decimal"
                        class="form-control"
                        :class="{ 'is-invalid': err('percentage') }"
                        :aria-invalid="err('percentage') ? 'true' : undefined"
                        :aria-describedby="describedBy('percentage')"
                    >
                    <div v-if="err('percentage')" id="rule_percentage_error" class="invalid-feedback d-block">{{ err('percentage') }}</div>
                </div>
                <div v-else class="col-12 col-md-6">
                    <label class="form-label" for="rule_fixed_amount">{{ t.form_fixed_amount }}</label>
                    <MoneyInput
                        id="rule_fixed_amount"
                        v-model="form.fixed_amount"
                        size=""
                        :invalid="Boolean(err('fixed_amount'))"
                        :aria-describedby="describedBy('fixed_amount')"
                    />
                    <div v-if="err('fixed_amount')" id="rule_fixed_amount_error" class="invalid-feedback d-block">{{ err('fixed_amount') }}</div>
                </div>

                <div class="col-6">
                    <label class="form-label" for="rule_valid_from">{{ t.form_valid_from }}</label>
                    <input
                        id="rule_valid_from"
                        v-model="form.valid_from"
                        type="date"
                        class="form-control"
                        :class="{ 'is-invalid': err('valid_from') }"
                        :aria-invalid="err('valid_from') ? 'true' : undefined"
                        :aria-describedby="describedBy('valid_from', 'rule_validity_hint')"
                    >
                    <div v-if="err('valid_from')" id="rule_valid_from_error" class="invalid-feedback d-block">{{ err('valid_from') }}</div>
                </div>
                <div class="col-6">
                    <label class="form-label" for="rule_valid_until">{{ t.form_valid_until }}</label>
                    <input
                        id="rule_valid_until"
                        v-model="form.valid_until"
                        type="date"
                        class="form-control"
                        :min="form.valid_from || undefined"
                        :class="{ 'is-invalid': err('valid_until') }"
                        :aria-invalid="err('valid_until') ? 'true' : undefined"
                        :aria-describedby="describedBy('valid_until', 'rule_validity_hint')"
                    >
                    <div v-if="err('valid_until')" id="rule_valid_until_error" class="invalid-feedback d-block">{{ err('valid_until') }}</div>
                </div>
                <p id="rule_validity_hint" class="col-12 form-text mt-1">{{ t.form_validity_hint }}</p>

                <div class="col-12">
                    <div class="form-check form-switch">
                        <input id="rule_active" v-model="form.active" type="checkbox" role="switch" class="form-check-input">
                        <label class="form-check-label" for="rule_active">{{ t.form_active }}</label>
                    </div>
                </div>

                <div class="col-12">
                    <label class="form-label" for="rule_notes">{{ t.form_notes }}</label>
                    <textarea
                        id="rule_notes"
                        v-model="form.notes"
                        rows="2"
                        maxlength="1000"
                        class="form-control"
                        :class="{ 'is-invalid': err('notes') }"
                        :aria-invalid="err('notes') ? 'true' : undefined"
                        :aria-describedby="describedBy('notes')"
                    ></textarea>
                    <div v-if="err('notes')" id="rule_notes_error" class="invalid-feedback d-block">{{ err('notes') }}</div>
                </div>
            </div>
        </form>

        <template #footer>
            <button type="button" class="btn btn-light" @click="$emit('close')">{{ t.form_cancel }}</button>
            <button type="button" class="btn btn-primary" :disabled="form.processing" data-test="rule-submit" @click="submit">
                <span v-if="form.processing" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                {{ t.form_save }}
            </button>
        </template>
    </OffcanvasPanel>
</template>
