<script setup>
import { ref, computed } from 'vue';
import AppLayout  from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';

const props = defineProps({
    providers:    { type: Array,  default: () => [] }, // {code,label,enabled,order,configured,model,price_ok}
    roles:        { type: Object, default: () => ({}) }, // {primary,reviewer,adjudicator}
    modelOptions: { type: Object, default: () => ({}) }, // {openai: ['gpt-4o', ...], ...}
    modelPrices:  { type: Array,  default: () => [] },   // catálogo ai_model_prices
    modes:        { type: Array,  default: () => [] }, // {value,label,available,needs}
    enabledCount: { type: Number, default: 0 },
    t:            { type: Object, default: () => ({}) },
});

const saving  = ref(false);
const testing = ref(null); // code em teste
const testResults = ref({}); // code -> {ok, message, latency_ms}

// Estado editável dos papéis.
const form = ref({
    primary:     props.roles.primary ?? null,
    reviewer:    props.roles.reviewer ?? null,
    adjudicator: props.roles.adjudicator ?? null,
});

// Modelo escolhido por provedor ('' = usar o padrão do servidor/.env).
// Só entra no form o que o SELECT consegue exibir: se o modelo efetivo (env)
// não está no catálogo de preços, inicia vazio — senão o save inteiro seria
// bloqueado por um provedor que o admin nem tocou (visto no ambiente de teste
// com claude-sonnet-4-5 sem preço cadastrado).
function initialModelFor(p) {
    const opts = props.modelOptions[p.code] ?? [];
    return opts.includes(p.model) ? p.model : '';
}
const modelForm = ref(Object.fromEntries(
    props.providers.map(p => [p.code, initialModelFor(p)])));

// Envia apenas provedores COM select renderizado (configurado + com opções).
function modelsPayload() {
    const out = {};
    for (const p of props.providers) {
        if (p.configured && (options.value[p.code] ?? []).length) {
            out[p.code] = modelForm.value[p.code] || '';
        }
    }
    return out;
}

const providersByCode = computed(() =>
    Object.fromEntries(props.providers.map(p => [p.code, p])));

// Um provedor só pode ser escolhido se tem credencial no servidor.
const selectable = computed(() => props.providers.filter(p => p.configured));

// Papéis definidos (únicos, na ordem principal→revisor→árbitro).
const assignedCodes = computed(() =>
    [...new Set([form.value.primary, form.value.reviewer, form.value.adjudicator].filter(Boolean))]);

// Validação viva (espelha o backend) — o admin vê o problema ANTES de salvar.
const problems = computed(() => {
    const list = [];
    const f = form.value;
    if (!f.primary) list.push(t_('error_empty', 'Defina o provedor principal.'));
    if (f.adjudicator && !f.reviewer) {
        list.push(t_('error_adjudicator_without_reviewer', 'Árbitro exige um revisor.'));
    }
    if (f.reviewer && f.reviewer === f.primary) list.push(dupMsg('reviewer'));
    if (f.adjudicator && [f.primary, f.reviewer].includes(f.adjudicator)) list.push(dupMsg('adjudicator'));
    for (const code of assignedCodes.value) {
        const p = providersByCode.value[code];
        if (p && !p.price_ok) {
            list.push(`${p.label}: ${t_('price_missing', 'modelo sem preço cadastrado')}`);
        }
    }
    return list;
});

function dupMsg() {
    return t_('error_duplicate_role', 'Cada papel precisa de um provedor diferente.');
}

const canSave = computed(() => !!form.value.primary
    && !(form.value.adjudicator && !form.value.reviewer)
    && !(form.value.reviewer && form.value.reviewer === form.value.primary)
    && !(form.value.adjudicator && [form.value.primary, form.value.reviewer].includes(form.value.adjudicator)));

// Prévia dos modos: escala com o nº de papéis preenchidos.
const previewModes = computed(() => {
    const n = assignedCodes.value.length;
    return props.modes.map(m => ({ ...m, available: n >= m.needs }));
});

// Limpar revisor arrasta o árbitro junto (consistência do consenso).
function onReviewerChange() {
    if (!form.value.reviewer) form.value.adjudicator = null;
}

function t_(key, fallback) {
    return props.t[key] ?? fallback;
}

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function save() {
    saving.value = true;
    try {
        const res = await fetch(route('manager.ai-providers.update'), {
            method:  'PATCH',
            headers: {
                Accept:         'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify({
                primary:     form.value.primary,
                reviewer:    form.value.reviewer || null,
                adjudicator: form.value.adjudicator || null,
                models:      modelsPayload(),
            }),
        });
        const json = await res.json();

        if (!res.ok) {
            showToast(json.message ?? 'Erro', 'error');
            return;
        }
        if (json.roles) {
            form.value = {
                primary:     json.roles.primary ?? null,
                reviewer:    json.roles.reviewer ?? null,
                adjudicator: json.roles.adjudicator ?? null,
            };
        }
        if (Array.isArray(json.providers)) {
            modelForm.value = Object.fromEntries(json.providers.map(p => [p.code, p.model ?? '']));
        }
        showToast(json.message, 'success');
    } finally {
        saving.value = false;
    }
}

async function testProvider(code) {
    testing.value = code;
    try {
        const res = await fetch(route('manager.ai-providers.test'), {
            method:  'POST',
            headers: {
                Accept:         'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify({ provider: code }),
        });
        const json = await res.json();
        testResults.value = { ...testResults.value, [code]: json };
    } catch {
        testResults.value = { ...testResults.value, [code]: { ok: false, message: t_('test_failed', 'Falha ao conectar.') } };
    } finally {
        testing.value = null;
    }
}

// ── Catálogo de modelos e preços ─────────────────────────────────────────
const prices       = ref([...props.modelPrices]);
const options      = ref({ ...props.modelOptions });
const priceModal   = ref(false);
const priceSaving  = ref(false);
const priceEditing = ref(null); // id em edição (null = criar)
const priceForm    = ref(emptyPriceForm());

function emptyPriceForm() {
    return {
        provider: 'openai', model: '',
        input_usd_per_million: null, output_usd_per_million: null,
        reasoning_usd_per_million: null, active: true,
    };
}

function openPriceCreate() {
    priceEditing.value = null;
    priceForm.value = emptyPriceForm();
    priceModal.value = true;
}

function openPriceEdit(row) {
    priceEditing.value = row.id;
    priceForm.value = {
        provider: row.provider, model: row.model,
        input_usd_per_million: row.input_usd_per_million,
        output_usd_per_million: row.output_usd_per_million,
        reasoning_usd_per_million: row.reasoning_usd_per_million,
        active: row.active,
    };
    priceModal.value = true;
}

async function savePrice() {
    priceSaving.value = true;
    try {
        const editing = priceEditing.value;
        const url = editing
            ? route('manager.ai-model-prices.update', editing)
            : route('manager.ai-model-prices.store');
        const res = await fetch(url, {
            method:  editing ? 'PATCH' : 'POST',
            headers: {
                Accept:         'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify(priceForm.value),
        });
        const json = await res.json();

        if (!res.ok) {
            const detail = json.message ?? Object.values(json.errors ?? {}).flat().join(' ');
            showToast(detail || 'Erro', 'error');
            return;
        }
        prices.value = json.prices ?? prices.value;
        rebuildOptions();
        priceModal.value = false;
        showToast(json.message, 'success');
    } finally {
        priceSaving.value = false;
    }
}

async function togglePriceActive(row) {
    const res = await fetch(route('manager.ai-model-prices.update', row.id), {
        method:  'PATCH',
        headers: {
            Accept:         'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf(),
        },
        body: JSON.stringify({
            input_usd_per_million:     row.input_usd_per_million,
            output_usd_per_million:    row.output_usd_per_million,
            reasoning_usd_per_million: row.reasoning_usd_per_million,
            active:                    !row.active,
        }),
    });
    const json = await res.json();
    if (!res.ok) return showToast(json.message ?? 'Erro', 'error');
    prices.value = json.prices ?? prices.value;
    rebuildOptions();
    showToast(json.message, 'success');
}

// Selects de modelo refletem o catálogo na hora (só ativos).
function rebuildOptions() {
    const map = {};
    for (const r of prices.value) {
        if (!r.active) continue;
        (map[r.provider] ??= []).push(r.model);
    }
    for (const k of Object.keys(map)) map[k].sort();
    options.value = map;
}

function showToast(msg, type = 'success') {
    // Helpers globais definidos pelo AppLayout — mesmo visual do flash do sistema.
    if (type === 'success') return window.showSuccessToast?.(msg);
    return window.showErrorToast?.(msg);
}

const roleCards = computed(() => [
    {
        key: 'primary',
        icon: 'ti-bolt',
        title: t_('role_primary', 'Principal (gera a resposta)'),
        hint:  t_('role_primary_hint', 'Obrigatório. Todo atendimento de IA começa por ele.'),
        required: true,
        onChange: null,
    },
    {
        key: 'reviewer',
        icon: 'ti-eye-check',
        title: t_('role_reviewer_title', 'Revisor (confere a resposta)'),
        hint:  t_('role_reviewer_hint', 'Opcional. Com revisor, o modo Validado fica disponível.'),
        required: false,
        onChange: onReviewerChange,
    },
    {
        key: 'adjudicator',
        icon: 'ti-scale',
        title: t_('role_adjudicator_title', 'Árbitro (desempata/consolida)'),
        hint:  t_('role_adjudicator_hint', 'Opcional. Exige revisor. Habilita o modo Consenso.'),
        required: false,
        onChange: null,
    },
]);

const breadcrumbs = [
    { label: 'Dashboard',                              url: route('manager.dashboard'), active: false },
    { label: props.t.breadcrumb ?? 'Provedores de IA', url: '#',                        active: true },
];
</script>

<template>
    <AppLayout :title="t.title" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader :title="t.roles_title ?? 'Papéis do assistente'">
                <template #actions>
                    <button type="button" class="btn btn-primary btn-sm"
                            :disabled="saving || !canSave" @click="save">
                        <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
                        <i v-else class="ti ti-check me-1"></i>{{ t.save ?? 'Salvar' }}
                    </button>
                </template>
            </PageHeader>

            <p class="text-muted small mb-3">{{ t.roles_subtitle }}</p>

            <!-- Problemas de configuração (validação viva) -->
            <div v-if="problems.length" class="alert alert-warning py-2 small">
                <div v-for="(p, i) in problems" :key="i">
                    <i class="ti ti-alert-triangle me-1"></i>{{ p }}
                </div>
            </div>

            <div class="row g-3">
                <!-- Cards de papéis -->
                <div class="col-12 col-lg-8">
                    <div class="row g-3">
                        <div v-for="card in roleCards" :key="card.key" class="col-12 col-md-4">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-body">
                                    <h6 class="fw-semibold mb-1">
                                        <i class="ti me-1 text-primary" :class="card.icon"></i>{{ card.title }}
                                        <span v-if="card.required" class="text-danger">*</span>
                                    </h6>
                                    <p class="text-muted small mb-2">{{ card.hint }}</p>
                                    <select v-model="form[card.key]"
                                            class="form-select form-select-sm"
                                            :data-role="card.key"
                                            @change="card.onChange && card.onChange()">
                                        <option v-if="!card.required" :value="null">{{ t.role_none ?? '— Nenhum —' }}</option>
                                        <option v-for="p in selectable" :key="p.code" :value="p.code">
                                            {{ p.label }} ({{ p.model ?? '—' }})
                                        </option>
                                    </select>
                                    <div v-if="form[card.key] && providersByCode[form[card.key]] && !providersByCode[form[card.key]].price_ok"
                                         class="small text-danger mt-2" :title="t.price_missing_hint">
                                        <i class="ti ti-coin-off me-1"></i>{{ t.price_missing ?? 'Modelo sem preço cadastrado' }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Saúde dos provedores -->
                    <div class="card border-0 shadow-sm mt-3">
                        <div class="card-header fw-semibold py-2">
                            <i class="ti ti-heart-rate-monitor me-1 text-muted"></i>{{ t.provider ?? 'Provedor' }}es
                        </div>
                        <div class="card-body p-0">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>{{ t.provider ?? 'Provedor' }}</th>
                                        <th>{{ t.model ?? 'Modelo' }}</th>
                                        <th>Status</th>
                                        <th class="text-end"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="p in providers" :key="p.code">
                                        <td class="fw-semibold">{{ p.label }}</td>
                                        <td style="min-width: 220px;">
                                            <select v-if="p.configured && (options[p.code] ?? []).length"
                                                    v-model="modelForm[p.code]"
                                                    class="form-select form-select-sm"
                                                    :data-model-for="p.code"
                                                    :title="t.model_hint">
                                                <option value="">{{ t.model_env_fallback ?? 'Padrão do servidor (.env)' }}</option>
                                                <option v-for="m in options[p.code]" :key="m" :value="m">{{ m }}</option>
                                            </select>
                                            <code v-else class="small">{{ p.model ?? '—' }}</code>
                                        </td>
                                        <td>
                                            <span v-if="p.configured"
                                                  class="badge bg-success-subtle text-success border border-success me-1">
                                                <i class="ti ti-shield-check me-1"></i>{{ t.configured ?? 'Configurado' }}
                                            </span>
                                            <span v-else
                                                  class="badge bg-danger-subtle text-danger border border-danger me-1"
                                                  :title="t.no_credential_hint">
                                                <i class="ti ti-alert-triangle me-1"></i>{{ t.not_configured ?? 'Sem credencial' }}
                                            </span>
                                            <span v-if="p.configured && !p.price_ok"
                                                  class="badge bg-warning-subtle text-warning border border-warning"
                                                  :title="t.price_missing_hint">
                                                <i class="ti ti-coin-off me-1"></i>{{ t.price_missing ?? 'Sem preço' }}
                                            </span>
                                            <div v-if="testResults[p.code]" class="small mt-1"
                                                 :class="testResults[p.code].ok ? 'text-success' : 'text-danger'">
                                                <i class="ti me-1" :class="testResults[p.code].ok ? 'ti-circle-check' : 'ti-circle-x'"></i>
                                                {{ testResults[p.code].message }}
                                                <span v-if="testResults[p.code].latency_ms">({{ testResults[p.code].latency_ms }} ms)</span>
                                            </div>
                                        </td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-sm btn-outline-secondary"
                                                    :disabled="!p.configured || testing === p.code"
                                                    @click="testProvider(p.code)">
                                                <span v-if="testing === p.code" class="spinner-border spinner-border-sm me-1"></span>
                                                <i v-else class="ti ti-plug-connected me-1"></i>
                                                {{ t.test_connection ?? 'Testar conexão' }}
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Catálogo de modelos e preços -->
                    <div class="card border-0 shadow-sm mt-3">
                        <div class="card-header d-flex justify-content-between align-items-center py-2">
                            <span class="fw-semibold">
                                <i class="ti ti-tags me-1 text-muted"></i>{{ t.prices_title ?? 'Modelos e preços (catálogo)' }}
                            </span>
                            <button type="button" class="btn btn-primary btn-sm" @click="openPriceCreate">
                                <i class="ti ti-plus me-1"></i>{{ t.price_new ?? 'Novo modelo' }}
                            </button>
                        </div>
                        <div class="card-body pb-0 pt-2">
                            <p class="text-muted small mb-2">{{ t.prices_subtitle }}</p>
                        </div>
                        <div class="card-body p-0">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>{{ t.provider ?? 'Provedor' }}</th>
                                        <th>{{ t.model ?? 'Modelo' }}</th>
                                        <th class="text-end">{{ t.price_input ?? 'Entrada' }}</th>
                                        <th class="text-end">{{ t.price_output ?? 'Saída' }}</th>
                                        <th class="text-center">Status</th>
                                        <th class="text-end"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in prices" :key="row.id" :class="{ 'opacity-50': !row.active }">
                                        <td>{{ row.provider_label }}</td>
                                        <td><code class="small">{{ row.model }}</code></td>
                                        <td class="text-end small">US$ {{ row.input_usd_per_million.toFixed(2) }}</td>
                                        <td class="text-end small">US$ {{ row.output_usd_per_million.toFixed(2) }}</td>
                                        <td class="text-center">
                                            <span class="badge"
                                                  :class="row.active ? 'bg-success-subtle text-success border border-success' : 'bg-secondary-subtle text-secondary border border-secondary'">
                                                {{ row.active ? 'Ativo' : 'Inativo' }}
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1"
                                                    :title="t.edit ?? 'Editar'" @click="openPriceEdit(row)">
                                                <i class="ti ti-edit"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm py-0 px-1 ms-1"
                                                    :class="row.active ? 'btn-outline-danger' : 'btn-outline-success'"
                                                    :title="row.active ? 'Desativar' : 'Ativar'"
                                                    @click="togglePriceActive(row)">
                                                <i class="ti" :class="row.active ? 'ti-lock' : 'ti-lock-open'"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <tr v-if="!prices.length">
                                        <td colspan="6" class="text-center text-muted py-3">—</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Prévia dos modos + propagação -->
                <div class="col-12 col-lg-4">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header fw-semibold py-2">
                            <i class="ti ti-adjustments me-1 text-muted"></i>{{ t.available_modes ?? 'Modos disponíveis' }}
                        </div>
                        <ul class="list-group list-group-flush">
                            <li v-for="m in previewModes" :key="m.value"
                                class="list-group-item d-flex justify-content-between align-items-center">
                                <span>
                                    <i class="ti me-1"
                                       :class="m.available ? 'ti-circle-check text-success' : 'ti-circle-x text-muted'"></i>
                                    {{ m.label }}
                                </span>
                                <small class="text-muted">{{ (t.mode_needs ?? 'requer :n provedor(es)').replace(':n', m.needs) }}</small>
                            </li>
                        </ul>
                        <div class="card-footer small text-muted">
                            <i class="ti ti-bolt me-1"></i>{{ t.propagation_note ?? 'As alterações entram em vigor imediatamente para todos os clientes.' }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal: novo/editar modelo do catálogo -->
        <div v-if="priceModal" class="modal d-block" style="background: rgba(0,0,0,.4);" @click.self="priceModal = false">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header py-2">
                        <h5 class="modal-title">
                            {{ priceEditing ? (t.edit ?? 'Editar') : (t.price_new ?? 'Novo modelo') }}
                        </h5>
                        <button type="button" class="btn-close" @click="priceModal = false"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">{{ t.provider ?? 'Provedor' }}</label>
                            <select v-model="priceForm.provider" class="form-select form-select-sm" :disabled="!!priceEditing">
                                <option v-for="p in providers" :key="p.code" :value="p.code">{{ p.label }}</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ t.price_model_name ?? 'Nome do modelo' }}</label>
                            <input v-model="priceForm.model" type="text" maxlength="120"
                                   class="form-control form-control-sm" :disabled="!!priceEditing"
                                   placeholder="gpt-4o-mini">
                            <div class="form-text">{{ t.price_model_hint }}</div>
                        </div>
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label">{{ t.price_input ?? 'Entrada (USD/1M)' }}</label>
                                <input v-model.number="priceForm.input_usd_per_million" type="number"
                                       min="0" step="0.01" class="form-control form-control-sm" placeholder="0.00">
                            </div>
                            <div class="col-6">
                                <label class="form-label">{{ t.price_output ?? 'Saída (USD/1M)' }}</label>
                                <input v-model.number="priceForm.output_usd_per_million" type="number"
                                       min="0" step="0.01" class="form-control form-control-sm" placeholder="0.00">
                            </div>
                            <div class="col-6">
                                <label class="form-label">{{ t.price_reasoning ?? 'Raciocínio (opcional)' }}</label>
                                <input v-model.number="priceForm.reasoning_usd_per_million" type="number"
                                       min="0" step="0.01" class="form-control form-control-sm" placeholder="—">
                            </div>
                            <div class="col-6 d-flex align-items-end">
                                <div class="form-check form-switch mb-1">
                                    <input id="priceActive" v-model="priceForm.active" class="form-check-input" type="checkbox" role="switch">
                                    <label class="form-check-label small" for="priceActive">{{ t.price_active ?? 'Ativo' }}</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer py-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm" @click="priceModal = false">
                            {{ t.cancel ?? 'Cancelar' }}
                        </button>
                        <button type="button" class="btn btn-primary btn-sm"
                                :disabled="priceSaving || !priceForm.model || priceForm.input_usd_per_million === null || priceForm.output_usd_per_million === null"
                                @click="savePrice">
                            <span v-if="priceSaving" class="spinner-border spinner-border-sm me-1"></span>
                            {{ t.save ?? 'Salvar' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
