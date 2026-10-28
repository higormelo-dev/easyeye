<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import { callApi, compact, errorMessages, useOperationText } from './useOperation.js';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

/**
 * Registrar implante. Duas origens:
 *  - estoque da clínica: busca o produto, escolhe o lote (a lista já vem em
 *    FEFO — vence primeiro, com saldo, não vencido — e o primeiro é
 *    pré-selecionado, mas a escolha é livre), informa série e potência;
 *  - de fora (consignado do hospital/fornecedor): dados digitados, sem baixa
 *    de saldo.
 * O servidor confere tudo de novo (lote vencido, saldo, série, ANVISA...).
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    section: { type: Object, required: true },
    t: { type: Object, required: true },
});

const emit = defineEmits(['close', 'saved']);

const { tr } = useOperationText(() => props.t);
const { date, quantity, number } = useLocaleFormat();

const blank = () => ({
    source: 'clinic_stock',
    eye: '',
    entity_product_id: '',
    stock_lot_id: '',
    serial_number: '',
    power: '',
    quantity: 1,
    anvisa_registration: '',
    model_reference: '',
    implanted_at: '',
    kind: 'iol',
    product_name: '',
    manufacturer: '',
    lot_number: '',
    expiry_date: '',
    plan_deviation_reason: '',
});

const form = reactive(blank());
const errors = ref([]);
const busy = ref(false);

const search = ref('');
const results = ref([]);
const searching = ref(false);
const product = ref(null);
let timer = null;
let ticket = 0;

const eyes = computed(() => props.section.options?.eyes ?? []);
const isExternal = computed(() => form.source === 'external');
const needsSerial = computed(() => (isExternal.value ? form.kind === 'iol' : Boolean(product.value?.requires_serial)));
const isIol = computed(() => (isExternal.value ? form.kind === 'iol' : product.value?.kind === 'iol'));
const plan = computed(() => props.section.iol_plans?.find((p) => p.eye === form.eye) ?? null);
const planPower = computed(() => plan.value?.power ?? '');
// Potência (ou produto) diferente da LIO planejada do olho: o servidor exige a justificativa do cirurgião.
const deviates = computed(
    () =>
        isIol.value &&
        plan.value !== null &&
        (Math.abs(Number(form.power) - Number(plan.value.power)) > 0.001 ||
            (!isExternal.value &&
                plan.value.entity_product_id &&
                product.value &&
                plan.value.entity_product_id !== product.value.id)),
);

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        Object.assign(form, blank());
        if (eyes.value.length === 1) form.eye = eyes.value[0].value;
        errors.value = [];
        search.value = '';
        results.value = [];
        product.value = null;
    },
);

watch(
    () => form.eye,
    () => {
        if (!form.power && planPower.value !== '') form.power = planPower.value;
    },
);

onBeforeUnmount(() => clearTimeout(timer));

function onSearchInput() {
    clearTimeout(timer);
    if (search.value.trim().length < 2) {
        results.value = [];
        return;
    }
    timer = setTimeout(runSearch, 300);
}

async function runSearch() {
    const mine = ++ticket;
    searching.value = true;
    const result = await callApi(`${props.section.urls.products}?q=${encodeURIComponent(search.value.trim())}`, {
        method: 'GET',
    });
    if (mine !== ticket) return;
    searching.value = false;
    results.value = result.ok && Array.isArray(result.data) ? result.data : [];
}

function pick(p) {
    product.value = p;
    form.entity_product_id = p.id;
    form.stock_lot_id = p.lots.find((l) => l.suggested)?.id ?? p.lots[0]?.id ?? '';
    form.anvisa_registration = p.suggested_anvisa ?? '';
    form.model_reference = p.sku ?? '';
    results.value = [];
    search.value = '';
    if (!form.power && planPower.value !== '') form.power = planPower.value;
}

function clearProduct() {
    product.value = null;
    form.entity_product_id = '';
    form.stock_lot_id = '';
}

async function submit() {
    if (busy.value) return;
    busy.value = true;
    errors.value = [];

    const body = compact({
        ...form,
        implanted_at: form.implanted_at ? new Date(form.implanted_at).toISOString() : '',
        quantity: needsSerial.value ? 1 : form.quantity,
    });

    if (isExternal.value) {
        delete body.entity_product_id;
        delete body.stock_lot_id;
    } else {
        ['product_name', 'manufacturer', 'lot_number', 'expiry_date', 'kind'].forEach((k) => delete body[k]);
    }

    const result = await callApi(props.section.urls.implant_store, { body });
    busy.value = false;

    if (result.ok) {
        emit('saved');
        return;
    }
    errors.value = errorMessages(result);
}
</script>

<template>
    <CenteredModal :open="open" size="lg" @close="emit('close')">
        <template #header>
            <i class="ti ti-eyeglass me-2" aria-hidden="true"></i>{{ tr('implant.form_title') }}
        </template>

        <div data-testid="op-implant-form">
            <div class="btn-group btn-group-sm mb-3" role="group" :aria-label="tr('implant.source')">
                <button
                    v-for="s in section.options.sources"
                    :key="s.value"
                    type="button"
                    class="btn"
                    :class="form.source === s.value ? 'btn-primary' : 'btn-outline-primary'"
                    :aria-pressed="form.source === s.value"
                    :data-testid="`op-source-${s.value}`"
                    @click="form.source = s.value"
                >
                    {{ s.label }}
                </button>
            </div>

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="op-imp-eye">{{ tr('implant.eye') }}</label>
                    <select id="op-imp-eye" v-model="form.eye" class="form-select" data-testid="op-imp-eye">
                        <option value="" disabled>{{ tr('implant.choose') }}</option>
                        <option v-for="e in eyes" :key="e.value" :value="e.value">{{ e.label }}</option>
                    </select>
                </div>

                <!-- Estoque da clínica -->
                <template v-if="!isExternal">
                    <div class="col-12">
                        <label class="form-label" for="op-imp-search">{{ tr('implant.product') }}</label>
                        <div v-if="product" class="d-flex align-items-center gap-2" data-testid="op-imp-product">
                            <strong>{{ product.name }}</strong>
                            <span v-if="product.manufacturer" class="text-muted small">{{ product.manufacturer }}</span>
                            <button type="button" class="btn btn-sm btn-link" @click="clearProduct">
                                {{ tr('implant.change_product') }}
                            </button>
                        </div>
                        <template v-else>
                            <input
                                id="op-imp-search"
                                v-model="search"
                                type="search"
                                class="form-control"
                                :placeholder="tr('implant.search_placeholder')"
                                autocomplete="off"
                                data-testid="op-imp-search"
                                @input="onSearchInput"
                            />
                            <div v-if="searching" class="form-text">{{ tr('implant.searching') }}</div>
                            <ul v-if="results.length" class="list-group mt-1" data-testid="op-imp-results">
                                <li v-for="p in results" :key="p.id" class="list-group-item list-group-item-action p-0">
                                    <button
                                        type="button"
                                        class="btn btn-link text-start w-100 text-decoration-none px-3 py-2"
                                        @click="pick(p)"
                                    >
                                        <strong>{{ p.name }}</strong>
                                        <span v-if="p.manufacturer" class="text-muted"> · {{ p.manufacturer }}</span>
                                        <span class="badge text-bg-light ms-2">{{ quantity(p.qty_on_hand) }}</span>
                                    </button>
                                </li>
                            </ul>
                            <div v-else-if="search.trim().length >= 2 && !searching" class="form-text">
                                {{ tr('implant.no_results') }}
                            </div>
                        </template>
                    </div>

                    <div v-if="product" class="col-12">
                        <label class="form-label">{{ tr('implant.lot') }}</label>
                        <div
                            v-if="!product.lots.length"
                            class="alert alert-warning py-2 mb-0"
                            data-testid="op-imp-no-lots"
                        >
                            {{ tr('implant.no_lots') }}
                        </div>
                        <div v-else class="list-group" role="radiogroup" :aria-label="tr('implant.lot')">
                            <label
                                v-for="lot in product.lots"
                                :key="lot.id"
                                class="list-group-item d-flex align-items-center gap-2"
                            >
                                <input
                                    v-model="form.stock_lot_id"
                                    type="radio"
                                    class="form-check-input mt-0"
                                    :value="lot.id"
                                    :data-testid="`op-lot-${lot.id}`"
                                />
                                <span class="fw-medium">{{ lot.lot_number }}</span>
                                <span class="text-muted small"
                                    >{{ tr('implant.expires') }}:
                                    {{ lot.expiry_date ? date(lot.expiry_date) : tr('implant.no_expiry') }}</span
                                >
                                <span class="text-muted small ms-auto"
                                    >{{ tr('implant.balance') }}: {{ quantity(lot.qty_on_hand) }}</span
                                >
                                <span v-if="lot.suggested" class="badge text-bg-success">{{ tr('implant.fefo') }}</span>
                            </label>
                        </div>
                    </div>
                </template>

                <!-- De fora do estoque (consignado) -->
                <template v-else>
                    <div class="col-md-4">
                        <label class="form-label" for="op-imp-kind">{{ tr('implant.kind') }}</label>
                        <select id="op-imp-kind" v-model="form.kind" class="form-select">
                            <option v-for="k in section.options.kinds" :key="k.value" :value="k.value">
                                {{ k.label }}
                            </option>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label" for="op-imp-name">{{ tr('implant.product_name') }}</label>
                        <input
                            id="op-imp-name"
                            v-model="form.product_name"
                            type="text"
                            class="form-control"
                            maxlength="255"
                            data-testid="op-imp-name"
                        />
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="op-imp-mfr">{{ tr('implant.manufacturer') }}</label>
                        <input
                            id="op-imp-mfr"
                            v-model="form.manufacturer"
                            type="text"
                            class="form-control"
                            maxlength="120"
                        />
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="op-imp-lotnum">{{ tr('implant.lot_number') }}</label>
                        <input
                            id="op-imp-lotnum"
                            v-model="form.lot_number"
                            type="text"
                            class="form-control"
                            maxlength="60"
                        />
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="op-imp-expiry">{{ tr('implant.expiry') }}</label>
                        <input id="op-imp-expiry" v-model="form.expiry_date" type="date" class="form-control" />
                    </div>
                </template>

                <div class="col-md-6">
                    <label class="form-label" for="op-imp-ref">{{ tr('implant.model_reference') }}</label>
                    <input
                        id="op-imp-ref"
                        v-model="form.model_reference"
                        type="text"
                        class="form-control"
                        maxlength="80"
                    />
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="op-imp-anvisa">{{ tr('implant.anvisa') }}</label>
                    <input
                        id="op-imp-anvisa"
                        v-model="form.anvisa_registration"
                        type="text"
                        class="form-control"
                        maxlength="30"
                        data-testid="op-imp-anvisa"
                    />
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="op-imp-serial">
                        {{ tr('implant.serial')
                        }}<span v-if="needsSerial" class="text-danger" aria-hidden="true"> *</span>
                    </label>
                    <input
                        id="op-imp-serial"
                        v-model="form.serial_number"
                        type="text"
                        class="form-control"
                        maxlength="60"
                        :required="needsSerial"
                        data-testid="op-imp-serial"
                    />
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="op-imp-power">
                        {{ tr('implant.power') }}<span v-if="isIol" class="text-danger" aria-hidden="true"> *</span>
                    </label>
                    <input
                        id="op-imp-power"
                        v-model="form.power"
                        type="number"
                        step="0.5"
                        min="-10"
                        max="60"
                        class="form-control"
                        data-testid="op-imp-power"
                    />
                </div>
                <div v-if="deviates" class="col-12" data-testid="op-imp-deviation">
                    <label class="form-label" for="op-imp-deviation">
                        {{ tr('implant.plan_deviation', { power: number(plan.power, 2) }) }}
                    </label>
                    <textarea
                        id="op-imp-deviation"
                        v-model="form.plan_deviation_reason"
                        class="form-control"
                        rows="2"
                        maxlength="2000"
                        data-testid="op-imp-deviation-reason"
                    ></textarea>
                </div>
                <div v-if="!needsSerial" class="col-md-4">
                    <label class="form-label" for="op-imp-qty">{{ tr('implant.quantity') }}</label>
                    <input
                        id="op-imp-qty"
                        v-model.number="form.quantity"
                        type="number"
                        min="1"
                        max="99"
                        class="form-control"
                    />
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="op-imp-date">{{ tr('implant.implanted_at') }}</label>
                    <input id="op-imp-date" v-model="form.implanted_at" type="datetime-local" class="form-control" />
                    <div class="form-text">{{ tr('implant.implanted_at_hint') }}</div>
                </div>
            </div>

            <div
                v-if="errors.length"
                class="alert alert-danger py-2 mt-3 mb-0"
                role="alert"
                data-testid="op-implant-errors"
            >
                <div v-for="(message, i) in errors" :key="i">{{ message }}</div>
            </div>
        </div>

        <template #footer>
            <button type="button" class="btn btn-outline-secondary" @click="emit('close')">{{ tr('cancel') }}</button>
            <button
                type="button"
                class="btn btn-primary"
                :disabled="busy"
                data-testid="op-implant-save"
                @click="submit"
            >
                <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>{{ tr('implant.save') }}
            </button>
        </template>
    </CenteredModal>
</template>
