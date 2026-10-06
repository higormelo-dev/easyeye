<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import KpiCard from '@/Components/Panel/KpiCard.vue';
import SearchInput from '@/Components/Panel/SearchInput.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import WhatsAppStatusBar from './WhatsAppStatusBar.vue';
import WhatsAppClinicTable from './WhatsAppClinicTable.vue';
import WhatsAppClinicCards from './WhatsAppClinicCards.vue';
import WhatsAppClinicDrawer from './WhatsAppClinicDrawer.vue';
import WhatsAppClinicFormModal from './WhatsAppClinicFormModal.vue';
import WhatsAppGlobalPanel from './WhatsAppGlobalPanel.vue';
import WhatsAppTemplates from './WhatsAppTemplates.vue';

/**
 * Manager → WhatsApp oficial (Gupshup), no padrão de Manager → Medicamentos:
 * faixa de situação única, KPIs (os de filtro são botões), abas Clínicas
 * (busca/filtros/paginação no servidor, tabela ou cards, gaveta de detalhes e
 * modal de configuração) · Número do EasyEye (app global) · Modelos.
 * Exclusivo do admin do SaaS. Nenhum segredo chega aqui: credenciais do
 * parceiro ficam no .env e o segredo do webhook só no servidor.
 */
const props = defineProps({
    // Paginador do Laravel (20 por página) com as clínicas da página.
    clinics: { type: Object, default: () => ({ data: [], links: [], last_page: 1 }) },
    filters: { type: Object, default: () => ({}) },
    kpis: { type: Object, default: () => ({}) },
    // App do número do EasyEye (padrão pra clínica sem número próprio).
    global: { type: Object, default: null },
    // 'gupshup' = envia de verdade; 'mock' = simulação (também com driver vazio).
    driver: { type: String, default: 'mock' },
    simulated: { type: Boolean, default: true },
    partner: { type: Object, default: () => ({ configured: false, auth_mode: null }) },
    templates: { type: Array, default: () => [] },
    templateLanguages: { type: Array, default: () => ['pt_BR'] },
    routes: { type: Object, required: true },
    t: { type: Object, required: true },
});

const { number } = useLocaleFormat();
const ui = computed(() => props.t.ui ?? {});

// ── Abas (lembradas na URL: ?tab=global|templates) ───────────────────────
const TABS = ['clinics', 'global', 'templates'];
const tab = ref(TABS.includes(props.filters.tab) ? props.filters.tab : 'clinics');

function setTab(value) {
    tab.value = value;
    try {
        const url = new URL(window.location.href);
        if (value === 'clinics') url.searchParams.delete('tab');
        else url.searchParams.set('tab', value);
        window.history.replaceState(window.history.state, '', `${url.pathname}${url.search}${url.hash}`);
    } catch {
        // sem history (preview/teste): a aba vale só nesta visita
    }
}

const tabIcons = { clinics: 'ti-building-hospital', global: 'ti-building-broadcast-tower', templates: 'ti-message-2' };

// Setas ←/→ entre as abas (padrão WAI-ARIA de tablist).
function onTabKey(event, current) {
    const step = { ArrowRight: 1, ArrowLeft: -1 }[event.key];
    if (!step) return;
    event.preventDefault();
    const next = TABS[(TABS.indexOf(current) + step + TABS.length) % TABS.length];
    setTab(next);
    document.getElementById(`wa-tab-${next}`)?.focus();
}

// ── Tabela / cards (preferência por navegador) ───────────────────────────
const VIEW_KEY = 'mgr_whatsapp_view';
const view = ref(readView());

// Sem preferência salva, o celular abre em cards (a tabela esconde colunas).
function readView() {
    let saved = null;
    try {
        saved = localStorage.getItem(VIEW_KEY);
    } catch {
        // armazenamento bloqueado: segue o tamanho da tela
    }
    if (saved === 'cards' || saved === 'table') return saved;

    return window.matchMedia?.('(max-width: 575.98px)').matches ? 'cards' : 'table';
}

function setView(value) {
    view.value = value;
    try {
        localStorage.setItem(VIEW_KEY, value);
    } catch {
        // armazenamento bloqueado: vale só nesta visita
    }
}

// ── Filtros (server-side, na URL) ────────────────────────────────────────
const search = ref(props.filters.search ?? '');
const numberFilter = ref(props.filters.number ?? '');
const automation = ref(props.filters.automation ?? '');

const hasFilters = computed(() => !!(search.value || numberFilter.value || automation.value));

function applyFilters() {
    router.get(
        route('manager.whatsapp.index'),
        {
            search: search.value || undefined,
            number: numberFilter.value || undefined,
            automation: automation.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true, only: ['clinics', 'filters'] },
    );
}

let searchTimer = null;
watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 350);
});
watch([numberFilter, automation], applyFilters);
onBeforeUnmount(() => clearTimeout(searchTimer));

function clearFilters() {
    clearTimeout(searchTimer);
    search.value = '';
    numberFilter.value = '';
    automation.value = '';
}

// ── KPIs (os que filtram a lista são botões com aria-pressed) ────────────
const kpiCards = computed(() => [
    {
        key: 'clinics',
        tone: 'primary',
        icon: 'ti ti-building-hospital',
        toggle: true,
        active: !numberFilter.value && !automation.value,
    },
    { key: 'own', tone: 'success', icon: 'ti ti-brand-whatsapp', toggle: true, active: numberFilter.value === 'own' },
    {
        key: 'global',
        tone: 'cyan',
        icon: 'ti ti-building-broadcast-tower',
        toggle: true,
        active: numberFilter.value === 'global',
    },
    {
        key: 'confirmations',
        tone: 'purple',
        icon: 'ti ti-calendar-check',
        toggle: true,
        active: automation.value === 'confirmation',
    },
    { key: 'messages_sent', tone: 'indigo', icon: 'ti ti-send' },
    { key: 'opt_outs', tone: 'orange', icon: 'ti ti-user-off' },
]);

function onKpi(key) {
    setTab('clinics');
    if (key === 'clinics') {
        numberFilter.value = '';
        automation.value = '';
    } else if (key === 'own' || key === 'global') {
        numberFilter.value = numberFilter.value === key ? '' : key;
    } else if (key === 'confirmations') {
        automation.value = automation.value === 'confirmation' ? '' : 'confirmation';
    }
}

// ── Gaveta de detalhes ───────────────────────────────────────────────────
const detail = ref(null);
const detailOpen = ref(false);

function openDetail(clinic) {
    detail.value = clinic;
    detailOpen.value = true;
}

// A lista recarregou (salvou/filtrou): a gaveta acompanha a linha nova.
watch(
    () => props.clinics?.data,
    (rows) => {
        if (detail.value) detail.value = rows?.find((r) => r.id === detail.value.id) ?? detail.value;
    },
);

// ── Verificação do app próprio (menu ⋮ e gaveta) ─────────────────────────
const clinicHealth = ref({});
const checkingClinic = ref(null);

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function verifyClinic(clinic) {
    if (!clinic || checkingClinic.value) return;
    checkingClinic.value = clinic.id;
    try {
        const { data } = await window.axios.post(
            props.routes.test.replace('__ID__', clinic.id),
            {},
            { headers: { 'X-CSRF-TOKEN': csrf() } },
        );
        clinicHealth.value = { ...clinicHealth.value, [clinic.id]: data };
    } catch (e) {
        clinicHealth.value = {
            ...clinicHealth.value,
            [clinic.id]: { ok: false, error: e.response?.data?.error ?? props.t.connection.failed },
        };
    } finally {
        checkingClinic.value = null;
    }
}

function testFromList(clinic) {
    openDetail(clinic);
    verifyClinic(clinic);
}

// ── Modal de configuração ────────────────────────────────────────────────
const formOpen = ref(false);
const editing = ref(null);

function openConfig(clinic) {
    editing.value = clinic;
    formOpen.value = true;
}

function configureFromDetail(clinic) {
    detailOpen.value = false;
    openConfig(clinic);
}

function onClinicSaved(clinicId) {
    delete clinicHealth.value[clinicId];
    router.reload({ only: ['clinics', 'kpis'], preserveScroll: true });
}

// ── Número do EasyEye (aba própria; a faixa de situação aciona por aqui) ─
const globalPanel = ref(null);
const globalHealth = ref(null);
const globalSaving = ref(false);
const globalTesting = ref(false);

function goConfigureGlobal() {
    setTab('global');
}

function registerGlobalWebhook() {
    globalPanel.value?.registerWebhook();
}

function verifyGlobal() {
    globalPanel.value?.verify();
}

function openGlobalTest() {
    setTab('global');
    globalPanel.value?.focusTest();
}

const breadcrumbs = [];
</script>

<template>
    <AppLayout :title="ui.page_title" :breadcrumbs="breadcrumbs">
        <PageHeader
            :title="ui.page_title"
            :total="kpis.clinics ?? null"
            :total-label="ui.total_label"
            :view="view"
            :view-table-title="ui.view_table"
            :view-cards-title="ui.view_cards"
            :show-view-toggle="tab === 'clinics'"
            @set-view="setView"
        >
            <template #actions>
                <button
                    v-if="global?.has_app"
                    type="button"
                    class="btn btn-outline-success fs-13"
                    :title="ui.send_test_hint"
                    :aria-label="ui.send_test_hint"
                    data-test="header-send-test"
                    @click="openGlobalTest"
                >
                    <i class="ti ti-brand-whatsapp" aria-hidden="true"></i
                    ><span class="d-none d-sm-inline ms-1">{{ ui.send_test }}</span>
                </button>
            </template>
        </PageHeader>

        <WhatsAppStatusBar
            :simulated="simulated"
            :partner="partner"
            :global="global"
            :health="globalHealth"
            :checking="globalTesting"
            :busy="globalSaving"
            :t="t"
            @configure="goConfigureGlobal"
            @register-webhook="registerGlobalWebhook"
            @verify="verifyGlobal"
        />

        <!-- KPIs (tinted; os de filtro são botões) -->
        <section class="wa-kpis mb-3" :aria-label="ui.kpis_label">
            <KpiCard
                v-for="card in kpiCards"
                :key="card.key"
                tinted
                :toggle="!!card.toggle"
                :active="!!card.active"
                :tone="card.tone"
                :icon="card.icon"
                :label="ui.kpis?.[card.key] ?? card.key"
                :hint="ui.kpi_hints?.[card.key] ?? ''"
                :value="number(kpis[card.key] ?? 0)"
                :test-id="card.key"
                :data-kpi="card.key"
                @click="onKpi(card.key)"
            />
        </section>

        <!-- Abas -->
        <ul class="nav nav-tabs mb-3" role="tablist" :aria-label="ui.tabs_label">
            <li v-for="key in ['clinics', 'global', 'templates']" :key="key" class="nav-item" role="presentation">
                <button
                    :id="`wa-tab-${key}`"
                    type="button"
                    class="nav-link"
                    :class="{ active: tab === key }"
                    role="tab"
                    :aria-selected="tab === key"
                    :aria-controls="`wa-panel-${key}`"
                    :tabindex="tab === key ? 0 : -1"
                    :data-test="`tab-${key}`"
                    @click="setTab(key)"
                    @keydown="onTabKey($event, key)"
                >
                    <i :class="`ti ${tabIcons[key]} me-1`" aria-hidden="true"></i>{{ ui.tabs?.[key] }}
                    <span
                        v-if="key === 'global' && !global?.has_app"
                        class="badge rounded-pill bg-danger ms-1 wa-tab-dot"
                        aria-hidden="true"
                        >!</span
                    >
                </button>
            </li>
        </ul>

        <!-- ════════ Clínicas ════════ -->
        <div
            v-show="tab === 'clinics'"
            id="wa-panel-clinics"
            role="tabpanel"
            aria-labelledby="wa-tab-clinics"
            data-test="panel-clinics"
        >
            <div class="wa-filters mb-3" role="search" :aria-label="ui.filters_label">
                <SearchInput
                    v-model="search"
                    class="wa-filters__search"
                    wrapper-class=""
                    :placeholder="ui.search_placeholder"
                    :clear-label="ui.search_clear"
                    max-width="320px"
                />
                <label class="visually-hidden" for="wa-filter-number">{{ ui.filter_number }}</label>
                <select
                    id="wa-filter-number"
                    v-model="numberFilter"
                    class="form-select form-select-sm"
                    data-test="filter-number"
                >
                    <option value="">{{ ui.filter_number_all }}</option>
                    <option v-for="key in ['own', 'global', 'none']" :key="key" :value="key">
                        {{ ui.sending?.[key] }}
                    </option>
                </select>
                <label class="visually-hidden" for="wa-filter-automation">{{ ui.filter_automation }}</label>
                <select
                    id="wa-filter-automation"
                    v-model="automation"
                    class="form-select form-select-sm"
                    data-test="filter-automation"
                >
                    <option value="">{{ ui.filter_automation_all }}</option>
                    <option v-for="key in ['confirmation', 'survey', 'none']" :key="key" :value="key">
                        {{ ui.automation?.[key] }}
                    </option>
                </select>
                <button
                    v-if="hasFilters"
                    type="button"
                    class="btn btn-link btn-sm text-decoration-none text-nowrap px-1"
                    data-test="filter-clear"
                    @click="clearFilters"
                >
                    <i class="ti ti-filter-off me-1" aria-hidden="true"></i>{{ ui.filter_clear }}
                </button>
            </div>

            <WhatsAppClinicTable
                v-if="view === 'table'"
                :clinics="clinics"
                :has-filters="hasFilters"
                :t="t"
                @view="openDetail"
                @configure="openConfig"
                @test="testFromList"
            />
            <WhatsAppClinicCards
                v-else
                :clinics="clinics"
                :has-filters="hasFilters"
                :t="t"
                @view="openDetail"
                @configure="openConfig"
                @test="testFromList"
            />
        </div>

        <!-- ════════ Número do EasyEye ════════ -->
        <div
            v-show="tab === 'global'"
            id="wa-panel-global"
            role="tabpanel"
            aria-labelledby="wa-tab-global"
            data-test="panel-global"
        >
            <WhatsAppGlobalPanel
                ref="globalPanel"
                :global="global"
                :routes="routes"
                :t="t"
                @health="globalHealth = $event"
                @saving="globalSaving = $event"
                @testing="globalTesting = $event"
            />
        </div>

        <!-- ════════ Modelos ════════ -->
        <div
            v-show="tab === 'templates'"
            id="wa-panel-templates"
            role="tabpanel"
            aria-labelledby="wa-tab-templates"
            data-test="panel-templates"
        >
            <WhatsAppTemplates :templates="templates" :enabled-languages="templateLanguages" :t="t.manager" />
        </div>

        <WhatsAppClinicDrawer
            :open="detailOpen"
            :clinic="detail"
            :health="detail ? (clinicHealth[detail.id] ?? null) : null"
            :checking="!!detail && checkingClinic === detail.id"
            :t="t"
            @close="detailOpen = false"
            @configure="configureFromDetail"
            @verify="verifyClinic"
        />

        <WhatsAppClinicFormModal
            :open="formOpen"
            :clinic="editing"
            :global-has-app="!!global?.has_app"
            :routes="routes"
            :t="t"
            @close="formOpen = false"
            @saved="onClinicSaved"
        />
    </AppLayout>
</template>

<style scoped>
.wa-kpis {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.75rem;
}
/* Tela larga: os seis numa linha só (abaixo disso os rótulos cortavam). */
@media (min-width: 1600px) {
    .wa-kpis {
        grid-template-columns: repeat(6, minmax(0, 1fr));
    }
}
/* Celular: 2 por linha (os rótulos quebram em duas linhas). */
@media (max-width: 575.98px) {
    .wa-kpis {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.5rem;
    }
}
.wa-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    align-items: center;
}
.wa-filters__search {
    flex: 0 1 320px;
}
.wa-filters .form-select {
    width: auto;
    min-width: 170px;
    max-width: 100%;
}
.wa-tab-dot {
    font-size: 0.6rem;
    vertical-align: top;
}
@media (max-width: 575.98px) {
    .wa-filters__search,
    .wa-filters .form-select {
        flex: 1 1 100%;
    }
    /* A busca limita a 320px (inline): no celular ocupa a largura toda, como os selects. */
    .wa-filters__search :deep(.input-group) {
        max-width: none !important;
    }
    .nav-tabs {
        flex-wrap: nowrap;
        overflow-x: auto;
        overflow-y: hidden;
    }
    .nav-tabs .nav-link {
        white-space: nowrap;
    }
}
</style>
