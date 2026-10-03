<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import SearchInput from '@/Components/Panel/SearchInput.vue';
import CatalogSyncPanel from './CatalogSyncPanel.vue';
import ModelPriceDrawer from './ModelPriceDrawer.vue';
import ModelPriceFormModal from './ModelPriceFormModal.vue';
import ModelPriceTable from './ModelPriceTable.vue';
import ProviderDetailDrawer from './ProviderDetailDrawer.vue';
import ProviderTable from './ProviderTable.vue';
import RolesCard from './RolesCard.vue';
import { usePriceFormat } from './usePriceFormat';

/**
 * Manager → Provedores de IA, no padrão das telas do manager (Empresas,
 * Medicamentos): números no topo e abas — Provedores (papéis do assistente
 * + provedores; chave só no .env), Modelos e preços (catálogo paginado e
 * filtrado no servidor) e Sincronização (catálogo LiteLLM + API de cada
 * provedor, em tempo real).
 */
const props = defineProps({
    // {code,label,enabled,role,configured,model,price_ok,has_key,key_hint,key_env,
    //  model_source,base_url,base_url_secure,keys_url,compatible,model_unlisted_at,...}
    providers: { type: Array, default: () => [] },
    roles: { type: Object, default: () => ({}) },
    modelOptions: { type: Object, default: () => ({}) }, // {openai: ['gpt-4o', ...]} — só ativos com preço
    prices: { type: Object, default: () => ({ data: [], links: [], last_page: 1 }) }, // paginator
    filters: { type: Object, default: () => ({}) },
    stats: { type: Object, default: () => ({}) },
    modes: { type: Array, default: () => [] },
    runningSync: { type: Object, default: null },
    syncs: { type: Array, default: () => [] },
    syncDetails: { type: Object, default: null },
    autoSync: { type: Boolean, default: false },
    lgpd: { type: Object, default: () => ({ checked_at: null, mechanisms: [] }) },
    t: { type: Object, default: () => ({}) },
});

const { number } = usePriceFormat();

function tr(key, fallback, replace = {}) {
    let text = props.t[key] ?? fallback;
    for (const [k, v] of Object.entries(replace)) text = text.replace(`:${k}`, v);
    return text;
}

function toast(message, type = 'success') {
    if (!message) return;
    if (type === 'success') return window.showSuccessToast?.(message);
    return window.showErrorToast?.(message);
}

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

// ── Abas ─────────────────────────────────────────────────────────────────
// Sincronização em andamento abre nela; filtros do catálogo na URL (voltar,
// recarregar a página) abrem em Modelos e preços.
const catalogFiltered =
    !!props.filters.search ||
    !!props.filters.provider ||
    (props.filters.status ?? 'active') !== 'active' ||
    !!props.filters.source ||
    !!props.filters.flag ||
    !!props.filters.sort ||
    (props.prices.current_page ?? 1) > 1;

const tab = ref(props.runningSync ? 'sync' : catalogFiltered ? 'models' : 'providers');
const syncRunning = ref(!!props.runningSync);

// ── Números do topo ──────────────────────────────────────────────────────
const statCards = computed(() => {
    const s = props.stats;
    return [
        {
            key: 'providers',
            icon: 'ti-plug-connected',
            label: tr('stat_providers', 'Provedores configurados'),
            value: tr('stat_providers_value', ':count de :total', {
                count: number(s.providers_configured ?? 0),
                total: number(s.providers_total ?? 0),
            }),
        },
        {
            key: 'models',
            icon: 'ti-tags',
            label: tr('stat_models', 'Modelos ativos'),
            value: number(s.models_active ?? 0),
            hint: tr('stat_models_hint', ':count inativos', { count: number(s.models_inactive ?? 0) }),
        },
        {
            key: 'mode',
            icon: 'ti-adjustments',
            label: tr('stat_mode', 'Modo mais completo'),
            value: s.best_mode ?? '—',
        },
        {
            key: 'sync',
            icon: 'ti-refresh',
            label: tr('stat_last_sync', 'Última sincronização'),
            value: s.last_sync_at ?? tr('stat_never', 'Nunca'),
            hint: s.last_sync_status ?? null,
            hintClass: s.last_sync_color ? `text-${s.last_sync_color}` : 'text-muted',
        },
    ];
});

// ── LGPD: pendências dos provedores em papel ─────────────────────────────
const lgpdPending = computed(() => props.providers.filter((p) => p.lgpd?.pending).map((p) => p.label));
const lgpdBlockedInRole = computed(() => props.providers.filter((p) => p.lgpd?.blocked_in_role).map((p) => p.label));

// ── Recargas parciais ────────────────────────────────────────────────────
const RELOAD_PROVIDERS = ['providers', 'roles', 'modes', 'stats', 'prices'];
const RELOAD_CATALOG = ['prices', 'modelOptions', 'providers', 'stats'];

function reload(only) {
    router.reload({ only });
}

// ── Provedores: detalhes e teste de conexão ──────────────────────────────
const providerCode = ref(null);
const providerOpen = ref(false);
const selectedProvider = computed(() => props.providers.find((p) => p.code === providerCode.value) ?? null);

function openProvider(code) {
    providerCode.value = code;
    providerOpen.value = true;
}

const testing = ref(null);
const testResults = ref({});

async function testProvider(code) {
    if (testing.value) return;
    testing.value = code;
    const label = props.providers.find((p) => p.code === code)?.label ?? code;
    try {
        const res = await fetch(route('manager.ai-providers.test'), {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({ provider: code }),
        });
        const json = await res.json().catch(() => ({}));
        const result = {
            ok: res.ok && !!json.ok,
            message: json.message ?? tr('test_failed', 'Falha ao conectar.'),
            latency_ms: json.latency_ms,
        };
        testResults.value = { ...testResults.value, [code]: result };
        toast(`${label}: ${result.message}`, result.ok ? 'success' : 'error');
    } catch {
        const message = tr('test_failed', 'Falha ao conectar.');
        testResults.value = { ...testResults.value, [code]: { ok: false, message } };
        toast(`${label}: ${message}`, 'error');
    } finally {
        testing.value = null;
    }
}

// ── Modelos e preços: filtros e ordenação (servidor) ─────────────────────
const search = ref(props.filters.search ?? '');
const provider = ref(props.filters.provider ?? '');
const status = ref(props.filters.status ?? 'active');
const source = ref(props.filters.source ?? '');
const flag = ref(props.filters.flag ?? '');
const sort = ref(props.filters.sort ?? '');
const direction = ref(props.filters.direction ?? 'asc');

function applyFilters() {
    router.get(
        route('manager.ai-providers.index'),
        {
            search: search.value || undefined,
            provider: provider.value || undefined,
            // Padrão (só ativos) fica fora da URL.
            status: status.value !== 'active' ? status.value : undefined,
            source: source.value || undefined,
            flag: flag.value || undefined,
            sort: sort.value || undefined,
            direction: sort.value ? direction.value : undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true, only: ['prices', 'filters'] },
    );
}

function onSort(payload) {
    sort.value = payload.sort;
    direction.value = payload.direction;
    applyFilters();
}

let searchTimer = null;
watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 350);
});
watch([provider, status, source, flag], applyFilters);
onBeforeUnmount(() => clearTimeout(searchTimer));

// ── Modelos e preços: detalhe, cadastro e ações rápidas ──────────────────
const priceDetail = ref(null);
const priceDetailOpen = ref(false);
const priceFormOpen = ref(false);
const priceEditing = ref(null);

function openPriceDetail(row) {
    priceDetail.value = row;
    priceDetailOpen.value = true;
}

function openPriceCreate() {
    priceEditing.value = null;
    priceFormOpen.value = true;
}

function openPriceEdit(row) {
    priceDetailOpen.value = false;
    priceEditing.value = row;
    priceFormOpen.value = true;
}

function onPriceSaved(message) {
    priceFormOpen.value = false;
    toast(message);
    reload(RELOAD_CATALOG);
}

const patching = ref(null);

// Ativar/desativar e travar/destravar: mesmos preços, só muda o que foi pedido.
async function patchPrice(row, changes) {
    if (patching.value) return;
    patching.value = row.id;
    try {
        const res = await fetch(route('manager.ai-model-prices.update', row.id), {
            method: 'PATCH',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({
                input_usd_per_million: row.input_usd_per_million,
                output_usd_per_million: row.output_usd_per_million,
                reasoning_usd_per_million: row.reasoning_usd_per_million,
                tool_call_usd: row.tool_call_usd,
                active: row.active,
                ...changes,
            }),
        });
        const json = await res.json().catch(() => ({}));
        if (!res.ok) {
            toast(json.message ?? Object.values(json.errors ?? {}).flat()[0] ?? tr('test_failed', 'Erro'), 'error');
            return;
        }
        toast(json.message);
        reload(RELOAD_CATALOG);
    } finally {
        patching.value = null;
    }
}

// ── Sincronização (botão do cabeçalho) ───────────────────────────────────
const syncPanel = ref(null);

function startSync() {
    tab.value = 'sync';
    syncPanel.value?.startSync();
}

const breadcrumbs = [
    { label: props.t.breadcrumb_home ?? 'Dashboard', url: route('manager.dashboard'), active: false },
    { label: props.t.breadcrumb ?? 'Provedores de IA', url: '#', active: true },
];
</script>

<template>
    <AppLayout :title="t.title" :breadcrumbs="breadcrumbs">
        <PageHeader :title="t.title ?? 'Provedores de IA'" :subtitle="t.page_subtitle">
            <template #actions>
                <button
                    type="button"
                    class="btn btn-outline-primary fs-13"
                    :title="tr('sync_now', 'Sincronizar agora')"
                    :aria-label="tr('sync_now', 'Sincronizar agora')"
                    :disabled="syncRunning"
                    data-header-sync
                    @click="startSync"
                >
                    <span v-if="syncRunning" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                    <i v-else class="ti ti-refresh" aria-hidden="true"></i
                    ><span class="d-none d-md-inline ms-1">{{ tr('btn_sync', 'Sincronizar') }}</span>
                </button>
                <button
                    type="button"
                    class="btn btn-primary fs-13"
                    :title="tr('price_new', 'Novo modelo')"
                    :aria-label="tr('price_new', 'Novo modelo')"
                    data-header-new-model
                    @click="openPriceCreate"
                >
                    <i class="ti ti-plus" aria-hidden="true"></i
                    ><span class="d-none d-sm-inline ms-1">{{ tr('price_new', 'Novo modelo') }}</span>
                </button>
            </template>
        </PageHeader>

        <!-- Números -->
        <div class="row g-2 mb-3">
            <div v-for="card in statCards" :key="card.key" class="col-6 col-lg-3">
                <div class="card mb-0 h-100" :data-stat="card.key">
                    <div class="card-body py-2 px-3">
                        <div class="text-muted small">
                            <i :class="`ti ${card.icon} me-1`" aria-hidden="true"></i>{{ card.label }}
                        </div>
                        <div class="fs-5 fw-semibold text-truncate" :title="card.value">{{ card.value }}</div>
                        <div v-if="card.hint" class="small" :class="card.hintClass ?? 'text-muted'">
                            {{ card.hint }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <ul class="nav nav-tabs mb-3" role="tablist">
            <li class="nav-item" role="presentation">
                <button
                    type="button"
                    class="nav-link"
                    :class="{ active: tab === 'providers' }"
                    role="tab"
                    :aria-selected="tab === 'providers'"
                    data-tab="providers"
                    @click="tab = 'providers'"
                >
                    <i class="ti ti-plug-connected me-1" aria-hidden="true"></i>{{ tr('tab_providers', 'Provedores') }}
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button
                    type="button"
                    class="nav-link"
                    :class="{ active: tab === 'models' }"
                    role="tab"
                    :aria-selected="tab === 'models'"
                    data-tab="models"
                    @click="tab = 'models'"
                >
                    <i class="ti ti-tags me-1" aria-hidden="true"></i>{{ tr('tab_models', 'Modelos e preços') }}
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button
                    type="button"
                    class="nav-link"
                    :class="{ active: tab === 'sync' }"
                    role="tab"
                    :aria-selected="tab === 'sync'"
                    data-tab="sync"
                    @click="tab = 'sync'"
                >
                    <i class="ti ti-refresh me-1" aria-hidden="true"></i>{{ tr('tab_sync', 'Sincronização') }}
                    <span v-if="syncRunning" class="spinner-border spinner-border-sm ms-1" aria-hidden="true"></span>
                </button>
            </li>
        </ul>

        <!-- ════════ Provedores ════════ -->
        <div v-show="tab === 'providers'" role="tabpanel">
            <div v-if="lgpdBlockedInRole.length" class="alert alert-danger py-2 small" role="alert" data-lgpd-blocked>
                <i class="ti ti-shield-x me-1" aria-hidden="true"></i
                >{{ tr('lgpd_blocked_alert', ':providers', { providers: lgpdBlockedInRole.join(', ') }) }}
            </div>
            <div v-if="lgpdPending.length" class="alert alert-warning py-2 small" role="alert" data-lgpd-pending>
                <i class="ti ti-shield-exclamation me-1" aria-hidden="true"></i
                >{{ tr('lgpd_pending_alert', ':providers', { providers: lgpdPending.join(', ') }) }}
            </div>

            <RolesCard :roles="roles" :providers="providers" :modes="modes" :t="t" @saved="reload(RELOAD_PROVIDERS)" />

            <div class="card mb-0">
                <div class="card-header bg-transparent fw-semibold">{{ tr('providers_title', 'Provedores') }}</div>
                <ProviderTable
                    :providers="providers"
                    :testing="testing"
                    :t="t"
                    @view="openProvider"
                    @test="testProvider"
                />
            </div>
        </div>

        <!-- ════════ Modelos e preços ════════ -->
        <div v-show="tab === 'models'" role="tabpanel">
            <p class="text-muted small mb-2">{{ t.prices_subtitle }}</p>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                <SearchInput
                    v-model="search"
                    :placeholder="tr('search_placeholder', 'Buscar modelo…')"
                    max-width="320px"
                    wrapper-class="mb-0 flex-grow-1"
                />
                <select
                    v-model="provider"
                    class="form-select w-auto"
                    :aria-label="tr('filter_provider_label', 'Filtrar por provedor')"
                    data-filter-provider
                >
                    <option value="">{{ tr('filter_provider_all', 'Todos os provedores') }}</option>
                    <option v-for="p in providers" :key="p.code" :value="p.code">{{ p.label }}</option>
                </select>
                <select
                    v-model="status"
                    class="form-select w-auto"
                    :aria-label="tr('filter_status_label', 'Filtrar por status')"
                    data-filter-status
                >
                    <option value="active">{{ tr('filter_status_active', 'Ativos') }}</option>
                    <option value="inactive">{{ tr('filter_status_inactive', 'Inativos (disponíveis)') }}</option>
                    <option value="all">{{ tr('filter_status_all', 'Todos') }}</option>
                </select>
                <select
                    v-model="source"
                    class="form-select w-auto"
                    :aria-label="tr('filter_source_label', 'Filtrar por origem')"
                    data-filter-source
                >
                    <option value="">{{ tr('filter_source_all', 'Todas as origens') }}</option>
                    <option value="seed">{{ tr('source_seed', 'Padrão') }}</option>
                    <option value="manual">{{ tr('source_manual', 'Manual') }}</option>
                    <option value="sync">{{ tr('source_sync', 'Sincronizado') }}</option>
                </select>
                <select
                    v-model="flag"
                    class="form-select w-auto"
                    :aria-label="tr('filter_flag_label', 'Filtrar por situação')"
                    data-filter-flag
                >
                    <option value="">{{ tr('filter_flag_all', 'Todas as situações') }}</option>
                    <option value="in_use">{{ tr('flag_in_use', 'Em uso') }}</option>
                    <option value="locked">{{ tr('flag_locked', 'Preço travado') }}</option>
                    <option value="unlisted">{{ tr('flag_unlisted', 'Não oferecidos pelo provedor') }}</option>
                </select>
            </div>

            <ModelPriceTable
                :prices="prices"
                :filters="filters"
                :t="t"
                @sort="onSort"
                @view="openPriceDetail"
                @edit="openPriceEdit"
                @toggle-active="(row) => patchPrice(row, { active: !row.active })"
                @toggle-lock="(row) => patchPrice(row, { price_locked: !row.price_locked })"
            />
        </div>

        <!-- ════════ Sincronização ════════ -->
        <div v-show="tab === 'sync'" role="tabpanel">
            <CatalogSyncPanel
                ref="syncPanel"
                :running-sync="runningSync"
                :syncs="syncs"
                :sync-details="syncDetails"
                :auto-sync="autoSync"
                :t="t"
                @update:running="(value) => (syncRunning = value)"
            />
        </div>

        <ProviderDetailDrawer
            :open="providerOpen"
            :provider="selectedProvider"
            :model-options="modelOptions[providerCode] ?? []"
            :testing="!!providerCode && testing === providerCode"
            :test-result="testResults[providerCode] ?? null"
            :lgpd="lgpd"
            :t="t"
            @close="providerOpen = false"
            @test="testProvider"
            @saved="reload(RELOAD_PROVIDERS)"
        />

        <ModelPriceDrawer
            :open="priceDetailOpen"
            :price="priceDetail"
            :t="t"
            @close="priceDetailOpen = false"
            @edit="openPriceEdit"
        />

        <ModelPriceFormModal
            :open="priceFormOpen"
            :price="priceEditing"
            :providers="providers"
            :default-provider="provider"
            :t="t"
            @close="priceFormOpen = false"
            @saved="onPriceSaved"
        />
    </AppLayout>
</template>
