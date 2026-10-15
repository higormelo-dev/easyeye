<script setup>
import { computed, ref } from 'vue';
import ImplantFormModal from './ImplantFormModal.vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import { callApi, errorMessages, useOperationText } from './useOperation.js';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

/**
 * Implantes da cirurgia (RDC 556/2021): lista (vigentes e anulados), registro,
 * anulação (com motivo e destino da unidade), termo do implante (documento
 * congelado), folha de etiquetas e cartão do implante.
 */
const props = defineProps({
    section: { type: Object, required: true },
    t: { type: Object, required: true },
});

const emit = defineEmits(['reload']);

const { tr } = useOperationText(() => props.t);
const { date, dateTime, number } = useLocaleFormat();

const formOpen = ref(false);
const voiding = ref(null);
const returnToStock = ref(false);
const voidReason = ref('');
const voidError = ref('');
const notice = ref('');
const busyId = ref(null);

const active = computed(() => props.section.implants.filter((i) => i.status === 'active'));
const voided = computed(() => props.section.implants.filter((i) => i.status === 'voided'));
const canRegister = computed(() => props.section.state.can_register);

const urlFor = (key, id) => props.section.urls[key].replace('__ID__', id);

function openVoid(implant) {
    voiding.value = implant;
    returnToStock.value = false;
    voidReason.value = '';
    voidError.value = '';
}

async function confirmVoid() {
    if (!voiding.value || voidReason.value.trim().length < 3) return;
    busyId.value = voiding.value.id;
    voidError.value = '';
    const result = await callApi(urlFor('implant_void', voiding.value.id), {
        body: { reason: voidReason.value.trim(), return_to_stock: returnToStock.value },
    });
    busyId.value = null;

    if (result.ok) {
        voiding.value = null;
        emit('reload');
        return;
    }
    voidError.value = errorMessages(result).join(' ');
}

async function issueRecord(implant) {
    busyId.value = implant.id;
    notice.value = '';
    const result = await callApi(urlFor('implant_record', implant.id));
    busyId.value = null;

    if (result.ok) {
        window.open(urlFor('implant_record_pdf', implant.id), '_blank', 'noopener');
        emit('reload');
        return;
    }
    notice.value = errorMessages(result).join(' ');
}

function labelsUrl(implant) {
    return `${urlFor('implant_labels', implant.id)}?count=${labelCount.value}`;
}

const labelCount = ref(6);

const voidActionLabel = (implant) =>
    implant.void_stock_action ? tr(`implant.void_action.${implant.void_stock_action}`) : tr('implant.void_action.none');
</script>

<template>
    <section class="card mb-3" data-testid="op-implants">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="ti ti-eyeglass" aria-hidden="true"></i>
            <h2 class="h6 mb-0">{{ tr('implant.title') }}</h2>
            <button
                v-if="canRegister"
                type="button"
                class="btn btn-sm btn-primary ms-auto"
                data-testid="op-implant-add"
                @click="formOpen = true"
            >
                <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ tr('implant.add') }}
            </button>
        </div>

        <div class="card-body">
            <div v-if="notice" class="alert alert-danger py-2" role="alert">{{ notice }}</div>

            <p v-if="!active.length" class="text-muted mb-0" data-testid="op-implants-empty">
                {{ tr('implant.empty') }}
            </p>

            <div v-else class="table-responsive">
                <table class="table table-sm align-middle mb-2">
                    <thead>
                        <tr>
                            <th scope="col">{{ tr('implant.product') }}</th>
                            <th scope="col">{{ tr('implant.eye') }}</th>
                            <th scope="col">{{ tr('implant.power') }}</th>
                            <th scope="col">{{ tr('implant.lot') }}</th>
                            <th scope="col">{{ tr('implant.serial') }}</th>
                            <th scope="col">{{ tr('implant.expiry') }}</th>
                            <th scope="col">
                                <span class="visually-hidden">{{ tr('actions') }}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="i in active" :key="i.id" data-testid="op-implant-row">
                            <td>
                                <div class="fw-medium">{{ i.product_name }}</div>
                                <div class="small text-muted">
                                    {{ i.manufacturer }}
                                    <span v-if="i.anvisa_registration">· ANVISA {{ i.anvisa_registration }}</span>
                                    <span class="badge text-bg-light ms-1">{{ i.source_label }}</span>
                                </div>
                            </td>
                            <td>{{ i.eye_label }}</td>
                            <td>{{ i.power === null ? '—' : number(i.power, 2) }}</td>
                            <td>{{ i.lot_number ?? '—' }}</td>
                            <td data-testid="op-implant-serial">{{ i.serial_number ?? '—' }}</td>
                            <td>{{ i.expiry_date ? date(i.expiry_date) : '—' }}</td>
                            <td class="text-end text-nowrap">
                                <template v-if="section.state.can_print">
                                    <a
                                        class="btn btn-sm btn-outline-secondary me-1"
                                        :href="labelsUrl(i)"
                                        target="_blank"
                                        rel="noopener"
                                        :title="tr('implant.labels')"
                                    >
                                        <i class="ti ti-tag" aria-hidden="true"></i
                                        ><span class="visually-hidden">{{ tr('implant.labels') }}</span>
                                    </a>
                                    <a
                                        class="btn btn-sm btn-outline-secondary me-1"
                                        :href="`${section.urls.card}?implant=${i.id}`"
                                        target="_blank"
                                        rel="noopener"
                                        :title="tr('implant.card')"
                                    >
                                        <i class="ti ti-id" aria-hidden="true"></i
                                        ><span class="visually-hidden">{{ tr('implant.card') }}</span>
                                    </a>
                                </template>
                                <a
                                    v-if="i.record && section.state.can_print"
                                    class="btn btn-sm btn-outline-primary me-1"
                                    :href="urlFor('implant_record_pdf', i.id)"
                                    target="_blank"
                                    rel="noopener"
                                    data-testid="op-record-pdf"
                                >
                                    <i class="ti ti-file-text me-1" aria-hidden="true"></i
                                    >{{ tr('implant.record_open') }}
                                </a>
                                <button
                                    v-if="section.state.can_register"
                                    type="button"
                                    class="btn btn-sm btn-outline-primary me-1"
                                    :disabled="busyId === i.id"
                                    data-testid="op-record-issue"
                                    @click="issueRecord(i)"
                                >
                                    <i class="ti ti-file-certificate me-1" aria-hidden="true"></i
                                    >{{ i.record ? tr('implant.record_reissue') : tr('implant.record_issue') }}
                                </button>
                                <button
                                    v-if="section.state.can_register"
                                    type="button"
                                    class="btn btn-sm btn-outline-danger"
                                    :disabled="busyId === i.id"
                                    data-testid="op-implant-void"
                                    @click="openVoid(i)"
                                >
                                    <i class="ti ti-ban me-1" aria-hidden="true"></i>{{ tr('implant.void') }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="active.length && section.state.can_print" class="d-flex align-items-center gap-2 small mb-2">
                <label for="op-label-count" class="mb-0">{{ tr('implant.label_count') }}</label>
                <input
                    id="op-label-count"
                    v-model.number="labelCount"
                    type="number"
                    min="1"
                    :max="section.options.label_max"
                    class="form-control form-control-sm"
                    style="width: 5rem"
                />
                <a
                    class="btn btn-sm btn-outline-secondary ms-auto"
                    :href="section.urls.card"
                    target="_blank"
                    rel="noopener"
                    data-testid="op-card-all"
                >
                    <i class="ti ti-id me-1" aria-hidden="true"></i>{{ tr('implant.card_all') }}
                </a>
            </div>

            <details v-if="voided.length" class="mt-2">
                <summary class="small text-muted">{{ tr('implant.voided_title', { n: voided.length }) }}</summary>
                <ul class="list-unstyled small mt-2 mb-0">
                    <li v-for="i in voided" :key="i.id" class="mb-1" data-testid="op-implant-voided">
                        <s>{{ i.product_name }} · {{ i.serial_number ?? i.lot_number }}</s>
                        — {{ dateTime(i.voided_at) }} · {{ i.void_reason }} ({{ voidActionLabel(i) }})
                    </li>
                </ul>
            </details>
        </div>

        <ImplantFormModal
            :open="formOpen"
            :section="section"
            :t="t"
            @close="formOpen = false"
            @saved="
                formOpen = false;
                emit('reload');
            "
        />

        <CenteredModal :open="Boolean(voiding)" size="md" @close="voiding = null">
            <template #header>{{ tr('implant.void_title') }}</template>

            <form class="p-1" @submit.prevent="confirmVoid">
                <label class="form-label fw-medium" for="op-void-reason">{{ tr('implant.void_reason') }}</label>
                <textarea
                    id="op-void-reason"
                    v-model="voidReason"
                    class="form-control"
                    :class="{ 'is-invalid': voidError }"
                    rows="3"
                    maxlength="500"
                    required
                    data-testid="op-void-reason"
                ></textarea>
                <div v-if="voidError" class="invalid-feedback d-block" role="alert">{{ voidError }}</div>

                <div v-if="voiding?.source === 'clinic_stock'" class="form-check mt-3">
                    <input
                        id="op-void-return"
                        v-model="returnToStock"
                        type="checkbox"
                        class="form-check-input"
                        data-testid="op-void-return"
                    />
                    <label class="form-check-label" for="op-void-return">{{ tr('implant.void_return') }}</label>
                    <div class="form-text">{{ tr('implant.void_return_hint') }}</div>
                </div>
            </form>

            <template #footer>
                <button type="button" class="btn btn-outline-secondary" @click="voiding = null">
                    {{ tr('cancel') }}
                </button>
                <button
                    type="button"
                    class="btn btn-danger"
                    :disabled="Boolean(busyId) || voidReason.trim().length < 3"
                    data-testid="op-void-confirm"
                    @click="confirmVoid"
                >
                    {{ tr('implant.void_confirm') }}
                </button>
            </template>
        </CenteredModal>
    </section>
</template>
