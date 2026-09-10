<script setup>
/**
 * Procedimento ESTRUTURADO do prontuário (Fase 3 — estoque ↔ prontuário,
 * gap-fill: back-end existia desde a Fase 3, mas nunca tinha UI). Mesmo
 * desenho arquitetural da Evolução (histórico por PACIENTE, atravessa
 * prontuários, carregado sob demanda via fetch()+csrf() — ver
 * MedicalRecordForm.vue openEvolutionModal()) e do modal de imagens (Teleport
 * + .modal.fade.show.d-block hand-rolled, NÃO OffcanvasPanel — aquele é o
 * padrão das telas novas em /panel/stock/*, este arquivo tem convenção
 * própria).
 *
 * Fluxo: solicitar (catálogo Procedure, mesmo autocomplete de
 * procedure_search usado no card de texto livre) → confirmar execução
 * (consumo de material OPCIONAL, pré-preenchido pela BOM do procedimento via
 * procedure_bom_template — o médico ajusta quantidade/lote ou remove item
 * que não foi usado) → ou cancelar. Servidor é sempre a fonte de verdade:
 * toda trava aqui (lote obrigatório, saldo) é conveniência de UX, repetida
 * de propósito em StockService (LotRequiredException/
 * InsufficientStockException já vêm com `render()` próprio em JSON 422).
 *
 * Fora de escopo aqui (BOM já cobre o caso comum): adicionar item de consumo
 * que NÃO está na composição padrão do procedimento — se a clínica precisar
 * disso, o caminho é cadastrar a BOM (tela Estoque > Procedimentos).
 */
import { computed, reactive, ref, watch } from 'vue';

const props = defineProps({
    show:     { type: Boolean, default: false },
    urls:     { type: Object,  required: true },
    isDoctor: { type: Boolean, default: false },
    isLocked: { type: Boolean, default: false },
    t:        { type: Object,  default: () => ({}) },
});

const emit = defineEmits(['close']);

function tt(key, fallback = '') {
    const v = props.t?.[key];
    return typeof v === 'string' && v !== '' ? v : fallback;
}

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

const EYE_OPTIONS = [
    { value: 'right', label: 'OD' },
    { value: 'left', label: 'OE' },
    { value: 'both', label: 'AO' },
];

// Mesmo vocabulário de ProcedureSolicitationService::TYPES — já usado no
// card de solicitação em texto livre (procTypeSelected), repetido aqui pra
// não depender de um endpoint só pra listar 4 valores fixos.
const TYPE_OPTIONS = [
    { value: 'rotina', label: 'Rotina' },
    { value: 'urgencia', label: 'Urgência' },
    { value: 'controle', label: 'Controle' },
    { value: 'comparativo', label: 'Comparativo' },
];

const STATUS_BADGE = {
    requested: 'bg-warning-subtle text-warning border border-warning',
    done: 'bg-success-subtle text-success border border-success',
    cancelled: 'bg-secondary-subtle text-secondary border border-secondary',
};

// Solicitar/confirmar/cancelar são atos médicos (Gate IssueReport no
// back-end) — as URLs só existem no payload quando isEdit && record (ver
// MedicalRecordsController::buildFormUrls()), então checar a URL já cobre
// "prontuário salvo" sem precisar repetir isEdit como prop.
const canWrite = computed(() => props.isDoctor && ! props.isLocked && !! props.urls.medicalrecordprocedures_store);

// ── Listagem (histórico por paciente, cronológico) ──────────────────────
const loading    = ref(false);
const loaded     = ref(false);
const loadError  = ref('');
const procedures = ref([]);

async function loadProcedures() {
    if (! props.urls.medicalrecordprocedures_index) return;
    loading.value = true;
    loadError.value = '';
    try {
        const res = await fetch(props.urls.medicalrecordprocedures_index, {
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        });
        if (! res.ok) throw new Error(String(res.status));
        procedures.value = (await res.json()).data ?? [];
        loaded.value = true;
    } catch (e) {
        console.error('Failed to load medical record procedures:', e);
        loadError.value = tt('procedures_error', 'Não foi possível carregar os procedimentos.');
    } finally {
        loading.value = false;
    }
}

watch(() => props.show, (open) => {
    if (open && ! loaded.value) loadProcedures();
    if (! open) { showNewForm.value = false; markDoneTarget.value = null; }
});

// ── Nova solicitação ─────────────────────────────────────────────────────
const showNewForm       = ref(false);
const procSearchQuery   = ref('');
const procSearchResults = ref([]);
const procSearchOpen    = ref(false);
const procSearchLoading = ref(false);
const selectedProcedure = ref(null);
const newForm           = reactive({ eye: '', solicitation_type: '', notes: '' });
const requestBusy        = ref(false);
const requestError       = ref('');
let searchDebounce       = null;

function searchProcedures() {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(async () => {
        const q = procSearchQuery.value.trim();
        if (q.length < 2 || ! props.urls.procedure_search) {
            procSearchResults.value = [];
            procSearchOpen.value = false;

            return;
        }
        procSearchLoading.value = true;
        try {
            const res = await fetch(`${props.urls.procedure_search}?q=${encodeURIComponent(q)}`, {
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            });
            if (! res.ok) {
                procSearchResults.value = [];
                procSearchOpen.value = false;

                return;
            }
            procSearchResults.value = await res.json();
            procSearchOpen.value = procSearchResults.value.length > 0;
        } catch (e) {
            console.error('Procedure search error:', e);
            procSearchResults.value = [];
            procSearchOpen.value = false;
        } finally {
            procSearchLoading.value = false;
        }
    }, 250);
}

function pickProcedure(item) {
    selectedProcedure.value = item;
    procSearchQuery.value = item.name;
    procSearchOpen.value = false;
}

function openNewForm() {
    markDoneTarget.value = null;
    showNewForm.value = true;
    selectedProcedure.value = null;
    procSearchQuery.value = '';
    procSearchResults.value = [];
    newForm.eye = '';
    newForm.solicitation_type = '';
    newForm.notes = '';
    requestError.value = '';
}

async function submitRequest() {
    if (! selectedProcedure.value) {
        requestError.value = tt('procedures_select_first', 'Selecione um procedimento do catálogo.');

        return;
    }
    if (requestBusy.value || ! props.urls.medicalrecordprocedures_store) return;

    requestBusy.value = true;
    requestError.value = '';
    try {
        const res = await fetch(props.urls.medicalrecordprocedures_store, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({
                procedure_id: selectedProcedure.value.id,
                eye: newForm.eye || null,
                solicitation_type: newForm.solicitation_type || null,
                notes: newForm.notes || null,
            }),
        });
        const json = await res.json().catch(() => ({}));
        if (! res.ok) {
            requestError.value = json.message ?? tt('procedures_request_error', 'Não foi possível solicitar o procedimento.');

            return;
        }
        procedures.value.unshift(json.data);
        showNewForm.value = false;
    } catch (e) {
        console.error('Procedure request error:', e);
        requestError.value = tt('procedures_request_error', 'Não foi possível solicitar o procedimento.');
    } finally {
        requestBusy.value = false;
    }
}

// ── Confirmar execução (consumo opcional, pré-preenchido pela BOM) ──────
const markDoneTarget   = ref(null); // linha da listagem em execução
const bomLoading       = ref(false);
const consumptionItems = ref([]);
const markDoneNotes    = ref('');
const markDoneBusy     = ref(false);
const markDoneError    = ref('');

async function openMarkDone(proc) {
    showNewForm.value = false;
    markDoneTarget.value = proc;
    markDoneError.value = '';
    markDoneNotes.value = '';
    consumptionItems.value = [];

    if (! props.urls.procedure_bom_template) return;

    bomLoading.value = true;
    try {
        const url = props.urls.procedure_bom_template.replace('__PROCEDURE_ID__', proc.procedure_id);
        const res = await fetch(url, { headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() } });
        if (res.ok) {
            const json = await res.json();
            consumptionItems.value = (json.data ?? []).map((item) => ({
                entity_product_id: item.entity_product_id,
                product_name:      item.product_name,
                product_code:      item.product_code,
                unit_label:        item.unit_label,
                requires_lot:      item.requires_lot,
                qty_on_hand:       item.qty_on_hand,
                quantity:          item.quantity,
                // Único lote disponível: pré-seleciona (menos clique); mais
                // de um, o médico escolhe (FEFO já vem ordenado por
                // validade — ver MedicalRecordProceduresController::bom()).
                stock_lot_id: item.lots?.length === 1 ? item.lots[0].id : '',
                lots:         item.lots ?? [],
            }));
        }
    } catch (e) {
        console.error('BOM fetch error:', e);
    } finally {
        bomLoading.value = false;
    }
}

function closeMarkDone() {
    markDoneTarget.value = null;
}

function removeConsumptionItem(idx) {
    consumptionItems.value.splice(idx, 1);
}

async function confirmMarkDone() {
    if (! markDoneTarget.value || markDoneBusy.value) return;

    for (const item of consumptionItems.value) {
        if (item.requires_lot && ! item.stock_lot_id) {
            markDoneError.value = `${tt('procedures_lot_label', 'Lote')}: ${item.product_name ?? ''}`;

            return;
        }
    }

    const url = props.urls.medicalrecordprocedure_mark_done_template?.replace('__PROCEDURE_ID__', markDoneTarget.value.id);
    if (! url) return;

    markDoneBusy.value = true;
    markDoneError.value = '';
    try {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({
                notes: markDoneNotes.value || null,
                items: consumptionItems.value
                    .filter((i) => Number(i.quantity) > 0)
                    .map((i) => ({
                        entity_product_id: i.entity_product_id,
                        quantity:          Number(i.quantity),
                        stock_lot_id:      i.stock_lot_id || null,
                    })),
            }),
        });
        const json = await res.json().catch(() => ({}));
        if (! res.ok) {
            markDoneError.value = json.message ?? tt('procedures_done_error', 'Não foi possível confirmar a execução.');

            return;
        }
        const idx = procedures.value.findIndex((p) => p.id === json.data.id);
        if (idx !== -1) procedures.value[idx] = json.data;
        closeMarkDone();
    } catch (e) {
        console.error('Mark done error:', e);
        markDoneError.value = tt('procedures_done_error', 'Não foi possível confirmar a execução.');
    } finally {
        markDoneBusy.value = false;
    }
}

// ── Cancelar ─────────────────────────────────────────────────────────────
async function cancelProcedure(proc) {
    if (! window.confirm(tt('procedures_cancel_confirm', 'Cancelar esta solicitação de procedimento?'))) return;

    const url = props.urls.medicalrecordprocedure_cancel_template?.replace('__PROCEDURE_ID__', proc.id);
    if (! url) return;

    try {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({}),
        });
        const json = await res.json().catch(() => ({}));
        if (! res.ok) {
            alert(json.message ?? tt('procedures_cancel_error', 'Não foi possível cancelar o procedimento.'));

            return;
        }
        const idx = procedures.value.findIndex((p) => p.id === proc.id);
        if (idx !== -1) procedures.value[idx] = json.data;
    } catch (e) {
        console.error('Cancel procedure error:', e);
        alert(tt('procedures_cancel_error', 'Não foi possível cancelar o procedimento.'));
    }
}
</script>

<template>
    <Teleport to="body">
        <div v-if="show" class="modal fade show d-block" style="background: rgba(15, 23, 42, .45);"
             role="dialog" aria-modal="true" @click.self="emit('close')">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header py-2">
                        <h5 class="modal-title">
                            <i class="fas fa-syringe me-2 text-primary"></i>{{ tt('procedures', 'Procedimentos') }}
                        </h5>
                        <button v-if="canWrite && ! showNewForm && ! markDoneTarget" type="button"
                                class="btn btn-primary btn-sm ms-auto me-2" @click="openNewForm">
                            <i class="fas fa-plus me-1"></i>{{ tt('procedures_new', 'Solicitar procedimento') }}
                        </button>
                        <button type="button" class="btn-close" @click="emit('close')"></button>
                    </div>

                    <div class="modal-body">
                        <!-- Nova solicitação -->
                        <div v-if="showNewForm" class="border rounded p-2 mb-3 bg-light">
                            <div class="position-relative mb-2">
                                <label class="form-label small fw-semibold mb-1">{{ tt('procedures', 'Procedimentos') }}</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                                    <input v-model="procSearchQuery" type="text" class="form-control form-control-sm"
                                           :placeholder="tt('procedures_search_ph', 'Buscar procedimento no catálogo...')"
                                           @input="searchProcedures">
                                    <span v-if="procSearchLoading" class="input-group-text bg-transparent">
                                        <span class="spinner-border spinner-border-sm" style="width:.8rem;height:.8rem;"></span>
                                    </span>
                                </div>
                                <ul v-if="procSearchOpen && procSearchResults.length > 0"
                                    class="list-group shadow-sm position-absolute w-100"
                                    style="z-index:1080;top:100%;max-height:220px;overflow-y:auto;">
                                    <li v-for="item in procSearchResults" :key="item.id"
                                        class="list-group-item list-group-item-action py-1 px-2" style="cursor:pointer;font-size:.82rem;"
                                        @mousedown.prevent="pickProcedure(item)">
                                        <span class="fw-semibold">{{ item.name }}</span>
                                        <span v-if="item.code" class="text-muted ms-1 small">({{ item.code }})</span>
                                    </li>
                                </ul>
                            </div>

                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <label class="form-label small fw-semibold mb-1">{{ tt('procedures_eye_label', 'Olho') }}</label>
                                    <select v-model="newForm.eye" class="form-select form-select-sm">
                                        <option value="">—</option>
                                        <option v-for="o in EYE_OPTIONS" :key="o.value" :value="o.value">{{ o.label }}</option>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-semibold mb-1">{{ tt('procedures_type_label', 'Tipo') }}</label>
                                    <select v-model="newForm.solicitation_type" class="form-select form-select-sm">
                                        <option value="">—</option>
                                        <option v-for="o in TYPE_OPTIONS" :key="o.value" :value="o.value">{{ o.label }}</option>
                                    </select>
                                </div>
                            </div>

                            <div class="mb-2">
                                <label class="form-label small fw-semibold mb-1">{{ tt('procedures_notes_label', 'Observações') }}</label>
                                <textarea v-model="newForm.notes" class="form-control form-control-sm" rows="2"></textarea>
                            </div>

                            <div v-if="requestError" class="alert alert-danger py-1 px-2 small mb-2">{{ requestError }}</div>

                            <div class="d-flex justify-content-end gap-2">
                                <button type="button" class="btn btn-outline-secondary btn-sm" @click="showNewForm = false">
                                    {{ tt('confirm_no', 'Cancelar') }}
                                </button>
                                <button type="button" class="btn btn-primary btn-sm" :disabled="requestBusy" @click="submitRequest">
                                    <span v-if="requestBusy" class="spinner-border spinner-border-sm me-1"></span>
                                    {{ tt('procedures_request', 'Solicitar') }}
                                </button>
                            </div>
                        </div>

                        <!-- Confirmar execução (consumo opcional) -->
                        <div v-else-if="markDoneTarget" class="border rounded p-2 mb-3 bg-light">
                            <h6 class="mb-2">{{ markDoneTarget.procedure_name }}</h6>

                            <div v-if="bomLoading" class="text-center text-muted py-3">
                                <span class="spinner-border spinner-border-sm me-2"></span>{{ tt('procedures_loading', 'Carregando procedimentos…') }}
                            </div>

                            <template v-else>
                                <div class="small fw-semibold mb-1">{{ tt('procedures_consumption_title', 'Consumo de material (opcional)') }}</div>
                                <p class="small text-muted">{{ tt('procedures_consumption_hint', 'Sugestão baseada na composição cadastrada deste procedimento — ajuste as quantidades ou remova o que não foi usado.') }}</p>

                                <div v-if="consumptionItems.length === 0" class="text-muted small mb-2">
                                    {{ tt('procedures_no_bom', 'Sem sugestão de material cadastrada.') }}
                                </div>

                                <div v-for="(item, idx) in consumptionItems" :key="item.entity_product_id" class="row g-2 align-items-end mb-2">
                                    <div class="col-12 col-sm-5">
                                        <label class="form-label small mb-1">{{ item.product_name }}</label>
                                        <div class="small text-muted">{{ item.product_code }} · {{ tt('procedures_qty_label', 'Quantidade') }} disp.: {{ item.qty_on_hand }} {{ item.unit_label }}</div>
                                    </div>
                                    <div class="col-6 col-sm-2">
                                        <label class="form-label small mb-1">{{ tt('procedures_qty_label', 'Quantidade') }}</label>
                                        <input v-model.number="item.quantity" type="number" min="0" step="any" class="form-control form-control-sm">
                                    </div>
                                    <div v-if="item.requires_lot" class="col-6 col-sm-4">
                                        <label class="form-label small mb-1">{{ tt('procedures_lot_label', 'Lote') }}</label>
                                        <select v-model="item.stock_lot_id" class="form-select form-select-sm" :class="{ 'is-invalid': ! item.stock_lot_id }">
                                            <option value="" disabled>—</option>
                                            <option v-for="lot in item.lots" :key="lot.id" :value="lot.id">
                                                {{ lot.lot_number }}<template v-if="lot.expiry_date"> · vence {{ lot.expiry_date }}</template> ({{ lot.qty_on_hand }})
                                            </option>
                                        </select>
                                        <small v-if="item.lots.length === 0" class="text-danger d-block">{{ tt('procedures_lot_none', 'Sem lote com saldo disponível') }}</small>
                                    </div>
                                    <div class="col-6 col-sm-1 text-end">
                                        <button type="button" class="btn btn-outline-danger btn-sm" :title="tt('procedures_remove_item', 'Remover')" @click="removeConsumptionItem(idx)">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="mb-2">
                                    <label class="form-label small fw-semibold mb-1">{{ tt('procedures_notes_label', 'Observações') }}</label>
                                    <textarea v-model="markDoneNotes" class="form-control form-control-sm" rows="2"></textarea>
                                </div>

                                <div v-if="markDoneError" class="alert alert-danger py-1 px-2 small mb-2">{{ markDoneError }}</div>

                                <div class="d-flex justify-content-end gap-2">
                                    <button type="button" class="btn btn-outline-secondary btn-sm" @click="closeMarkDone">
                                        {{ tt('confirm_no', 'Cancelar') }}
                                    </button>
                                    <button type="button" class="btn btn-success btn-sm" :disabled="markDoneBusy" @click="confirmMarkDone">
                                        <span v-if="markDoneBusy" class="spinner-border spinner-border-sm me-1"></span>
                                        {{ tt('procedures_mark_done', 'Confirmar execução') }}
                                    </button>
                                </div>
                            </template>
                        </div>

                        <!-- Histórico -->
                        <div v-if="loading" class="text-center text-muted py-5">
                            <span class="spinner-border spinner-border-sm me-2"></span>{{ tt('procedures_loading', 'Carregando procedimentos…') }}
                        </div>

                        <div v-else-if="loadError" class="alert alert-danger py-2 small mb-0">
                            <i class="fas fa-exclamation-triangle me-1"></i>{{ loadError }}
                        </div>

                        <div v-else-if="procedures.length === 0" class="text-center text-muted py-4">
                            <i class="fas fa-syringe d-block fs-3 mb-2"></i>{{ tt('procedures_empty', 'Nenhum procedimento registrado para este paciente.') }}
                        </div>

                        <ul v-else class="list-group">
                            <li v-for="proc in procedures" :key="proc.id" class="list-group-item">
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <span class="fw-semibold">{{ proc.procedure_name }}</span>
                                    <span class="badge" :class="STATUS_BADGE[proc.status] ?? 'bg-secondary-subtle text-secondary'">{{ proc.status_label }}</span>
                                    <span v-if="proc.eye" class="badge bg-light text-dark border">{{ EYE_OPTIONS.find(o => o.value === proc.eye)?.label ?? proc.eye }}</span>
                                    <span v-if="proc.solicitation_type" class="badge bg-light text-dark border">{{ TYPE_OPTIONS.find(o => o.value === proc.solicitation_type)?.label ?? proc.solicitation_type }}</span>
                                    <span class="text-muted small ms-auto">{{ proc.doctor_name }} · {{ proc.created_at }}</span>
                                </div>
                                <div v-if="proc.notes" class="small text-muted mt-1">{{ proc.notes }}</div>
                                <div v-if="proc.status === 'done'" class="small text-success mt-1">
                                    <i class="fas fa-check-circle me-1"></i>{{ proc.executed_by_name }} · {{ proc.executed_at }}
                                </div>
                                <div v-if="canWrite && proc.status === 'requested'" class="d-flex gap-2 mt-2">
                                    <button type="button" class="btn btn-outline-success btn-sm" @click="openMarkDone(proc)">
                                        <i class="fas fa-check me-1"></i>{{ tt('procedures_mark_done', 'Confirmar execução') }}
                                    </button>
                                    <button type="button" class="btn btn-outline-danger btn-sm" @click="cancelProcedure(proc)">
                                        <i class="fas fa-ban me-1"></i>{{ tt('procedures_cancel', 'Cancelar') }}
                                    </button>
                                </div>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
