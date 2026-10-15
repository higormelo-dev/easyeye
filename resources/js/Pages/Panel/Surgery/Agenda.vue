<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import DoctorMissingAlert from '@/Components/Panel/DoctorMissingAlert.vue';
import ScheduleFormModal from '@/Components/Surgery/ScheduleFormModal.vue';
import SurgeryStatusBadge from '@/Components/Surgery/SurgeryStatusBadge.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

/**
 * Agenda cirúrgica: o dia por sala (colunas) ou a semana por dia, com filtro de
 * local e de cirurgião, e ao lado a fila de casos autorizados esperando vaga.
 * Só lê (SurgeryAgendaController); agendar é o ScheduleFormModal, que confere
 * os conflitos antes de salvar. Sem polling: a tela recarrega ao navegar,
 * filtrar ou agendar.
 */
const props = defineProps({
    breadcrumbs: { type: Array, default: () => [] },
    view: { type: String, default: 'day' },
    date: { type: String, required: true },
    range: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    cases: { type: Array, default: () => [] },
    queue: { type: Array, default: () => [] },
    rooms: { type: Array, default: () => [] },
    hours: { type: Object, default: () => ({ start: 6, end: 22 }) },
    options: { type: Object, default: () => ({ facilities: [], surgeons: [] }) },
    can: { type: Object, default: () => ({}) },
    doctorMissing: { type: Boolean, default: false },
    doctorMissingText: { type: String, default: '' },
    urls: { type: Object, default: () => ({}) },
    t: { type: Object, required: true },
});

const { locale, date: formatDate } = useLocaleFormat();
const s = computed(() => props.t.schedule ?? {});
const a = computed(() => s.value.agenda ?? {});

const HOUR_PX = 64;
const NO_ROOM = '__none__';

// ── Datas (sempre locais; 'YYYY-MM-DD' puro não passa por fuso) ───────────
const parseDay = (iso) => new Date(`${iso}T00:00:00`);
const pad = (n) => String(n).padStart(2, '0');
const isoDay = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const addDays = (iso, n) => {
    const d = parseDay(iso);
    d.setDate(d.getDate() + n);
    return isoDay(d);
};
const timeOf = (iso) =>
    new Intl.DateTimeFormat(locale.value, { hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
const dayLabel = (iso, opts = { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' }) =>
    new Intl.DateTimeFormat(locale.value, opts).format(parseDay(iso));

const isDay = computed(() => props.view === 'day');
const title = computed(() =>
    isDay.value
        ? dayLabel(props.date)
        : `${dayLabel(props.range.from, { day: '2-digit', month: 'short' })} – ${dayLabel(props.range.to, { day: '2-digit', month: 'short', year: 'numeric' })}`,
);

// ── Navegação (filtros viram query; o servidor devolve o recorte) ─────────
function go(partial = {}) {
    const query = { view: props.view, date: props.date, ...props.filters, ...partial };
    Object.keys(query).forEach((k) => (query[k] === null || query[k] === '') && delete query[k]);

    router.get(props.urls.agenda, query, { preserveState: true, preserveScroll: true, replace: true });
}

const step = (dir) => go({ date: addDays(props.date, dir * (isDay.value ? 1 : 7)) });
const today = () => go({ date: isoDay(new Date()) });
const setFilter = (key, value) => go({ [key]: value || null });
const mapUrl = computed(() => {
    const q = new URLSearchParams({ date: props.date });
    if (props.filters.facility) q.set('facility', props.filters.facility);

    return `${props.urls.map}?${q.toString()}`;
});

// ── Grade do dia ──────────────────────────────────────────────────────────
const gridHeight = computed(() => (props.hours.end - props.hours.start) * HOUR_PX);
const hourMarks = computed(() =>
    Array.from({ length: props.hours.end - props.hours.start }, (_, i) => props.hours.start + i),
);

const columns = computed(() => {
    const visible = props.rooms.filter((r) => !props.filters.facility || r.facility_id === props.filters.facility);
    const cols = visible.map((r) => ({ id: r.id, name: r.name, facility: r.facility }));
    if (props.cases.some((c) => !c.room_id)) cols.push({ id: NO_ROOM, name: a.value.no_room, facility: null });

    return cols;
});

function minutesFromGridStart(iso) {
    const d = new Date(iso);

    return d.getHours() * 60 + d.getMinutes() - props.hours.start * 60;
}

/** Posição vertical e "faixa" (lane) dos blocos de uma coluna; sobreposição (sem sala) divide a largura. */
function layout(list) {
    const placed = list
        .map((c) => {
            const top = Math.max(0, minutesFromGridStart(c.scheduled_start));
            const end = Math.min((props.hours.end - props.hours.start) * 60, minutesFromGridStart(c.scheduled_end));

            return { c, top, end: Math.max(end, top + 30), lane: 0, lanes: 1 };
        })
        .sort((x, y) => x.top - y.top);

    const laneEnds = [];
    for (const item of placed) {
        let lane = laneEnds.findIndex((end) => end <= item.top);
        if (lane === -1) lane = laneEnds.length;
        laneEnds[lane] = item.end;
        item.lane = lane;
    }
    const lanes = Math.max(1, laneEnds.length);
    placed.forEach((item) => (item.lanes = lanes));

    return placed.map((item) => ({
        c: item.c,
        style: {
            top: `${(item.top / 60) * HOUR_PX}px`,
            height: `${((item.end - item.top) / 60) * HOUR_PX}px`,
            left: `${(item.lane / item.lanes) * 100}%`,
            width: `${100 / item.lanes}%`,
        },
    }));
}

const dayCases = computed(() => props.cases.filter((c) => isoDay(new Date(c.scheduled_start)) === props.date));
const columnBlocks = (col) =>
    layout(dayCases.value.filter((c) => (col.id === NO_ROOM ? !c.room_id : c.room_id === col.id)));

// ── Semana ────────────────────────────────────────────────────────────────
const weekDays = computed(() => Array.from({ length: 7 }, (_, i) => addDays(props.range.from, i)));
const casesOfDay = (iso) => props.cases.filter((c) => isoDay(new Date(c.scheduled_start)) === iso);

// ── Acessibilidade e rótulos ──────────────────────────────────────────────
const blockLabel = (c) =>
    [
        `${timeOf(c.scheduled_start)}–${timeOf(c.scheduled_end)}`,
        c.title,
        c.eye?.short,
        c.patient.name,
        c.room,
        c.status.label,
        progressText(c),
    ]
        .filter(Boolean)
        .join(', ');
const progressText = (c) =>
    c.checklist ? (a.value.checklist_progress ?? '').replace(':done', String(c.checklist.filter(Boolean).length)) : '';
const showUrl = (id) => props.urls.show.replace('__ID__', id);
const checklistLabel = (c) =>
    c.checklist
        .map((done, i) => `${a.value.phase_short?.[i] ?? i + 1}: ${done ? a.value.done : a.value.pending}`)
        .join(' · ');

// ── Agendar a partir da fila ──────────────────────────────────────────────
const scheduling = ref(null);

const formUrls = computed(() =>
    scheduling.value
        ? {
              submit: props.urls.schedule.replace('__ID__', scheduling.value.id),
              check: props.urls.schedule_check.replace('__ID__', scheduling.value.id),
              facilities: props.urls.facilities,
          }
        : {},
);

function openSchedule(row) {
    scheduling.value = {
        id: row.id,
        title: row.title,
        eye: row.eye?.short ?? '',
        patient: row.patient.name,
        revision: row.revision,
        start: null,
        duration: row.duration,
        facility_id: row.facility_id,
        room_id: null,
    };
}

function saved() {
    scheduling.value = null;
    router.reload({ only: ['cases', 'queue', 'rooms', 'flash'], preserveScroll: true });
}

const summary = computed(() => (a.value.summary ?? '').replace(':count', String(props.cases.length)));
</script>

<template>
    <Head :title="a.title" />

    <AppLayout :title="a.title" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3 sa-page">
            <PageHeader :title="a.title" :subtitle="a.subtitle">
                <template #actions>
                    <a
                        :href="mapUrl"
                        class="btn btn-outline-secondary btn-sm"
                        target="_blank"
                        rel="noopener"
                        data-testid="agenda-map"
                    >
                        <i class="ti ti-printer me-1" aria-hidden="true"></i>{{ a.map }}
                    </a>
                    <Link v-if="can.manage" :href="urls.index" class="btn btn-outline-secondary btn-sm">
                        <i class="ti ti-list me-1" aria-hidden="true"></i>{{ a.cases }}
                    </Link>
                </template>
            </PageHeader>

            <DoctorMissingAlert v-if="doctorMissing" :text="doctorMissingText" />

            <template v-else>
                <!-- Barra de ferramentas -->
                <div class="d-flex flex-wrap align-items-end gap-2 mb-3" role="toolbar" :aria-label="a.toolbar">
                    <div class="btn-group">
                        <button
                            type="button"
                            class="btn btn-outline-secondary"
                            :aria-label="a.previous"
                            data-testid="agenda-prev"
                            @click="step(-1)"
                        >
                            <i class="ti ti-chevron-left" aria-hidden="true"></i>
                        </button>
                        <button
                            type="button"
                            class="btn btn-outline-secondary"
                            data-testid="agenda-today"
                            @click="today"
                        >
                            {{ a.today }}
                        </button>
                        <button
                            type="button"
                            class="btn btn-outline-secondary"
                            :aria-label="a.next"
                            data-testid="agenda-next"
                            @click="step(1)"
                        >
                            <i class="ti ti-chevron-right" aria-hidden="true"></i>
                        </button>
                    </div>

                    <div>
                        <label class="form-label small mb-0" for="agenda-date">{{ a.date }}</label>
                        <input
                            id="agenda-date"
                            type="date"
                            class="form-control"
                            :value="date"
                            @change="go({ date: $event.target.value })"
                        />
                    </div>

                    <div class="btn-group" role="group" :aria-label="a.view">
                        <button
                            type="button"
                            class="btn"
                            :class="isDay ? 'btn-primary' : 'btn-outline-primary'"
                            :aria-pressed="isDay"
                            data-testid="view-day"
                            @click="go({ view: 'day' })"
                        >
                            {{ a.day }}
                        </button>
                        <button
                            type="button"
                            class="btn"
                            :class="!isDay ? 'btn-primary' : 'btn-outline-primary'"
                            :aria-pressed="!isDay"
                            data-testid="view-week"
                            @click="go({ view: 'week' })"
                        >
                            {{ a.week }}
                        </button>
                    </div>

                    <div>
                        <label class="form-label small mb-0" for="agenda-facility">{{ a.facility }}</label>
                        <select
                            id="agenda-facility"
                            class="form-select"
                            :value="filters.facility ?? ''"
                            @change="setFilter('facility', $event.target.value)"
                        >
                            <option value="">{{ a.all_facilities }}</option>
                            <option v-for="f in options.facilities" :key="f.id" :value="f.id">{{ f.name }}</option>
                        </select>
                    </div>
                    <div v-if="options.surgeons.length > 1 || filters.surgeon">
                        <label class="form-label small mb-0" for="agenda-surgeon">{{ a.surgeon }}</label>
                        <select
                            id="agenda-surgeon"
                            class="form-select"
                            :value="filters.surgeon ?? ''"
                            @change="setFilter('surgeon', $event.target.value)"
                        >
                            <option value="">{{ a.all_surgeons }}</option>
                            <option v-for="d in options.surgeons" :key="d.id" :value="d.id">{{ d.name }}</option>
                        </select>
                    </div>
                </div>

                <h2 class="h5 text-capitalize mb-3" data-testid="agenda-title">{{ title }}</h2>
                <span class="visually-hidden" aria-live="polite">{{ summary }}</span>

                <div class="row g-3">
                    <!-- Agenda -->
                    <div class="col-xl-9">
                        <div
                            v-if="!options.facilities.length"
                            class="alert alert-info"
                            data-testid="agenda-no-facility"
                        >
                            {{ a.no_facility }}
                            <a :href="urls.facilities">{{ a.configure }}</a>
                        </div>

                        <!-- Dia: colunas = salas -->
                        <section v-if="isDay" class="card" :aria-label="a.grid">
                            <div
                                v-if="!columns.length"
                                class="card-body text-center text-muted py-5"
                                data-testid="agenda-empty"
                            >
                                {{ a.no_rooms }}
                            </div>
                            <div v-else class="sa-scroll">
                                <div class="sa-grid" :style="{ '--sa-cols': columns.length }">
                                    <div class="sa-axis" :style="{ height: `${gridHeight}px` }" aria-hidden="true">
                                        <div
                                            v-for="h in hourMarks"
                                            :key="h"
                                            class="sa-hour"
                                            :style="{ height: `${HOUR_PX}px` }"
                                        >
                                            {{ pad(h) }}:00
                                        </div>
                                    </div>
                                    <div
                                        v-for="col in columns"
                                        :key="col.id"
                                        class="sa-col"
                                        :data-testid="`col-${col.id}`"
                                    >
                                        <div class="sa-col__head">
                                            <strong>{{ col.name }}</strong>
                                            <small v-if="col.facility" class="text-muted d-block">{{
                                                col.facility
                                            }}</small>
                                        </div>
                                        <ol
                                            class="sa-col__body list-unstyled mb-0"
                                            :style="{ height: `${gridHeight}px` }"
                                            :aria-label="col.name"
                                        >
                                            <li
                                                v-for="h in hourMarks"
                                                :key="`l${h}`"
                                                class="sa-line"
                                                :style="{ top: `${(h - hours.start) * HOUR_PX}px` }"
                                                aria-hidden="true"
                                            ></li>
                                            <li
                                                v-for="block in columnBlocks(col)"
                                                :key="block.c.id"
                                                class="sa-block-wrap"
                                                :style="block.style"
                                            >
                                                <Link
                                                    :href="showUrl(block.c.id)"
                                                    class="sa-block"
                                                    :class="`sa-block--${block.c.status.value}`"
                                                    :aria-label="blockLabel(block.c)"
                                                    :title="checklistLabel(block.c)"
                                                    data-testid="agenda-block"
                                                >
                                                    <span class="fw-semibold"
                                                        >{{ timeOf(block.c.scheduled_start) }}–{{
                                                            timeOf(block.c.scheduled_end)
                                                        }}</span
                                                    >
                                                    <span class="d-block text-truncate"
                                                        >{{ block.c.title }}
                                                        <strong v-if="block.c.eye" data-testid="block-eye">{{
                                                            block.c.eye.short
                                                        }}</strong></span
                                                    >
                                                    <span class="d-block text-truncate small">{{
                                                        block.c.patient.name
                                                    }}</span>
                                                    <span class="d-block text-truncate small">{{
                                                        block.c.surgeon.name
                                                    }}</span>
                                                    <span class="visually-hidden">{{ checklistLabel(block.c) }}</span>
                                                    <span class="sa-dots" aria-hidden="true">
                                                        <i
                                                            v-for="(done, i) in block.c.checklist"
                                                            :key="i"
                                                            class="sa-dot"
                                                            :class="{ 'is-done': done }"
                                                        ></i>
                                                    </span>
                                                </Link>
                                            </li>
                                        </ol>
                                    </div>
                                </div>
                            </div>
                            <p
                                v-if="columns.length && !dayCases.length"
                                class="text-center text-muted py-3 mb-0"
                                data-testid="agenda-day-empty"
                            >
                                {{ a.day_empty }}
                            </p>
                        </section>

                        <!-- Semana: colunas = dias -->
                        <section v-else class="row g-2" :aria-label="a.grid" data-testid="agenda-week">
                            <div v-for="d in weekDays" :key="d" class="col-12 col-md-6 col-xxl">
                                <div class="card h-100">
                                    <div
                                        class="card-header py-2 text-capitalize"
                                        :class="{ 'bg-primary-subtle': d === date }"
                                    >
                                        <strong>{{
                                            dayLabel(d, { weekday: 'short', day: '2-digit', month: 'short' })
                                        }}</strong>
                                    </div>
                                    <ul class="list-group list-group-flush">
                                        <li v-if="!casesOfDay(d).length" class="list-group-item text-muted small">
                                            {{ a.day_empty }}
                                        </li>
                                        <li
                                            v-for="c in casesOfDay(d)"
                                            :key="c.id"
                                            class="list-group-item px-2 py-2"
                                            data-testid="week-case"
                                        >
                                            <Link
                                                :href="showUrl(c.id)"
                                                class="text-decoration-none d-block"
                                                :aria-label="blockLabel(c)"
                                            >
                                                <span class="fw-semibold"
                                                    >{{ timeOf(c.scheduled_start) }}–{{ timeOf(c.scheduled_end) }}</span
                                                >
                                                <span class="d-block text-body"
                                                    >{{ c.title }} <strong>{{ c.eye?.short }}</strong></span
                                                >
                                                <span class="d-block small text-muted"
                                                    >{{ c.patient.name }} · {{ c.room ?? a.no_room }}</span
                                                >
                                            </Link>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </section>
                    </div>

                    <!-- Fila: autorizados esperando vaga -->
                    <aside class="col-xl-3" :aria-label="a.queue">
                        <div class="card">
                            <div class="card-header d-flex align-items-center gap-2">
                                <h2 class="h6 mb-0">{{ a.queue }}</h2>
                                <span class="badge text-bg-secondary ms-auto">{{ queue.length }}</span>
                            </div>
                            <p v-if="!queue.length" class="card-body text-muted small mb-0" data-testid="queue-empty">
                                {{ a.queue_empty }}
                            </p>
                            <ul v-else class="list-group list-group-flush" data-testid="queue">
                                <li v-for="row in queue" :key="row.id" class="list-group-item" data-testid="queue-item">
                                    <div class="d-flex align-items-start gap-2">
                                        <div class="me-auto">
                                            <Link :href="showUrl(row.id)" class="fw-medium text-decoration-none">{{
                                                row.title
                                            }}</Link>
                                            <strong v-if="row.eye" class="ms-1" data-testid="queue-eye">{{
                                                row.eye.short
                                            }}</strong>
                                            <span v-if="row.urgent" class="badge text-bg-danger ms-1">{{
                                                a.urgent
                                            }}</span>
                                            <div class="small text-muted">
                                                {{ row.patient.name }} · {{ row.surgeon.name }}
                                            </div>
                                            <div class="small text-muted">
                                                {{ a.duration.replace(':n', row.duration) }}
                                                <template v-if="row.valid_until">
                                                    ·
                                                    {{
                                                        a.valid_until.replace(':date', formatDate(row.valid_until))
                                                    }}</template
                                                >
                                            </div>
                                            <SurgeryStatusBadge :status="row.status" />
                                        </div>
                                        <button
                                            v-if="can.manage"
                                            type="button"
                                            class="btn btn-sm btn-outline-primary"
                                            data-testid="queue-schedule"
                                            @click="openSchedule(row)"
                                        >
                                            {{ a.schedule }}
                                        </button>
                                    </div>
                                </li>
                            </ul>
                        </div>
                    </aside>
                </div>
            </template>
        </div>

        <ScheduleFormModal
            :open="scheduling !== null"
            mode="schedule"
            :target="scheduling"
            :facilities="options.facilities"
            :default-date="date"
            :default-facility="filters.facility ?? ''"
            :urls="formUrls"
            :t="t"
            @close="scheduling = null"
            @saved="saved"
        />
    </AppLayout>
</template>

<style scoped>
.sa-scroll {
    overflow-x: auto;
}
.sa-grid {
    display: grid;
    grid-template-columns: 56px repeat(var(--sa-cols), minmax(190px, 1fr));
    min-width: min-content;
}
.sa-axis {
    margin-top: 48px;
    border-right: 1px solid var(--bs-border-color);
}
.sa-hour {
    font-size: 0.75rem;
    color: var(--bs-secondary-color);
    text-align: right;
    padding-right: 6px;
    border-top: 1px solid var(--bs-border-color-translucent);
}
.sa-col {
    border-right: 1px solid var(--bs-border-color);
    min-width: 0;
}
.sa-col__head {
    height: 48px;
    padding: 4px 8px;
    border-bottom: 1px solid var(--bs-border-color);
    background: var(--bs-tertiary-bg);
    overflow: hidden;
}
.sa-col__body {
    position: relative;
}
.sa-line {
    position: absolute;
    left: 0;
    right: 0;
    border-top: 1px solid var(--bs-border-color-translucent);
}
.sa-block-wrap {
    position: absolute;
    padding: 1px 2px;
}
.sa-block {
    display: block;
    height: 100%;
    overflow: hidden;
    padding: 4px 6px;
    border-radius: 6px;
    border-left: 4px solid var(--bs-primary);
    background: var(--bs-primary-bg-subtle);
    color: var(--bs-body-color);
    text-decoration: none;
    font-size: 0.8rem;
    line-height: 1.25;
}
.sa-block:focus-visible,
.sa-block:hover {
    outline: 2px solid var(--bs-primary);
    outline-offset: 1px;
}
.sa-block--performed {
    border-left-color: var(--bs-success);
    background: var(--bs-success-bg-subtle);
}
.sa-dots {
    display: inline-flex;
    gap: 3px;
    margin-top: 2px;
}
.sa-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    border: 1px solid var(--bs-secondary);
}
.sa-dot.is-done {
    background: var(--bs-success);
    border-color: var(--bs-success);
}
</style>
