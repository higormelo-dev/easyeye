<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useImportProgress } from '@/composables/useImportProgress';
import { usePriceFormat } from './usePriceFormat';

/**
 * Sincronização do catálogo de modelos/preços de IA: "Sincronizar agora",
 * progresso em tempo real (WebSocket/Reverb — sem polling HTTP), resultado
 * por provedor, o que mudou e o histórico. Mesmo padrão das cargas de
 * Medicamentos e Convênios.
 */
const props = defineProps({
    runningSync: { type: Object, default: null },
    syncs: { type: Array, default: () => [] },
    syncDetails: { type: Object, default: null },
    autoSync: { type: Boolean, default: false },
    t: { type: Object, default: () => ({}) },
});

// running: a aba mostra o "carregando"; o botão do cabeçalho chama startSync().
const emit = defineEmits(['update:running']);

const page = usePage();
const { number, usd } = usePriceFormat();

// Ao terminar, relê catálogo, números, seletores de modelo, avisos dos provedores e histórico.
const RELOAD_DONE = ['prices', 'stats', 'modelOptions', 'providers', 'syncs', 'syncDetails', 'runningSync'];

const progress = ref(props.runningSync ?? props.syncs[0] ?? null);
const running = computed(() => !!progress.value && !progress.value.is_done);

watch(running, (value) => emit('update:running', value), { immediate: true });

const { realtimeConnected, resync } = useImportProgress(progress, {
    onDone: () => router.reload({ only: RELOAD_DONE }),
    onResync: () => router.reload({ only: ['runningSync', 'syncs'] }),
});

watch(
    () => props.runningSync,
    (value) => {
        if (value) {
            progress.value = value;
            return;
        }
        // Terminou antes de o WebSocket conectar: resultado final vem do histórico.
        if (running.value) {
            const finished = props.syncs.find((s) => s.id === progress.value.id);
            if (finished) progress.value = finished;
        }
    },
);

function tr(key, fallback, replace = {}) {
    let text = props.t[key] ?? fallback;
    for (const [k, v] of Object.entries(replace)) text = text.replace(`:${k}`, v);
    return text;
}

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

function toast(message, type = 'success') {
    if (!message) return;
    if (type === 'success') return window.showSuccessToast?.(message);
    return window.showErrorToast?.(message);
}

async function post(url) {
    const res = await fetch(url, {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: '{}',
    });
    const json = await res.json().catch(() => ({}));
    return { ok: res.ok, json };
}

const starting = ref(false);

async function startSync() {
    if (starting.value || running.value) return;
    starting.value = true;
    try {
        const { ok, json } = await post(route('manager.ai-catalog-syncs.store'));
        if (!ok) {
            toast(json.message ?? props.t.sync_failed_generic, 'error');
            router.reload({ only: ['runningSync', 'syncs'] });
            return;
        }
        progress.value = json.sync;
        toast(json.message);
    } catch {
        toast(props.t.sync_failed_generic, 'error');
    } finally {
        starting.value = false;
    }
}

// Parada há quanto tempo (sem polling): o servidor manda idle_seconds; aqui
// só soma o tempo desde que o estado chegou (relógio local, sem requisição).
const idleSeconds = ref(0);
let idleBase = { at: Date.now(), seconds: 0 };

watch(
    progress,
    (sync) => {
        idleBase = { at: Date.now(), seconds: sync?.idle_seconds ?? 0 };
        idleSeconds.value = idleBase.seconds;
    },
    { immediate: true },
);

const idleTimer = setInterval(() => {
    if (running.value) idleSeconds.value = idleBase.seconds + Math.floor((Date.now() - idleBase.at) / 1000);
}, 5000);

onBeforeUnmount(() => clearInterval(idleTimer));

const queued = computed(() => running.value && progress.value?.status === 'pending');
const stalled = computed(
    () =>
        running.value &&
        progress.value?.stall_after_seconds != null &&
        idleSeconds.value >= progress.value.stall_after_seconds,
);

const cancelling = ref(false);
const cancelError = ref('');

async function cancelSync() {
    if (!progress.value || cancelling.value) return;
    cancelling.value = true;
    cancelError.value = '';
    try {
        const { ok, json } = await post(route('manager.ai-catalog-syncs.cancel', progress.value.id));
        if (!ok) {
            cancelError.value = json.message ?? '';
            return;
        }
        progress.value = json.sync;
        toast(json.message);
        router.reload({ only: ['syncs'] });
    } finally {
        cancelling.value = false;
    }
}

const alertClass = computed(() => {
    const sync = progress.value;
    if (!sync) return '';
    if (running.value) return stalled.value ? 'alert-warning' : 'alert-info';
    if (sync.status === 'done') return sync.notice ? 'alert-warning' : 'alert-success';
    return sync.status === 'cancelled' ? 'alert-secondary' : 'alert-danger';
});

const counters = computed(() => {
    const s = progress.value ?? {};
    return [
        { key: 'listed', label: tr('result_listed', 'Modelos listados'), value: s.models_listed },
        { key: 'created', label: tr('result_created', 'Novos'), value: s.created_count, cls: 'text-primary' },
        {
            key: 'updated',
            label: tr('result_updated', 'Preços atualizados'),
            value: s.updated_count,
            cls: 'text-success',
        },
        { key: 'unchanged', label: tr('result_unchanged', 'Sem mudança'), value: s.unchanged_count, cls: 'text-muted' },
        { key: 'locked', label: tr('result_locked', 'Travados'), value: s.locked_count, hideZero: true },
        {
            key: 'suspicious',
            label: tr('result_suspicious', 'Para revisar'),
            value: s.suspicious_count,
            cls: 'text-danger',
            hideZero: true,
        },
        {
            key: 'missing',
            label: tr('result_missing_price', 'Sem preço'),
            value: s.missing_price_count,
            hideZero: true,
        },
        {
            key: 'unlisted',
            label: tr('result_unlisted', 'Não listados'),
            value: s.unlisted_count,
            cls: 'text-warning',
            hideZero: true,
        },
    ].filter((c) => !(c.hideZero && !c.value));
});

function providerStatusText(p) {
    if (p.status === 'ok') return tr('provider_status_ok', ':count modelos', { count: number(p.listed) });
    if (p.status === 'failed') return tr('provider_status_failed', 'Falhou');
    return tr('provider_status_skipped', 'Sem chave');
}

function providerStatusClass(p) {
    if (p.status === 'ok') return 'bg-success-subtle text-success border-success';
    if (p.status === 'failed') return 'bg-danger-subtle text-danger border-danger';
    return 'bg-secondary-subtle text-secondary border-secondary';
}

// O que mudou na última sincronização (listas com amostra de até 50 itens).
const DETAIL_LISTS = [
    { key: 'updated', label: 'details_updated', fallback: 'Preços atualizados', change: true },
    { key: 'suspicious', label: 'details_suspicious', fallback: 'Variação suspeita', change: true },
    { key: 'locked', label: 'details_locked', fallback: 'Preço travado diferente do catálogo', change: true },
    { key: 'created', label: 'details_created', fallback: 'Modelos novos' },
    { key: 'unlisted', label: 'details_unlisted', fallback: 'Não oferecidos mais pelo provedor' },
    { key: 'missing_price', label: 'details_missing_price', fallback: 'Sem preço no catálogo' },
];

const detailLists = computed(() =>
    DETAIL_LISTS.map((d) => ({ ...d, items: props.syncDetails?.lists?.[d.key] ?? [] })).filter((d) => d.items.length),
);

function priceText(p) {
    return p ? `${usd(p.input)} / ${usd(p.output)}` : '—';
}

defineExpose({ startSync });
</script>

<template>
    <div data-catalog-sync>
        <!-- Sincronizar agora + progresso em tempo real (WebSocket) -->
        <div class="card">
            <div class="card-body">
                <div class="d-flex flex-wrap align-items-start gap-2 mb-2">
                    <div class="me-auto">
                        <h6 class="fw-semibold mb-1">{{ tr('sync_title', 'Sincronização com os provedores') }}</h6>
                        <p class="text-muted small mb-0">{{ t.sync_help }}</p>
                    </div>
                    <button
                        type="button"
                        class="btn btn-primary"
                        data-sync-now
                        :disabled="starting || running"
                        @click="startSync"
                    >
                        <span
                            v-if="starting || running"
                            class="spinner-border spinner-border-sm me-1"
                            aria-hidden="true"
                        ></span>
                        <i v-else class="ti ti-cloud-download me-1" aria-hidden="true"></i
                        >{{ tr('sync_now', 'Sincronizar agora') }}
                    </button>
                </div>
                <p class="small mb-3" :class="autoSync ? 'text-primary' : 'text-muted'" data-auto-sync>
                    <i class="ti ti-calendar-repeat me-1" aria-hidden="true"></i
                    >{{ autoSync ? t.sync_auto_on : t.sync_auto_off }}
                </p>

                <div
                    v-if="progress"
                    class="alert mb-0"
                    :class="alertClass"
                    role="status"
                    aria-live="polite"
                    data-sync-status
                >
                    <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                        <strong>{{ progress.source_label }}</strong>
                        <span :class="`badge bg-${progress.status_color}`">{{ progress.status_label }}</span>
                        <span v-if="progress.phase_label" class="small text-muted">— {{ progress.phase_label }}</span>
                        <span v-if="progress.finished_at || progress.created_at" class="small text-muted ms-auto">{{
                            progress.finished_at ?? progress.created_at
                        }}</span>
                    </div>

                    <div
                        v-if="running"
                        class="progress mb-2"
                        style="height: 8px"
                        role="progressbar"
                        :aria-valuenow="progress.progress"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        :aria-label="tr('sync_progress_label', 'Progresso da sincronização')"
                    >
                        <div
                            class="progress-bar bg-info progress-bar-striped progress-bar-animated"
                            :style="`width: ${Math.max(progress.progress ?? 0, 3)}%`"
                        ></div>
                    </div>

                    <div v-if="queued" class="small">
                        <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span
                        >{{ tr('sync_waiting_worker', 'Aguardando a fila começar…') }}
                    </div>
                    <div v-else class="small d-flex flex-wrap column-gap-3 row-gap-1">
                        <span v-if="running && progress.total_providers" class="text-muted">{{
                            tr('sync_progress_providers', ':done de :total provedores', {
                                done: progress.processed_providers,
                                total: progress.total_providers,
                            })
                        }}</span>
                        <span v-for="c in counters" :key="c.key" :class="c.cls" :data-counter="c.key"
                            >{{ c.label }}: <strong>{{ number(c.value) }}</strong></span
                        >
                    </div>

                    <ul v-if="progress.providers?.length" class="list-unstyled d-flex flex-wrap gap-2 small mt-2 mb-0">
                        <li v-for="p in progress.providers" :key="p.code" :data-provider-result="p.code">
                            <span class="badge border" :class="providerStatusClass(p)" :title="p.message ?? ''"
                                >{{ p.label }} · {{ providerStatusText(p) }}</span
                            >
                        </li>
                    </ul>
                    <ul
                        v-if="progress.providers?.some((p) => p.status === 'failed')"
                        class="small text-danger mt-2 mb-0 ps-3"
                    >
                        <li v-for="p in progress.providers.filter((x) => x.status === 'failed')" :key="p.code">
                            {{ p.label }}: {{ p.message }}
                        </li>
                    </ul>

                    <div v-if="progress.notice" class="small mt-2">
                        <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ progress.notice }}
                    </div>
                    <div v-if="progress.error" class="small text-danger mt-1">{{ progress.error }}</div>

                    <div v-if="stalled" class="small mt-2" role="alert">
                        <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i
                        >{{ queued ? t.sync_stalled_pending : t.sync_stalled_processing }}
                        <div class="mt-2">
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-danger"
                                data-sync-cancel
                                :disabled="cancelling"
                                @click="cancelSync"
                            >
                                <span
                                    v-if="cancelling"
                                    class="spinner-border spinner-border-sm me-1"
                                    aria-hidden="true"
                                ></span>
                                <i v-else class="ti ti-player-stop me-1" aria-hidden="true"></i
                                >{{ tr('sync_cancel', 'Cancelar sincronização') }}
                            </button>
                        </div>
                        <div v-if="cancelError" class="text-danger mt-1">{{ cancelError }}</div>
                    </div>

                    <div
                        v-if="running && !realtimeConnected"
                        class="d-flex flex-wrap align-items-center gap-2 mt-2 small text-muted"
                    >
                        <span
                            ><i class="ti ti-plug-connected-x me-1" aria-hidden="true"></i
                            >{{ page.props.t_ui?.realtime_offline }}</span
                        >
                        <button type="button" class="btn btn-sm btn-light" data-sync-refresh @click="resync">
                            <i class="ti ti-refresh me-1" aria-hidden="true"></i
                            >{{ tr('sync_refresh_status', 'Atualizar status') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- O que mudou na última sincronização -->
        <div v-if="detailLists.length" class="card" data-details>
            <div class="card-header bg-transparent fw-semibold">
                {{ tr('details_title', 'O que mudou na última sincronização') }}
                <span v-if="syncDetails?.finished_at" class="text-muted fw-normal small ms-1"
                    >({{ syncDetails.finished_at }})</span
                >
            </div>
            <div class="card-body">
                <details
                    v-for="list in detailLists"
                    :key="list.key"
                    class="mb-2"
                    :open="list.key === 'suspicious'"
                    :data-detail-list="list.key"
                >
                    <summary class="fw-semibold small">
                        {{ tr(list.label, list.fallback) }} ({{ number(list.items.length) }})
                    </summary>
                    <div class="table-responsive mt-2">
                        <table class="table table-sm align-middle mb-0 small">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">{{ tr('provider', 'Provedor') }}</th>
                                    <th scope="col">{{ tr('model', 'Modelo') }}</th>
                                    <th v-if="list.change" scope="col">{{ tr('section_prices', 'Preço') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(item, i) in list.items" :key="i">
                                    <td>{{ item.provider_label }}</td>
                                    <td>
                                        <code class="text-break">{{ item.model }}</code>
                                        <span
                                            v-if="item.in_use"
                                            class="badge badge-soft-warning rounded text-warning border border-warning ms-1"
                                            >{{ tr('in_use', 'Em uso') }}</span
                                        >
                                    </td>
                                    <td v-if="list.change">
                                        {{
                                            tr('details_from_to', 'de :old para :new', {
                                                old: priceText(item.old),
                                                new: priceText(item.new),
                                            })
                                        }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </details>
                <p class="text-muted small mb-0">{{ t.details_limit }}</p>
            </div>
        </div>

        <!-- Histórico -->
        <div class="card">
            <div class="card-header bg-transparent fw-semibold">
                {{ tr('history_title', 'Histórico de sincronizações') }}
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0 small" data-history>
                    <thead class="table-light">
                        <tr>
                            <th scope="col">{{ tr('history_when', 'Quando') }}</th>
                            <th scope="col">{{ tr('col_status', 'Status') }}</th>
                            <th scope="col" class="d-none d-md-table-cell">{{ tr('history_origin', 'Origem') }}</th>
                            <th scope="col">{{ tr('history_result', 'Resultado') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!syncs.length">
                            <td colspan="4" class="text-center text-muted py-3">{{ tr('stat_never', 'Nunca') }}</td>
                        </tr>
                        <tr v-for="s in syncs" :key="s.id">
                            <td class="text-nowrap">
                                {{ s.created_at }}
                                <div class="text-muted">{{ s.user ?? tr('history_system', 'Sistema') }}</div>
                            </td>
                            <td>
                                <span :class="`badge bg-${s.status_color}`">{{ s.status_label }}</span>
                            </td>
                            <td class="d-none d-md-table-cell">{{ s.source_label }}</td>
                            <td>
                                <div v-if="s.error" class="text-danger">{{ s.error }}</div>
                                <template v-else-if="s.status === 'done'">
                                    <span class="me-2"
                                        >{{ tr('result_created', 'Novos') }}:
                                        <strong>{{ number(s.created_count) }}</strong></span
                                    >
                                    <span class="me-2"
                                        >{{ tr('result_updated', 'Preços atualizados') }}:
                                        <strong>{{ number(s.updated_count) }}</strong></span
                                    >
                                    <span v-if="s.suspicious_count" class="me-2 text-danger"
                                        >{{ tr('result_suspicious', 'Para revisar') }}:
                                        <strong>{{ number(s.suspicious_count) }}</strong></span
                                    >
                                </template>
                                <span v-else class="text-muted">—</span>
                                <div v-if="s.notice" class="text-muted mt-1">
                                    <i class="ti ti-info-circle me-1" aria-hidden="true"></i>{{ s.notice }}
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
