<script setup>
import { ref, watch, defineAsyncComponent } from 'vue';
import { router }               from '@inertiajs/vue3';
import AppLayout                from '@/Layouts/AppLayout.vue';
import ScheduleCard             from './ScheduleCard.vue';
import EventCard                from './EventCard.vue';
import ScheduleFormModal        from './ScheduleFormModal.vue';

// Lazy: FullCalendar (~77 kB gz) só é carregado quando o utilizador activa a vista calendário.
const CalendarView = defineAsyncComponent(() => import('./CalendarView.vue'));
import RescheduleModal          from './RescheduleModal.vue';
import CancelModal              from './CancelModal.vue';
import BulkCancelModal          from './BulkCancelModal.vue';
import BulkRescheduleModal      from './BulkRescheduleModal.vue';
import NoticesPanel             from './NoticesPanel.vue';
import WaitingListPanel         from './WaitingListPanel.vue';
import WaitingListFormModal     from './WaitingListFormModal.vue';
import ScheduleDetailDrawer     from './ScheduleDetailDrawer.vue';
import { useDashboardPolling }  from '@/composables/useDashboardPolling.js';
import MiniCalendar             from '@/Components/Panel/MiniCalendar.vue';

const props = defineProps({
    scheduleItems: { type: Array,   default: () => [] },
    doctors:       { type: Array,   default: () => [] },
    covenants:     { type: Array,   default: () => [] },
    visitTypes:    { type: Array,   default: () => [] },
    situations:    { type: Array,   default: () => [] },
    moods:         { type: Array,   default: () => [] },
    filters:       { type: Object,  default: () => ({}) },
    isDoctor:      { type: Boolean, default: false },
    isStaff:       { type: Boolean, default: false },
    t:             { type: Object,  default: () => ({}) },
});

// ── Polling ────────────────────────────────────────────────────────────────────
useDashboardPolling(['scheduleItems'], 30_000);

// ── View mode (list | calendar) — persistido no localStorage ──────────────────
const viewMode = ref(localStorage.getItem('schedules_view_mode') ?? 'list');

watch(viewMode, (mode) => {
    localStorage.setItem('schedules_view_mode', mode);
    if (mode === 'calendar') {
        selectionMode.value = false;
        selectedIds.value   = [];
    }
});

// ── Filters ────────────────────────────────────────────────────────────────────
const date   = ref(props.filters.date   ?? new Date().toISOString().substring(0, 10));
const doctor = ref(props.filters.doctor ?? 'tudo');
const bout   = ref(props.filters.bout   ?? 1);
const search = ref(props.filters.search ?? '');

let searchDebounce = null;

function applyFilters(partial = false) {
    router.get(
        route('panel.schedules.index'),
        { date: date.value, doctor: doctor.value, bout: bout.value, search: search.value },
        { preserveState: true, replace: true, only: partial ? ['scheduleItems', 'filters'] : undefined },
    );
}

function onSearchInput() {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => applyFilters(true), 400);
}

function setDoctor(id) { doctor.value = id; applyFilters(true); }
function setBout(b)    { bout.value   = b;  applyFilters(true); }

// ── Date navigation ────────────────────────────────────────────────────────────
function goToDate(d) { date.value = d; applyFilters(true); }

// ── Locale para o MiniCalendar (resolvido fora do template — `window` não é exposto) ──
const sessionLocale = (typeof window !== 'undefined' && window.sessionLocale)
    ? window.sessionLocale
    : 'pt-BR';

// ── Painel de recados ──────────────────────────────────────────────────────────
const noticesPanelRef = ref(null);
const noticesUnread   = ref(0);

// ── Lista de espera ────────────────────────────────────────────────────────────
const wlPanelRef = ref(null);
const wlCount    = ref(0);
const wlFormOpen = ref(false);

function onScheduleFromWaiting(entry) {
    prefillData.value  = entry;
    editSchedule.value = null;
    formOpen.value     = true;
}

function onWlSaved() {
    wlFormOpen.value = false;
    wlPanelRef.value?.fetch();
}

// ── Bulk selection ─────────────────────────────────────────────────────────────
const selectionMode = ref(false);
const selectedIds   = ref([]);

function toggleSelectionMode() {
    selectionMode.value = !selectionMode.value;
    selectedIds.value   = [];
}

function toggleSelect(id) {
    const idx = selectedIds.value.indexOf(id);
    if (idx === -1) selectedIds.value.push(id);
    else selectedIds.value.splice(idx, 1);
}

function isSelected(id) { return selectedIds.value.includes(id); }

async function bulkUpdate(situation) {
    if (selectedIds.value.length === 0) return;
    const res = await fetch(route('panel.schedules.bulk-update'), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept':       'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify({ ids: selectedIds.value, situation }),
    });
    const json = await res.json();
    showToast(json.message, res.ok ? 'success' : 'error');
    if (res.ok) {
        selectedIds.value   = [];
        selectionMode.value = false;
        router.reload({ only: ['scheduleItems'] });
    }
}

// ── Bulk cancel ────────────────────────────────────────────────────────────────
const bulkCancelOpen = ref(false);

function onBulkCancelled(json) {
    bulkCancelOpen.value = false;
    showToast(json.message, 'success');
    selectedIds.value   = [];
    selectionMode.value = false;
    router.reload({ only: ['scheduleItems'] });
}

// ── Bulk reschedule ────────────────────────────────────────────────────────────
const bulkRescheduleOpen = ref(false);

function onBulkRescheduled(json) {
    bulkRescheduleOpen.value = false;
    showToast(json.message, 'success');
    selectedIds.value   = [];
    selectionMode.value = false;
    router.reload({ only: ['scheduleItems'] });
}

// ── Schedule form modal ────────────────────────────────────────────────────────
const formOpen     = ref(false);
const editSchedule = ref(null);
const prefillData  = ref(null);

function openCreate() {
    editSchedule.value = null;
    prefillData.value  = null;
    formOpen.value     = true;
}

async function openEdit(item) {
    const res = await fetch(item.show_url, { headers: { Accept: 'application/json' } });
    if (res.ok) {
        const json         = await res.json();
        editSchedule.value = json.data;
    }
    prefillData.value = null;
    formOpen.value    = true;
}

// ── Detail drawer ──────────────────────────────────────────────────────────────
const detailOpen       = ref(false);
const viewScheduleId   = ref(null);

function onView(item) {
    viewScheduleId.value = item.id;
    detailOpen.value     = true;
}

function closeDetail() {
    detailOpen.value     = false;
    viewScheduleId.value = null;
}

function onSaved() {
    formOpen.value     = false;
    editSchedule.value = null;
    prefillData.value  = null;
    router.reload({ only: ['scheduleItems'] });
}

// ── Interação com CalendarView ─────────────────────────────────────────────────
async function onCalendarEventClick(item) {
    if (item.type !== 'schedule') return;
    await openEdit(item);
}

function onCalendarSlotClick({ datetime }) {
    editSchedule.value = null;
    prefillData.value  = { date_time: datetime };
    formOpen.value     = true;
}

// ── Reschedule modal ───────────────────────────────────────────────────────────
const rescheduleItem = ref(null);

function openReschedule(item) { rescheduleItem.value = item; }

function onRescheduled(json) {
    rescheduleItem.value = null;
    showToast(json.message, 'success');
    router.reload({ only: ['scheduleItems'] });
}

// ── Cancel modal ───────────────────────────────────────────────────────────────
const cancelItem = ref(null);

function openCancel(item) { cancelItem.value = item; }

function onCancelled(json) {
    cancelItem.value = null;
    showToast(json.message, 'success');
    router.reload({ only: ['scheduleItems'] });
}

// ── Situation / mood change ────────────────────────────────────────────────────
async function onChangeSituation({ item, to }) {
    const res = await fetch(item.situation_url, {
        method: 'PATCH',
        headers: {
            'Content-Type': 'application/json',
            'Accept':       'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify({ situation: to }),
    });
    const json = await res.json();
    showToast(json.message, res.ok ? 'success' : 'error');
    if (res.ok) router.reload({ only: ['scheduleItems'] });
}

async function onChangeMood({ item, mood }) {
    const res = await fetch(item.mood_url, {
        method: 'PATCH',
        headers: {
            'Content-Type': 'application/json',
            'Accept':       'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify({ mood }),
    });
    const json = await res.json();
    showToast(json.message, res.ok ? 'success' : 'error');
    if (res.ok) router.reload({ only: ['scheduleItems'] });
}

// ── Helpers ────────────────────────────────────────────────────────────────────
function showToast(msg, type = 'success') {
    if (type === 'success' && window.showSuccessToast) return window.showSuccessToast(msg);
    if (type === 'error'   && window.showErrorToast)   return window.showErrorToast(msg);
    alert(msg);
}

const storeUrl    = route('panel.schedules.store');
const breadcrumbs = [
    { label: props.t.breadcrumb_home    ?? 'Dashboard', url: route('panel.dashboard'), active: false },
    { label: props.t.breadcrumb_current ?? 'Agenda',    url: '#', active: true },
];
</script>

<template>
    <AppLayout :title="t.page_title" :breadcrumbs="breadcrumbs">
        <div>

            <!-- ════════════════════════════════════════════════════════════
                 LINHA 1 — Ação principal + navegação de datas
                 ════════════════════════════════════════════════════════════ -->
            <div class="d-flex align-items-center gap-2 pb-3 mb-0 border-bottom flex-wrap">

                <div class="d-flex align-items-center gap-2 me-auto">
                    <h4 class="mb-0 fw-bold">{{ t.page_title }}</h4>
                    <span style="font-size:.78rem;font-weight:600;color:#0d6efd;background:#eff4ff;border:1.5px solid #0d6efd;border-radius:20px;padding:2px 12px;white-space:nowrap;line-height:1.6;">Total: {{ scheduleItems.length }}</span>
                </div>

                <!-- Toggle lista / calendário -->
                <div class="bg-white border shadow-sm rounded px-1 d-flex align-items-center">
                    <button type="button"
                            class="rounded p-1 d-flex align-items-center border-0"
                            :class="viewMode === 'list' ? 'bg-light' : 'bg-white'"
                            :title="t.view_list"
                            @click="viewMode = 'list'">
                        <i class="ti ti-list fs-14 text-body"></i>
                    </button>
                    <button type="button"
                            class="rounded p-1 d-flex align-items-center border-0"
                            :class="viewMode === 'calendar' ? 'bg-light' : 'bg-white'"
                            :title="t.view_calendar"
                            @click="viewMode = 'calendar'">
                        <i class="ti ti-calendar fs-14 text-body"></i>
                    </button>
                </div>

                <button type="button" class="btn btn-primary btn-sm" @click="openCreate">
                    <i class="ti ti-plus me-1"></i>{{ t.btn_new }}
                </button>

            </div>

            <!-- ════════════════════════════════════════════════════════════
                 LINHA 2 — Painéis, busca e seleção
                 Alterna entre modo normal e modo de seleção em massa
                 ════════════════════════════════════════════════════════════ -->
            <div class="d-flex align-items-center gap-2 py-2 border-bottom mb-3 flex-wrap">

                <!-- MODO NORMAL -->
                <template v-if="!selectionMode">

                    <!-- Mural de recados -->
                    <button type="button"
                            class="btn btn-sm btn-outline-primary position-relative"
                            @click="noticesPanelRef?.toggle()">
                        <i class="fas fa-bullhorn me-1"></i>{{ t.notices_title }}
                        <span v-if="noticesUnread > 0"
                              class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                              style="font-size:.6rem;">
                            {{ noticesUnread }}
                        </span>
                    </button>

                    <!-- Lista de espera (somente não-médicos) -->
                    <template v-if="!isDoctor">
                        <button type="button"
                                class="btn btn-sm btn-outline-warning position-relative"
                                @click="wlPanelRef?.toggle()">
                            <i class="fas fa-hourglass-half me-1"></i>{{ t.waiting_title }}
                            <span v-if="wlCount > 0"
                                  class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                                  style="font-size:.6rem;">
                                {{ wlCount }}
                            </span>
                        </button>
                        <button type="button"
                                class="btn btn-sm btn-outline-secondary"
                                :title="t.waiting_add_queue"
                                @click="wlFormOpen = true">
                            <i class="fas fa-user-plus me-1"></i>{{ t.waiting_add_queue }}
                        </button>
                    </template>

                    <!-- Separador -->
                    <div class="vr mx-1 d-none d-sm-block flex-shrink-0"></div>

                    <!-- Busca + Selecionar agrupados à direita -->
                    <div class="d-flex align-items-center gap-2 ms-auto">
                        <div class="input-group input-group-sm" style="min-width:180px;max-width:240px;">
                            <span class="input-group-text"><i class="fas fa-search"></i></span>
                            <input v-model="search"
                                   type="text"
                                   class="form-control"
                                   :placeholder="t.search_placeholder"
                                   @input="onSearchInput">
                            <button v-if="search"
                                    type="button"
                                    class="btn btn-outline-secondary"
                                    @click="search = ''; applyFilters(true)">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <button v-if="isStaff && viewMode === 'list'"
                                type="button"
                                class="btn btn-sm btn-outline-secondary flex-shrink-0"
                                @click="toggleSelectionMode">
                            <i class="fas fa-check-square me-1"></i>{{ t.btn_select }}
                        </button>
                    </div>

                </template>

                <!-- MODO SELEÇÃO EM MASSA -->
                <template v-else>
                    <button type="button"
                            class="btn btn-outline-secondary btn-sm"
                            @click="toggleSelectionMode">
                        <i class="fas fa-times me-1"></i>{{ t.btn_cancel_select }}
                    </button>

                    <span class="badge bg-secondary rounded-pill">
                        {{ selectedIds.length }} {{ t.selected_count }}
                    </span>

                    <div class="vr flex-shrink-0"></div>

                    <button type="button"
                            class="btn btn-info btn-sm"
                            :disabled="selectedIds.length === 0"
                            @click="bulkUpdate(2)">
                        <i class="fas fa-check-circle me-1"></i>{{ t.btn_bulk_confirm }}
                    </button>
                    <button type="button"
                            class="btn btn-warning btn-sm text-dark"
                            :disabled="selectedIds.length === 0"
                            @click="bulkUpdate(8)">
                        <i class="fas fa-user-times me-1"></i>{{ t.btn_bulk_noshow }}
                    </button>
                    <button type="button"
                            class="btn btn-danger btn-sm"
                            :disabled="selectedIds.length === 0"
                            @click="bulkCancelOpen = true">
                        <i class="fas fa-ban me-1"></i>{{ t.btn_bulk_cancel }}
                    </button>
                    <button type="button"
                            class="btn btn-secondary btn-sm"
                            :disabled="selectedIds.length === 0"
                            @click="bulkRescheduleOpen = true">
                        <i class="fas fa-calendar-alt me-1"></i>{{ t.btn_bulk_change_date }}
                    </button>
                </template>

            </div>

            <!-- ════════════════════════════════════════════════════════════
                 Painéis colapsíveis (abrem abaixo da barra, largura total)
                 ════════════════════════════════════════════════════════════ -->
            <NoticesPanel
                ref="noticesPanelRef"
                :t="t"
                @update:unread-count="noticesUnread = $event"
            />

            <WaitingListPanel
                v-if="!isDoctor"
                ref="wlPanelRef"
                :doctor="doctor"
                :t="t"
                @update:count="wlCount = $event"
                @schedule-from-waiting="onScheduleFromWaiting"
            />

            <!-- ════════════════════════════════════════════════════════════
                 Layout principal: sidebar médicos + coluna de agendamentos
                 ════════════════════════════════════════════════════════════ -->
            <div class="row g-3">

                <!-- Sidebar — sempre visível para não-médicos -->
                <div v-if="!isDoctor" class="col-12 col-md-3">
                    <div class="card">
                        <div class="card-body">

                            <!-- Mini calendário mensal -->
                            <MiniCalendar
                                :model-value="date"
                                :locale="sessionLocale"
                                @update:model-value="goToDate"
                            />

                            <!-- Médicos + Turno — só quando há médicos cadastrados -->
                            <template v-if="doctors.length > 0">
                                <hr class="my-3">

                                <!-- Médicos -->
                                <h6 class="fw-bold text-uppercase mb-2">{{ t.sidebar_doctors }}</h6>

                                <!-- Todos -->
                                <div class="d-flex align-items-center mb-3 gap-2"
                                     style="cursor:pointer;"
                                     @click="setDoctor('tudo')">
                                    <div class="rounded d-flex align-items-center justify-content-center flex-shrink-0"
                                         style="width:42px;height:42px;"
                                         :style="{ border: doctor === 'tudo' ? '2px solid #333' : '1px solid #ccc' }">
                                        <i class="fas fa-users text-muted"></i>
                                    </div>
                                    <div>
                                        <div class="fw-bold small">{{ t.sidebar_all }}</div>
                                        <div class="text-muted" style="font-size:.75rem;">{{ t.sidebar_select_all }}</div>
                                    </div>
                                </div>

                                <div v-for="d in doctors"
                                     :key="d.id"
                                     class="d-flex align-items-center mb-3 gap-2"
                                     style="cursor:pointer;"
                                     @click="setDoctor(d.id)">
                                    <div class="rounded d-flex align-items-center justify-content-center flex-shrink-0 overflow-hidden"
                                         style="width:42px;height:42px;"
                                         :style="{ border: doctor === d.id ? `2px solid ${d.color}` : `1px solid ${d.color}` }">
                                        <img v-if="d.photo_url" :src="d.photo_url" :alt="d.name"
                                             style="width:100%;height:100%;object-fit:cover;">
                                        <i v-else class="fas fa-user" :style="{ color: d.color }"></i>
                                    </div>
                                    <div>
                                        <div class="fw-semibold small" :style="{ color: d.color }">{{ d.name }}</div>
                                        <div class="text-muted" style="font-size:.72rem;">{{ d.record }}</div>
                                    </div>
                                </div>

                                <hr class="my-3">

                                <!-- Turno -->
                                <h6 class="fw-bold text-uppercase mb-2">{{ t.sidebar_time }}</h6>
                                <div class="d-flex w-100 border rounded overflow-hidden" role="group">
                                    <button
                                        v-for="(icon, b) in { 1: 'fa-th', 2: 'fa-sun', 3: 'fa-cloud-sun', 4: 'fa-moon' }"
                                        :key="b"
                                        type="button"
                                        class="flex-fill btn btn-sm rounded-0 border-0 py-2 px-1 text-center"
                                        :class="bout == b ? 'btn-dark' : 'btn-light'"
                                        @click="setBout(Number(b))">
                                        <i class="fas d-block" :class="icon" style="font-size:1rem;"></i>
                                        <span class="d-block"
                                              style="font-size:.6rem;font-weight:700;letter-spacing:.05em;margin-top:.2rem;">
                                            {{ { 1: t.sidebar_all, 2: t.sidebar_morning, 3: t.sidebar_afternoon, 4: t.sidebar_evening }[b] }}
                                        </span>
                                    </button>
                                </div>
                            </template>

                        </div>
                    </div>
                </div>

                <!-- Coluna principal de agendamentos -->
                <div :class="!isDoctor ? 'col-12 col-md-9' : 'col-12'">

                    <!-- ── Vista calendário ───────────────────────────────── -->
                    <CalendarView
                        v-if="viewMode === 'calendar'"
                        :schedule-items="scheduleItems"
                        :date="date"
                        :t="t"
                        @event-click="onCalendarEventClick"
                        @slot-click="onCalendarSlotClick"
                    />

                    <!-- ── Vista lista ────────────────────────────────────── -->
                    <template v-else>

                        <!-- Estado vazio -->
                        <div v-if="scheduleItems.length === 0" class="text-center py-5 text-muted">
                            <i class="fas fa-calendar-times fa-3x mb-3 opacity-25"></i>
                            <p>{{ t.empty }}</p>
                            <button type="button" class="btn btn-primary btn-sm" @click="openCreate">
                                <i class="fas fa-plus me-1"></i>{{ t.btn_new }}
                            </button>
                        </div>

                        <!-- Itens -->
                        <template v-else>
                            <template v-for="item in scheduleItems" :key="item.id">
                                <ScheduleCard
                                    v-if="item.type === 'schedule'"
                                    :item="item"
                                    :is-staff="isStaff"
                                    :is-doctor="isDoctor"
                                    :moods="moods"
                                    :selection-mode="selectionMode"
                                    :selected="isSelected(item.id)"
                                    :t="t"
                                    @toggle-select="toggleSelect"
                                    @view="onView"
                                    @edit="openEdit"
                                    @reschedule="openReschedule"
                                    @cancel="openCancel"
                                    @change-situation="onChangeSituation"
                                    @change-mood="onChangeMood"
                                />
                                <EventCard v-else :item="item" :t="t" />
                            </template>
                        </template>

                    </template>

                </div>
            </div>

        </div>

        <!-- ── Modais / Painéis deslizantes ──────────────────────────────────── -->

        <ScheduleFormModal
            :open="formOpen"
            :edit-schedule="editSchedule"
            :prefill-data="prefillData"
            :default-date="date"
            :doctors="doctors"
            :covenants="covenants"
            :visit-types="visitTypes"
            :store-url="storeUrl"
            :t="t"
            @close="formOpen = false; editSchedule = null; prefillData = null"
            @saved="onSaved"
        />

        <RescheduleModal
            :item="rescheduleItem"
            :doctors="doctors"
            :t="t"
            @close="rescheduleItem = null"
            @rescheduled="onRescheduled"
        />

        <CancelModal
            :item="cancelItem"
            :t="t"
            @close="cancelItem = null"
            @cancelled="onCancelled"
        />

        <BulkCancelModal
            :open="bulkCancelOpen"
            :selected-ids="selectedIds"
            :t="t"
            @close="bulkCancelOpen = false"
            @done="onBulkCancelled"
        />

        <BulkRescheduleModal
            :open="bulkRescheduleOpen"
            :selected-ids="selectedIds"
            :t="t"
            @close="bulkRescheduleOpen = false"
            @done="onBulkRescheduled"
        />

        <WaitingListFormModal
            :open="wlFormOpen"
            :doctors="doctors"
            :covenants="covenants"
            :visit-types="visitTypes"
            :t="t"
            @close="wlFormOpen = false"
            @saved="onWlSaved"
        />

        <!-- ── Schedule Detail Drawer ─────────────────────────────────────── -->
        <ScheduleDetailDrawer
            :open="detailOpen"
            :schedule-id="viewScheduleId"
            :t="t"
            @close="closeDetail"
        />

    </AppLayout>
</template>
