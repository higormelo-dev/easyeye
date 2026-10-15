<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import ReasonModal from '@/Components/Surgery/ReasonModal.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

/**
 * Uma guia TISS do caso (solicitação de internação ou anexo de OPME): estado da entrega,
 * campos que a secretária completa, itens, pré-validação, XML/PDF, envio e histórico.
 *
 * O servidor decide o que vale agora (`guide.editable`, `guide.locked`, `guide.delivery`):
 * guia oferecida à operadora (em voo, EM DÚVIDA ou confirmada) fica travada — nada de
 * editar, regerar ou cancelar; só reenviar (mesmo XML, mesma chave) quando a entrega ficou
 * em dúvida.
 */
const props = defineProps({
    kind: { type: String, required: true }, // 'inpatient' | 'opme'
    guide: { type: Object, required: true },
    canAct: { type: Boolean, default: false },
    caseAwaiting: { type: Boolean, default: false },
    urls: { type: Object, required: true },
    tableCodes: { type: Array, default: () => [] },
    t: { type: Object, required: true },
});

const emit = defineEmits(['reload']);

const { date, dateTime } = useLocaleFormat();

const isOpme = computed(() => props.kind === 'opme');
const delivery = computed(() => props.guide.delivery?.state ?? 'none');
const url = (name) => String(props.urls[name] ?? '').replace('__GUIDE__', props.guide.id);

const tone = computed(
    () =>
        ({
            draft: 'secondary',
            xml_generated: 'primary',
            sent: 'info',
            authorized: 'success',
            denied: 'danger',
            cancelled: 'secondary',
            error: 'danger',
        })[props.guide.status.value] ?? 'secondary',
);

// ── Campos e itens editáveis (cópia local, ressincronizada quando a guia recarrega) ────────────
const fields = reactive({});
const items = ref([]);

function sync() {
    Object.assign(fields, {
        suggested_date: props.guide.fields.suggested_date ?? '',
        referenced_guide_number: props.guide.fields.referenced_guide_number ?? '',
        professional_phone: props.guide.fields.professional_phone ?? '',
        justification: props.guide.fields.justification ?? '',
        specification: props.guide.fields.specification ?? '',
        observation: props.guide.fields.observation ?? '',
    });
    items.value = props.guide.items.map((i) => ({ ...i }));
}
watch(() => props.guide, sync, { immediate: true, deep: true });

const busy = ref(false);
const errors = ref({});

function run(method, urlName, payload = {}, options = {}) {
    busy.value = true;
    errors.value = {};
    router[method](url(urlName), payload, {
        preserveScroll: true,
        onError: (e) => {
            errors.value = e ?? {};
        },
        onSuccess: () => emit('reload'),
        onFinish: () => {
            busy.value = false;
        },
        ...options,
    });
}

function save() {
    const payload = {
        suggested_date: isOpme.value ? undefined : fields.suggested_date || null,
        referenced_guide_number: isOpme.value ? fields.referenced_guide_number || null : undefined,
        professional_phone: isOpme.value ? fields.professional_phone || null : undefined,
        justification: isOpme.value ? fields.justification || null : undefined,
        specification: isOpme.value ? fields.specification || null : undefined,
        observation: fields.observation || null,
        items: items.value.map((i) => ({
            id: i.id,
            tuss_code: i.tuss_code || null,
            table_code: i.table_code || null,
            ...(isOpme.value
                ? {
                      manufacturer_option: i.manufacturer_option || null,
                      anvisa_registration: i.anvisa_registration || null,
                      manufacturer_ref: i.manufacturer_ref || null,
                      operating_authorization: i.operating_authorization || null,
                  }
                : {}),
        })),
    };
    run('put', 'guide', payload);
}

// ── Ações ───────────────────────────────────────────────────────────────────────────────────────
const confirmingSend = ref(false);
const cancelling = ref(false);
const fileInput = ref(null);

const hasErrors = computed(() => (props.guide.validation?.errors?.length ?? 0) > 0);
const xmlValid = computed(() => props.guide.xml && ['validated', 'signed'].includes(props.guide.xml.status));
const canSend = computed(
    () =>
        props.canAct &&
        (props.caseAwaiting || delivery.value === 'doubt') &&
        !['cancelled', 'denied'].includes(props.guide.status.value),
);
const sendLabel = computed(() =>
    delivery.value === 'doubt' || delivery.value === 'failed' ? props.t.resend : props.t.send,
);

function send() {
    confirmingSend.value = false;
    run('post', 'send');
}

function uploadResponse(event) {
    const file = event.target.files?.[0];
    if (!file) return;
    run('post', 'response', { xml_file: file }, { forceFormData: true });
    event.target.value = '';
}

const errorList = computed(() => Object.values(errors.value).flat().filter(Boolean));
</script>

<template>
    <section
        class="card mb-3"
        :data-testid="`tiss-card-${kind}`"
        :data-status="guide.status.value"
        :data-delivery="delivery"
    >
        <div class="card-header d-flex flex-wrap align-items-center gap-2">
            <h3 class="h6 mb-0 me-auto">
                {{ isOpme ? t.opme_title : t.inpatient_title }}
                <small class="text-muted ms-2">{{ t.number }} {{ guide.number }}</small>
            </h3>
            <span class="badge" :class="`text-bg-${tone}`">{{ guide.status.label }}</span>
            <span class="badge text-bg-light border" data-testid="delivery-label">{{ guide.delivery.label }}</span>
        </div>

        <div class="card-body">
            <!-- Entrega: palavras que a secretária entende -->
            <div v-if="delivery === 'doubt'" class="alert alert-warning" role="alert" data-testid="doubt-banner">
                <strong>{{ t.doubt_title }}.</strong> {{ t.doubt_text }}
            </div>
            <div v-else-if="delivery === 'failed'" class="alert alert-danger" role="alert">{{ t.failed_text }}</div>
            <div v-else-if="delivery === 'in_flight'" class="alert alert-info" role="status">
                {{ t.in_flight_text }}
            </div>
            <div v-else-if="delivery === 'delivered'" class="alert alert-success py-2" role="status">
                {{ t.delivered_text }}
                <span v-if="guide.operator_number"> · {{ t.operator_number }}: {{ guide.operator_number }}</span>
                <span v-if="guide.authorization_number">
                    · {{ t.authorization_number }}: {{ guide.authorization_number }}</span
                >
            </div>

            <div v-if="guide.locked && delivery !== 'doubt'" class="text-muted small mb-2">{{ t.locked_hint }}</div>

            <div
                v-if="guide.stale"
                class="alert alert-secondary d-flex align-items-center gap-2"
                data-testid="stale-banner"
            >
                <span class="me-auto">{{ t.stale }}</span>
                <button
                    v-if="canAct"
                    type="button"
                    class="btn btn-sm btn-outline-secondary"
                    :disabled="busy"
                    @click="run('post', 'refresh')"
                >
                    {{ t.refresh }}
                </button>
            </div>

            <div v-if="errorList.length" class="alert alert-danger py-2" role="alert" data-testid="tiss-errors">
                <div v-for="(message, i) in errorList" :key="i">{{ message }}</div>
            </div>

            <!-- Resumo + campos completáveis -->
            <div class="row g-2 mb-3">
                <div v-if="!isOpme" class="col-md-4">
                    <span class="small text-muted d-block">{{ t.hospital }}</span>
                    {{ guide.summary.hospital || '—' }}
                    <span v-if="guide.summary.hospital_code" class="text-muted small"
                        >({{ guide.summary.hospital_code }})</span
                    >
                </div>
                <div v-if="!isOpme" class="col-md-5">
                    <span class="small text-muted d-block">{{ t.diagnoses }}</span>
                    {{ guide.summary.diagnoses.join(', ') || '—' }}
                </div>
                <div v-if="!isOpme" class="col-md-3">
                    <span class="small text-muted d-block">{{ t.days }}</span>
                    {{ guide.summary.days }}
                </div>

                <div v-if="!isOpme" class="col-md-4">
                    <label class="form-label small mb-1" :for="`sd-${guide.id}`">{{ t.suggested_date }}</label>
                    <input
                        :id="`sd-${guide.id}`"
                        v-model="fields.suggested_date"
                        type="date"
                        class="form-control form-control-sm"
                        :disabled="!guide.editable || !canAct"
                        data-testid="field-suggested-date"
                    />
                </div>
                <template v-else>
                    <div class="col-md-4">
                        <label class="form-label small mb-1" :for="`rg-${guide.id}`">{{
                            t.referenced_guide_number
                        }}</label>
                        <input
                            :id="`rg-${guide.id}`"
                            v-model="fields.referenced_guide_number"
                            class="form-control form-control-sm"
                            maxlength="20"
                            :disabled="!guide.editable || !canAct"
                        />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small mb-1" :for="`ph-${guide.id}`">{{ t.professional_phone }}</label>
                        <input
                            :id="`ph-${guide.id}`"
                            v-model="fields.professional_phone"
                            class="form-control form-control-sm"
                            maxlength="20"
                            :disabled="!guide.editable || !canAct"
                        />
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-1" :for="`ju-${guide.id}`">{{ t.justification }}</label>
                        <textarea
                            :id="`ju-${guide.id}`"
                            v-model="fields.justification"
                            rows="2"
                            class="form-control form-control-sm"
                            maxlength="2000"
                            :disabled="!guide.editable || !canAct"
                        ></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-1" :for="`sp-${guide.id}`">{{ t.specification }}</label>
                        <textarea
                            :id="`sp-${guide.id}`"
                            v-model="fields.specification"
                            rows="2"
                            class="form-control form-control-sm"
                            maxlength="1000"
                            :disabled="!guide.editable || !canAct"
                        ></textarea>
                    </div>
                </template>
                <div class="col-12">
                    <label class="form-label small mb-1" :for="`ob-${guide.id}`">{{ t.observation }}</label>
                    <input
                        :id="`ob-${guide.id}`"
                        v-model="fields.observation"
                        class="form-control form-control-sm"
                        maxlength="1000"
                        :disabled="!guide.editable || !canAct"
                    />
                </div>
            </div>

            <!-- Itens -->
            <h4 class="h6">{{ t.items_title }}</h4>
            <div class="table-responsive mb-2">
                <table class="table table-sm align-middle" :data-testid="`items-${kind}`">
                    <thead>
                        <tr>
                            <th scope="col">{{ t.item_description }}</th>
                            <th scope="col" class="text-end">{{ t.item_quantity }}</th>
                            <th scope="col">{{ t.item_table }}</th>
                            <th scope="col">{{ t.item_code }}</th>
                            <template v-if="isOpme">
                                <th scope="col">{{ t.item_manufacturer_option }}</th>
                                <th scope="col">{{ t.item_anvisa }}</th>
                                <th scope="col">{{ t.item_ref }}</th>
                                <th scope="col">{{ t.item_operating_authorization }}</th>
                            </template>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in items" :key="item.id">
                            <td>{{ item.description }}</td>
                            <td class="text-end">{{ item.quantity }}</td>
                            <td style="width: 5.5rem">
                                <select
                                    v-model="item.table_code"
                                    class="form-select form-select-sm"
                                    :disabled="!guide.editable || !canAct"
                                    :aria-label="t.item_table"
                                >
                                    <option v-for="code in tableCodes" :key="code" :value="code">{{ code }}</option>
                                </select>
                            </td>
                            <td style="width: 8rem">
                                <input
                                    v-model="item.tuss_code"
                                    class="form-control form-control-sm"
                                    maxlength="10"
                                    :disabled="!guide.editable || !canAct"
                                    :aria-label="t.item_code"
                                    data-testid="item-code"
                                />
                            </td>
                            <template v-if="isOpme">
                                <td style="width: 5.5rem">
                                    <select
                                        v-model="item.manufacturer_option"
                                        class="form-select form-select-sm"
                                        :disabled="!guide.editable || !canAct"
                                        :aria-label="t.item_manufacturer_option"
                                    >
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                    </select>
                                </td>
                                <td>
                                    <input
                                        v-model="item.anvisa_registration"
                                        class="form-control form-control-sm"
                                        maxlength="15"
                                        :disabled="!guide.editable || !canAct"
                                        :aria-label="t.item_anvisa"
                                        data-testid="item-anvisa"
                                    />
                                </td>
                                <td>
                                    <input
                                        v-model="item.manufacturer_ref"
                                        class="form-control form-control-sm"
                                        maxlength="60"
                                        :disabled="!guide.editable || !canAct"
                                        :aria-label="t.item_ref"
                                    />
                                </td>
                                <td>
                                    <input
                                        v-model="item.operating_authorization"
                                        class="form-control form-control-sm"
                                        maxlength="30"
                                        :disabled="!guide.editable || !canAct"
                                        :aria-label="t.item_operating_authorization"
                                    />
                                </td>
                            </template>
                        </tr>
                    </tbody>
                </table>
            </div>
            <button
                v-if="guide.editable && canAct"
                type="button"
                class="btn btn-sm btn-outline-primary mb-3"
                :disabled="busy"
                data-testid="save-fields"
                @click="save"
            >
                {{ t.save_fields }}
            </button>

            <!-- Pré-validação -->
            <div v-if="guide.validation" class="mb-3" data-testid="validation">
                <h4 class="h6">{{ t.validation_title }}</h4>
                <p
                    v-if="guide.validation.errors.length === 0 && guide.validation.warnings.length === 0"
                    class="text-success small mb-0"
                >
                    {{ t.validation_ok }}
                </p>
                <template v-else>
                    <div v-if="guide.validation.errors.length" class="mb-2">
                        <div class="small fw-semibold text-danger">{{ t.validation_errors }}</div>
                        <ul class="small mb-0">
                            <li v-for="issue in guide.validation.errors" :key="issue.code + issue.field">
                                {{ issue.message }}
                                <span class="text-muted">— {{ issue.suggestion }}</span>
                            </li>
                        </ul>
                    </div>
                    <div v-if="guide.validation.warnings.length">
                        <div class="small fw-semibold text-warning-emphasis">{{ t.validation_warnings }}</div>
                        <ul class="small mb-0">
                            <li v-for="issue in guide.validation.warnings" :key="issue.code + issue.field">
                                {{ issue.message }}
                            </li>
                        </ul>
                    </div>
                </template>
            </div>

            <!-- XML -->
            <div class="small mb-3" data-testid="xml-state">
                <template v-if="guide.xml">
                    <span :class="xmlValid ? 'text-success' : 'text-danger'">{{
                        xmlValid ? t.xml_ready : t.xml_invalid
                    }}</span>
                    <span v-if="guide.xml.generated_at" class="text-muted">
                        · {{ dateTime(guide.xml.generated_at) }}</span
                    >
                    <ul v-if="!xmlValid && guide.xml.errors.length" class="mb-0">
                        <li v-for="(e, i) in guide.xml.errors" :key="i">{{ e }}</li>
                    </ul>
                </template>
                <span v-else class="text-muted">{{ t.xml_none }}</span>
            </div>

            <!-- Ações -->
            <div class="d-flex flex-wrap gap-2 mb-3">
                <button
                    v-if="canAct && guide.editable && guide.status.value !== 'cancelled'"
                    type="button"
                    class="btn btn-sm btn-outline-primary"
                    :disabled="busy || hasErrors"
                    data-testid="make-xml"
                    @click="run('post', 'xml_make')"
                >
                    {{ t.make_xml }}
                </button>
                <a
                    v-if="guide.xml"
                    class="btn btn-sm btn-outline-secondary"
                    :href="url('xml')"
                    data-testid="download-xml"
                    >{{ t.download_xml }}</a
                >
                <a
                    class="btn btn-sm btn-outline-secondary"
                    :href="url('pdf')"
                    target="_blank"
                    rel="noopener"
                    data-testid="download-pdf"
                    >{{ t.download_pdf }}</a
                >
                <button
                    v-if="canSend"
                    type="button"
                    class="btn btn-sm btn-primary"
                    :disabled="busy || (hasErrors && delivery !== 'doubt')"
                    data-testid="send"
                    @click="confirmingSend = true"
                >
                    {{ sendLabel }}
                </button>
                <button
                    v-if="canAct && guide.editable && guide.status.value !== 'draft'"
                    type="button"
                    class="btn btn-sm btn-outline-secondary"
                    :disabled="busy"
                    @click="run('post', 'reopen')"
                >
                    {{ t.reopen }}
                </button>
                <button
                    v-if="canAct && !guide.locked && guide.status.value !== 'cancelled'"
                    type="button"
                    class="btn btn-sm btn-outline-danger"
                    :disabled="busy"
                    @click="cancelling = true"
                >
                    {{ t.cancel }}
                </button>
            </div>

            <!-- Resposta da operadora fora do envio -->
            <div
                v-if="
                    (canAct && ['sent', 'authorized'].includes(guide.status.value)) || (canAct && delivery === 'doubt')
                "
                class="mb-3"
            >
                <div class="small fw-semibold">{{ t.response_title }}</div>
                <div class="small text-muted mb-1">{{ t.response_hint }}</div>
                <input
                    ref="fileInput"
                    type="file"
                    accept=".xml,text/xml,application/xml"
                    class="form-control form-control-sm"
                    :disabled="busy"
                    :aria-label="t.response_upload"
                    data-testid="response-file"
                    @change="uploadResponse"
                />
            </div>

            <!-- Histórico -->
            <h4 class="h6">{{ t.transmissions_title }}</h4>
            <p v-if="!guide.transmissions.length" class="small text-muted">{{ t.no_transmissions }}</p>
            <ul v-else class="small list-unstyled mb-2" data-testid="transmissions">
                <li v-for="tx in guide.transmissions" :key="tx.id" class="mb-1">
                    <span class="badge text-bg-light border me-1">{{ tx.label }}</span>
                    {{ t.attempt }} {{ tx.attempt }} · {{ dateTime(tx.requested_at) }}
                    <span v-if="tx.protocol"> · {{ t.protocol }} {{ tx.protocol }}</span>
                    <span v-if="tx.error" class="text-danger"> · {{ tx.error }}</span>
                </li>
            </ul>

            <template v-if="guide.events.length">
                <h4 class="h6">{{ t.events_title }}</h4>
                <ul class="small list-unstyled mb-0">
                    <li v-for="e in guide.events" :key="e.id">
                        {{ date(e.occurred_at) }} · {{ e.label }}<span v-if="e.number"> · {{ e.number }}</span
                        ><span v-if="e.note" class="text-muted"> — {{ e.note }}</span>
                    </li>
                </ul>
            </template>
        </div>

        <CenteredModal :open="confirmingSend" size="md" @close="confirmingSend = false">
            <template #header>{{ sendLabel }}</template>
            <p class="mb-0">{{ t.send_confirm }}</p>
            <template #footer>
                <button type="button" class="btn btn-outline-secondary" @click="confirmingSend = false">
                    {{ t.back }}
                </button>
                <button type="button" class="btn btn-primary" data-testid="confirm-send" @click="send">
                    {{ t.confirm }}
                </button>
            </template>
        </CenteredModal>

        <ReasonModal
            :open="cancelling"
            :title="t.cancel"
            :label="t.cancel_reason"
            :confirm-text="t.confirm"
            :cancel-text="t.back"
            :busy="busy"
            @close="cancelling = false"
            @confirm="
                (reason) => {
                    cancelling = false;
                    run('post', 'cancel', { reason });
                }
            "
        />
    </section>
</template>
