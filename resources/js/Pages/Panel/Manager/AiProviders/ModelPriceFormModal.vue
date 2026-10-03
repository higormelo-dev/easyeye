<script setup>
import { computed, ref, watch } from 'vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';

/**
 * Cadastro/edição de um modelo do catálogo (preço em USD por 1 milhão de
 * tokens). Preço digitado à mão fica travado — a sincronização não
 * sobrescreve —, a não ser que o admin desmarque.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    price: { type: Object, default: null }, // null = novo
    providers: { type: Array, default: () => [] },
    defaultProvider: { type: String, default: '' },
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close', 'saved']);

const isEdit = computed(() => !!props.price);
const form = ref(blank());
const errors = ref({});
const saving = ref(false);
let lockTouched = false;

function blank() {
    return {
        provider: props.defaultProvider || props.providers[0]?.code || 'openai',
        model: '',
        input_usd_per_million: null,
        output_usd_per_million: null,
        reasoning_usd_per_million: null,
        active: true,
        price_locked: true,
    };
}

watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) return;
        errors.value = {};
        lockTouched = false;
        const r = props.price;
        form.value = r
            ? {
                  provider: r.provider,
                  model: r.model,
                  input_usd_per_million: r.input_usd_per_million,
                  output_usd_per_million: r.output_usd_per_million,
                  reasoning_usd_per_million: r.reasoning_usd_per_million,
                  active: r.active,
                  price_locked: r.price_locked,
              }
            : blank();
    },
);

// Mudou o preço à mão: trava (a sincronização não desfaz) — o admin pode desmarcar.
watch(
    () => [form.value.input_usd_per_million, form.value.output_usd_per_million, form.value.reasoning_usd_per_million],
    (now) => {
        const r = props.price;
        if (!r || lockTouched) return;
        const original = [r.input_usd_per_million, r.output_usd_per_million, r.reasoning_usd_per_million];
        if (now.some((v, i) => (v === '' ? null : v) !== (original[i] ?? null))) form.value.price_locked = true;
    },
);

function touchLock() {
    lockTouched = true;
}

const tr = (key, fallback) => props.t[key] ?? fallback;
const filled = (v) => v !== null && v !== '' && v !== undefined;
const canSave = computed(
    () =>
        !saving.value &&
        !!form.value.model &&
        filled(form.value.input_usd_per_million) &&
        filled(form.value.output_usd_per_million),
);

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function submit() {
    if (!canSave.value) return;
    saving.value = true;
    errors.value = {};
    try {
        const payload = {
            ...form.value,
            reasoning_usd_per_million: filled(form.value.reasoning_usd_per_million)
                ? form.value.reasoning_usd_per_million
                : null,
        };
        const url = isEdit.value
            ? route('manager.ai-model-prices.update', props.price.id)
            : route('manager.ai-model-prices.store');
        const res = await fetch(url, {
            method: isEdit.value ? 'PATCH' : 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify(isEdit.value ? { ...payload, tool_call_usd: props.price.tool_call_usd } : payload),
        });
        const json = await res.json().catch(() => ({}));

        if (!res.ok) {
            // Erro de campo fica no campo; regra de negócio (ex.: duplicado) no topo.
            errors.value = Object.fromEntries(Object.entries(json.errors ?? {}).map(([k, v]) => [k, [].concat(v)[0]]));
            if (!Object.keys(errors.value).length) errors.value = { _form: json.message ?? '' };
            return;
        }

        emit('saved', json.message);
    } catch {
        errors.value = { _form: tr('test_failed', 'Erro') };
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <CenteredModal :open="open" size="md" :close-label="t.close" @close="$emit('close')">
        <template #header>
            <h5 class="mb-0 fw-semibold">
                <i :class="`ti ${isEdit ? 'ti-edit' : 'ti-plus'} me-2 text-primary`" aria-hidden="true"></i
                >{{ isEdit ? tr('action_edit_price', 'Editar preço') : tr('price_new', 'Novo modelo') }}
            </h5>
        </template>

        <form id="model-price-form" novalidate data-price-form @submit.prevent="submit">
            <div v-if="errors._form" class="alert alert-danger py-2 small" role="alert">{{ errors._form }}</div>

            <div class="row g-3">
                <div class="col-12 col-md-5">
                    <label class="form-label fw-semibold" for="mp-provider">{{ tr('provider', 'Provedor') }}</label>
                    <select
                        id="mp-provider"
                        v-model="form.provider"
                        class="form-select"
                        :class="{ 'is-invalid': errors.provider }"
                        :disabled="isEdit"
                    >
                        <option v-for="p in providers" :key="p.code" :value="p.code">{{ p.label }}</option>
                    </select>
                    <div class="invalid-feedback">{{ errors.provider }}</div>
                </div>
                <div class="col-12 col-md-7">
                    <label class="form-label fw-semibold" for="mp-model">
                        {{ tr('price_model_name', 'Nome do modelo') }}
                        <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    <input
                        id="mp-model"
                        v-model.trim="form.model"
                        type="text"
                        maxlength="120"
                        class="form-control"
                        :class="{ 'is-invalid': errors.model }"
                        :disabled="isEdit"
                        placeholder="gpt-4o-mini"
                        autocomplete="off"
                        required
                        aria-describedby="mp-model-hint"
                    />
                    <div id="mp-model-hint" class="form-text">{{ t.price_model_hint }}</div>
                    <div class="invalid-feedback">{{ errors.model }}</div>
                </div>

                <div class="col-12 col-sm-4">
                    <label class="form-label fw-semibold" for="mp-input">
                        {{ tr('col_input', 'Entrada') }} <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    <div class="input-group has-validation">
                        <span class="input-group-text">US$</span>
                        <input
                            id="mp-input"
                            v-model.number="form.input_usd_per_million"
                            type="number"
                            min="0"
                            step="0.0001"
                            class="form-control"
                            :class="{ 'is-invalid': errors.input_usd_per_million }"
                            required
                        />
                        <div class="invalid-feedback">{{ errors.input_usd_per_million }}</div>
                    </div>
                </div>
                <div class="col-12 col-sm-4">
                    <label class="form-label fw-semibold" for="mp-output">
                        {{ tr('col_output', 'Saída') }} <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    <div class="input-group has-validation">
                        <span class="input-group-text">US$</span>
                        <input
                            id="mp-output"
                            v-model.number="form.output_usd_per_million"
                            type="number"
                            min="0"
                            step="0.0001"
                            class="form-control"
                            :class="{ 'is-invalid': errors.output_usd_per_million }"
                            required
                        />
                        <div class="invalid-feedback">{{ errors.output_usd_per_million }}</div>
                    </div>
                </div>
                <div class="col-12 col-sm-4">
                    <label class="form-label fw-semibold" for="mp-reasoning">{{
                        tr('field_reasoning', 'Raciocínio')
                    }}</label>
                    <div class="input-group has-validation">
                        <span class="input-group-text">US$</span>
                        <input
                            id="mp-reasoning"
                            v-model.number="form.reasoning_usd_per_million"
                            type="number"
                            min="0"
                            step="0.0001"
                            class="form-control"
                            :class="{ 'is-invalid': errors.reasoning_usd_per_million }"
                            :placeholder="tr('field_reasoning_same', 'Igual à saída')"
                        />
                        <div class="invalid-feedback">{{ errors.reasoning_usd_per_million }}</div>
                    </div>
                </div>
                <div class="col-12">
                    <div class="form-text mt-0">{{ tr('section_prices', 'Preço (USD por 1 milhão de tokens)') }}</div>
                </div>

                <div class="col-12 col-sm-5">
                    <div class="form-check form-switch">
                        <input
                            id="mp-active"
                            v-model="form.active"
                            class="form-check-input"
                            type="checkbox"
                            role="switch"
                        />
                        <label class="form-check-label" for="mp-active">{{ tr('price_active', 'Ativo') }}</label>
                    </div>
                </div>
                <div class="col-12 col-sm-7">
                    <div class="form-check">
                        <input
                            id="mp-locked"
                            v-model="form.price_locked"
                            class="form-check-input"
                            type="checkbox"
                            aria-describedby="mp-locked-hint"
                            data-price-lock-input
                            @change="touchLock"
                        />
                        <label class="form-check-label" for="mp-locked">{{
                            tr('price_lock', 'Travar este preço')
                        }}</label>
                    </div>
                    <div id="mp-locked-hint" class="form-text">{{ t.price_lock_hint }}</div>
                </div>
            </div>
        </form>

        <template #footer>
            <button type="button" class="btn btn-light" @click="$emit('close')">{{ tr('cancel', 'Cancelar') }}</button>
            <button type="submit" form="model-price-form" class="btn btn-primary" :disabled="!canSave" data-price-save>
                <span v-if="saving" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                {{ tr('save', 'Salvar') }}
            </button>
        </template>
    </CenteredModal>
</template>
