<script setup>
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import { useTrans } from '@/composables/useTrans';
import { usePlural } from './useReportPage.js';

/**
 * Guias de um convênio no período (linha expandida do relatório de convênios).
 *
 * - JSON paginado de FinancialReportsController::covenantClaims (mesmas regras
 *   do consolidado: sem rascunho/cancelada, "Recebido" só de guia paga).
 * - LGPD: do paciente só código + iniciais — o servidor nunca manda o nome.
 * - Região rotulada; Esc (ou "Fechar") recolhe e o foco volta ao convênio
 *   (quem expandiu cuida disso ao receber `close`).
 * - Atalhos: "Ver no faturamento" (aba Guias filtrada pelo convênio e período;
 *   sem convênio não há filtro equivalente, então some) e "Ver glosas".
 */
const props = defineProps({
    id:       { type: String, required: true },
    row:      { type: Object, required: true },   // linha de byCovenant ({ covenant_id, covenant, ... })
    filters:  { type: Object, required: true },   // { from, to } aplicados
    endpoint: { type: String, required: true },   // routes.claims
    t:        { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close']);

const { money, number, date } = useLocaleFormat();
const { tx } = useTrans(() => props.t.covenants ?? {});
const { plural } = usePlural();

const STATUS_BADGE = {
    submitted: 'badge-soft-info border border-info',
    paid:      'badge-soft-success border border-success',
    denied:    'badge-soft-danger border border-danger',
};

const EMPTY_META = { current_page: 1, last_page: 1, total: 0 };

const c       = computed(() => props.t.covenants ?? {});
const titleId = `covenant-claims-title-${useId()}`;
const title   = computed(() => tx('claims_title', { covenant: props.row.covenant ?? '' }));

const state   = ref('loading'); // loading | ready | error
const claims  = ref([]);
const meta    = ref({ ...EMPTY_META });
const region  = ref(null);

let controller = null;
let requestSeq = 0;

function queryString(page) {
    return new URLSearchParams({
        covenant_id: props.row.covenant_id ?? '',
        from:        props.filters.from ?? '',
        to:          props.filters.to ?? '',
        page:        String(page),
    }).toString();
}

async function load(page = 1) {
    controller?.abort();
    controller = typeof AbortController === 'function' ? new AbortController() : null;

    const seq = ++requestSeq;
    state.value = 'loading';

    try {
        const response = await fetch(`${props.endpoint}?${queryString(page)}`, {
            headers:     { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            signal:      controller?.signal,
        });

        if (seq !== requestSeq) return;

        if (!response.ok) {
            state.value = 'error';

            return;
        }

        const json = await response.json();
        if (seq !== requestSeq) return;

        claims.value = Array.isArray(json?.data) ? json.data : [];
        meta.value   = { ...EMPTY_META, ...(json?.meta ?? {}) };
        state.value  = 'ready';
    } catch (error) {
        if (error?.name === 'AbortError' || seq !== requestSeq) return;

        state.value = 'error';
    }
}

// Troca de convênio ou de período aplicado: recomeça da página 1.
watch(() => [props.row.covenant_id, props.filters.from, props.filters.to], () => load(1), { immediate: true });

onBeforeUnmount(() => {
    requestSeq += 1;
    controller?.abort();
});

/** Página anterior/próxima; se o botão ficou desabilitado (1ª/última página), o foco vai para a região. */
async function goTo(page, event) {
    if (state.value === 'loading' || page < 1 || page > meta.value.last_page) return;

    const button = event?.currentTarget ?? null;

    await load(page);
    await nextTick();

    if (!button || button.disabled || !button.isConnected) region.value?.focus();
}

function retry() {
    load(meta.value.current_page || 1);
}

const statusLabel = (status) => props.t.claim_status?.[status] ?? status;
const statusBadge = (status) => STATUS_BADGE[status] ?? 'badge-soft-secondary border';

// Placeholders sem prefixo comum (:current/:last): o tx() troca na ordem e
// ":page" corromperia ":pages".
const pageStatus = computed(() => tx('pagination_status', {
    current: number(meta.value.current_page),
    last:    number(meta.value.last_page),
}));

/** Anúncio (aria-live) de carregando / página e total — o erro tem role=alert próprio. */
const liveMessage = computed(() => {
    if (state.value === 'loading') return c.value.claims_loading ?? '';
    if (state.value !== 'ready') return '';
    if (!claims.value.length) return c.value.claims_empty ?? '';

    return `${pageStatus.value} · ${plural(c.value, 'claims_count', meta.value.total)}`;
});

const billingHref = computed(() => (props.row.covenant_id
    ? route('panel.financial.billing.index', {
        tab:         'claims',
        covenant_id: props.row.covenant_id,
        from:        props.filters.from,
        to:          props.filters.to,
    })
    : null));

// Aba "todas": na padrão ("pendentes") o período é ignorado e o link mostraria
// as pendentes de qualquer data.
const glosasHref = computed(() => route('panel.financial.tiss.glosas.index', { tab: 'all', from: props.filters.from, to: props.filters.to }));
</script>

<template>
    <div
        :id="id"
        ref="region"
        class="covenant-claims"
        role="region"
        :aria-labelledby="titleId"
        tabindex="-1"
        :aria-busy="state === 'loading' ? 'true' : 'false'"
        data-test="claims-panel"
        @keydown.esc.stop.prevent="emit('close')"
    >
        <div class="d-flex flex-wrap align-items-start gap-2 mb-2">
            <div class="me-auto covenant-claims__heading">
                <h3 :id="titleId" class="fs-6 fw-semibold mb-0" data-test="claims-title">{{ title }}</h3>
                <p class="small text-body-secondary mb-0" data-test="claims-privacy">
                    <i class="ti ti-shield-lock me-1" aria-hidden="true"></i>{{ c.claims_privacy_note }}
                </p>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <Link v-if="billingHref" :href="billingHref" class="btn btn-outline-primary btn-sm" data-test="view-billing">
                    <i class="ti ti-file-invoice me-1" aria-hidden="true"></i>{{ c.view_in_billing }}
                </Link>
                <Link :href="glosasHref" class="btn btn-outline-secondary btn-sm" data-test="view-glosas">
                    <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>{{ c.view_glosas }}
                </Link>
                <button type="button" class="btn btn-link btn-sm text-decoration-none" data-test="claims-close" @click="emit('close')">
                    <i class="ti ti-x me-1" aria-hidden="true"></i>{{ c.claims_close }}
                </button>
            </div>
        </div>

        <p class="visually-hidden" role="status" aria-live="polite" data-test="claims-live">{{ liveMessage }}</p>

        <div v-if="state === 'error'" class="alert alert-danger d-flex flex-wrap align-items-center gap-2 mb-0 py-2" role="alert" data-test="claims-error">
            <i class="ti ti-alert-circle" aria-hidden="true"></i>
            <span class="me-auto">{{ c.claims_error }}</span>
            <button type="button" class="btn btn-sm btn-outline-danger" data-test="claims-retry" @click="retry">{{ c.claims_retry }}</button>
        </div>

        <div v-else-if="state === 'loading' && !claims.length" class="text-body-secondary small py-2" aria-hidden="true" data-test="claims-loading">
            <span class="spinner-border spinner-border-sm me-1"></span>{{ c.claims_loading }}
        </div>

        <p v-else-if="state === 'ready' && !claims.length" class="text-body-secondary small mb-0" data-test="claims-empty">{{ c.claims_empty }}</p>

        <template v-else>
            <div class="table-responsive covenant-claims__results" :class="{ 'covenant-claims__results--loading': state === 'loading' }">
                <table class="table table-sm align-middle mb-0 covenant-claims__table">
                    <caption class="visually-hidden">{{ title }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ c.col_guide }}</th>
                            <th scope="col">{{ c.col_attendance_date }}</th>
                            <th scope="col" class="d-none d-md-table-cell">{{ c.col_patient }}</th>
                            <th scope="col">{{ c.col_status }}</th>
                            <th scope="col" class="text-end">{{ c.col_value }}</th>
                            <th scope="col" class="text-end d-none d-lg-table-cell">{{ c.col_received }}</th>
                            <th scope="col" class="text-end d-none d-lg-table-cell">{{ c.col_glosa }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="claim in claims" :key="claim.id" data-test="claim-row">
                            <th scope="row" class="fw-medium text-body text-nowrap" data-test="claim-code">{{ claim.code || '—' }}</th>
                            <td class="text-nowrap text-body" data-test="claim-date">
                                <time :datetime="claim.attendance_date">{{ date(claim.attendance_date) }}</time>
                            </td>
                            <td class="d-none d-md-table-cell text-nowrap text-body" data-test="claim-patient">{{ claim.patient }}</td>
                            <td>
                                <span class="badge fw-medium" :class="statusBadge(claim.status)" data-test="claim-status">{{ statusLabel(claim.status) }}</span>
                            </td>
                            <td class="text-end text-body text-nowrap" data-test="claim-amount">{{ money(claim.amount) }}</td>
                            <td class="text-end text-body text-nowrap d-none d-lg-table-cell" data-test="claim-received">{{ money(claim.received) }}</td>
                            <td class="text-end text-body text-nowrap d-none d-lg-table-cell" data-test="claim-glosa">{{ money(claim.glosa) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <nav
                v-if="meta.last_page > 1"
                class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-2"
                :aria-label="c.pagination_label"
                data-test="claims-pagination"
            >
                <span class="small text-body-secondary" data-test="claims-page-status">
                    {{ pageStatus }} · {{ plural(c, 'claims_count', meta.total) }}
                </span>
                <div class="btn-group btn-group-sm">
                    <button
                        type="button"
                        class="btn btn-outline-secondary"
                        :disabled="meta.current_page <= 1"
                        :aria-label="c.pagination_previous"
                        :title="c.pagination_previous"
                        data-test="claims-prev"
                        @click="goTo(meta.current_page - 1, $event)"
                    ><i class="ti ti-arrow-left" aria-hidden="true"></i></button>
                    <button
                        type="button"
                        class="btn btn-outline-secondary"
                        :disabled="meta.current_page >= meta.last_page"
                        :aria-label="c.pagination_next"
                        :title="c.pagination_next"
                        data-test="claims-next"
                        @click="goTo(meta.current_page + 1, $event)"
                    ><i class="ti ti-arrow-right" aria-hidden="true"></i></button>
                </div>
            </nav>
        </template>
    </div>
</template>

<style scoped>
.covenant-claims:focus {
    outline: none;
}

.covenant-claims:focus-visible {
    outline: 2px solid var(--bs-primary);
    outline-offset: -2px;
}

.covenant-claims__heading {
    min-width: 0;
}

.covenant-claims__table td {
    font-variant-numeric: tabular-nums;
}

.covenant-claims__results {
    transition: opacity var(--ee-duration-fast, 150ms) ease;
}

.covenant-claims__results--loading {
    opacity: 0.6;
}

@media (prefers-reduced-motion: reduce) {
    .covenant-claims__results {
        transition: none;
    }
}
</style>
