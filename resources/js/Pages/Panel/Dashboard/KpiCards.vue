<script setup>
import { computed } from 'vue';
import KpiStatCard from './KpiStatCard.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { formatRange, parseDate } from './trend.js';

const props = defineProps({
    stats: { type: Object, required: true },
    // Perfil do usuário na clínica (PanelDashboardController: admin, secretary,
    // financial, user, doctor) — cada um vê os indicadores do posto de
    // trabalho dele; o servidor só envia os números desses indicadores.
    profile: { type: String, default: 'admin' },
    // Laudos de IA aguardando aprovação do médico (null sem IA na clínica).
    aiWaiting: { type: Object, default: null },
    // Recepção (confirmações, sala de espera) e lista de espera — secretária.
    reception: { type: Object, default: null },
    waitlist: { type: Object, default: null },
    // Números de gestão (mês × mesmo período do mês anterior) — fora do polling.
    insights: { type: Object, default: null },
    // Telas que o usuário pode abrir (PanelDashboardController::buildAccess —
    // mesmas regras das rotas): sem acesso, o card não vira link para um 403.
    access: { type: Object, default: () => ({}) },
    isRefreshing: { type: Boolean, default: false },
    // "Atualizar" pedido: os números de gestão estão sendo recalculados.
    loadingInsights: { type: Boolean, default: false },
    t: { type: Object, required: true },
});

const { locale, money, number } = useLocaleFormat();
const tx = (key, params = {}) =>
    Object.entries(params).reduce((text, [name, value]) => text.replace(`:${name}`, String(value)), props.t[key] ?? '');

// ── Período de comparação (mês atual até hoje × mesmo intervalo do anterior) ──
const period = computed(() => props.insights?.period ?? null);
const compareLabel = computed(() => formatRange(period.value?.previous, locale.value));
const vsCaption = computed(() => (compareLabel.value ? tx('kpi_vs', { period: compareLabel.value }) : ''));
const monthName = computed(() => {
    const from = parseDate(period.value?.current?.from);

    return from ? new Intl.DateTimeFormat(locale.value, { month: 'long', year: 'numeric' }).format(from) : '';
});

const month = computed(() => props.insights?.month ?? null);
const cur = computed(() => month.value?.current ?? {});
const prev = computed(() => month.value?.previous ?? {});

/** Indicador do mês com variação vs. o mesmo intervalo do mês anterior. */
const monthly = (key, field, label, { format = 'number', kind = 'count', better = 'up', icon, hint, url, tone } = {}) => ({
    key,
    label,
    value: cur.value[field] ?? null,
    format,
    icon,
    tone,
    hint,
    url: url ?? null,
    caption: vsCaption.value,
    compareLabel: compareLabel.value,
    delta: month.value ? { previous: prev.value[field] ?? null, kind, better } : null,
    management: true,
});

// Médico sem cadastro de médico (sem doctor_id): a Agenda não sabe quem ele
// é — o Dashboard não manda ele para lá.
const scheduleUrl = () =>
    props.access.schedules && (props.profile !== 'doctor' || props.stats.doctor_id)
        ? route('panel.schedules.index')
        : null;
const biUrl = () => (props.access.financial ? route('panel.financial.bi.index') : null);

const todayCaption = () => {
    const total = Number(props.stats.today_count ?? 0);
    const missed = Number(props.stats.cancelled_today ?? 0);

    return tx('kpi_today_progress', { attended: number(props.stats.attended_today ?? 0), expected: number(total - missed) });
};

const examsCard = (mine) => ({
    key: mine ? 'my_exams' : 'exams',
    icon: 'ti ti-eye',
    tone: 'orange',
    value: props.stats.exams_pending ?? 0,
    label: mine ? props.t.kpi_my_exams_pending : props.t.kpi_exams_pending,
    caption: props.t.kpi_exams_pending_hint,
    // O link abre o Gerenciador de Imagens já com o mesmo filtro, para o
    // número bater com a lista (o do médico, filtrado por ele).
    url: !props.access.eye_images
        ? null
        : mine
          ? props.stats.doctor_id
              ? route('panel.eye-images.index', { status: 'pendente', period: '30', doctor_id: props.stats.doctor_id })
              : null
          : route('panel.eye-images.index', { status: 'pendente', period: '30' }),
});

// ── Indicadores por perfil ──────────────────────────────────────────────────
const BUILDERS = {
    doctor: () => [
        {
            key: 'my_today',
            icon: 'ti ti-calendar-check',
            tone: 'primary',
            value: props.stats.today_count ?? 0,
            label: props.t.kpi_my_today,
            caption: todayCaption(),
            url: scheduleUrl(),
        },
        {
            key: 'my_waiting',
            icon: 'ti ti-armchair',
            tone: 'warning',
            value: props.stats.waiting_now ?? 0,
            label: props.t.kpi_my_waiting,
            caption: props.t.kpi_my_waiting_hint,
            url: scheduleUrl(),
            alert: Number(props.stats.waiting_now ?? 0) > 0,
        },
        monthly('my_month', 'attended', props.t.kpi_my_month_attended, { icon: 'ti ti-user-check', tone: 'success' }),
        monthly('my_noshow', 'noshow_rate', props.t.kpi_my_noshow_rate, {
            format: 'pct',
            kind: 'pct',
            better: 'down',
            icon: 'ti ti-user-x',
            tone: 'danger',
            hint: props.t.kpi_noshow_rate_hint,
        }),
        examsCard(true),
        ...(props.aiWaiting
            ? [
                  {
                      key: 'ai_waiting',
                      icon: 'ti ti-sparkles',
                      tone: 'teal',
                      value: props.aiWaiting.count ?? 0,
                      label: props.t.kpi_ai_waiting,
                      caption: props.t.kpi_ai_waiting_hint,
                      url: props.aiWaiting.list_url ?? null,
                  },
              ]
            : []),
    ],

    secretary: () => {
        const today = props.reception?.days?.today ?? null;
        const tomorrow = props.reception?.days?.tomorrow ?? null;
        const longest = props.reception?.waiting_room?.longest ?? null;
        const confirmedPct = today && today.total > 0 ? Math.round((today.confirmed / today.total) * 100) : null;

        return [
            {
                key: 'today',
                icon: 'ti ti-calendar-check',
                tone: 'primary',
                value: props.stats.today_count ?? 0,
                label: props.t.kpi_today,
                caption: todayCaption(),
                url: scheduleUrl(),
            },
            {
                key: 'waiting',
                icon: 'ti ti-armchair',
                tone: 'warning',
                value: props.stats.waiting_now ?? 0,
                label: props.t.kpi_waiting_now,
                caption: longest ? tx('kpi_longest_wait', { minutes: number(longest) }) : props.t.kpi_waiting_now_hint,
                url: scheduleUrl(),
                alert: Number(longest ?? 0) >= 30,
            },
            {
                key: 'confirmed_today',
                icon: 'ti ti-circle-check',
                tone: 'success',
                value: confirmedPct,
                format: 'pct',
                label: props.t.kpi_confirmed_today,
                caption: today
                    ? tx('kpi_confirmed_of', { confirmed: number(today.confirmed), total: number(today.total) })
                    : '',
            },
            {
                key: 'tomorrow',
                icon: 'ti ti-calendar-time',
                tone: 'indigo',
                value: tomorrow?.total ?? null,
                label: props.t.kpi_tomorrow,
                caption: !tomorrow
                    ? ''
                    : tomorrow.unconfirmed > 0
                      ? tx('kpi_unconfirmed', { count: number(tomorrow.unconfirmed) })
                      : props.t.kpi_all_confirmed,
                alert: (tomorrow?.unconfirmed ?? 0) > 0,
                url: tomorrow?.url && props.access.schedules ? tomorrow.url : null,
            },
            {
                key: 'waitlist',
                icon: 'ti ti-list-numbers',
                tone: 'purple',
                value: props.waitlist?.count ?? null,
                label: props.t.kpi_waitlist,
                caption: props.t.kpi_waitlist_hint,
            },
            examsCard(false),
        ];
    },

    admin: () => {
        const finance = !!props.insights?.finance;
        const receivables = props.insights?.receivables ?? null;

        return [
            monthly('occupancy', 'occupancy_rate', props.t.kpi_occupancy, {
                format: 'pct',
                kind: 'pct',
                icon: 'ti ti-calendar-stats',
                tone: 'primary',
                hint: props.t.kpi_occupancy_hint,
            }),
            monthly('attendance', 'attendance_rate', props.t.kpi_attendance, {
                format: 'pct',
                kind: 'pct',
                icon: 'ti ti-user-check',
                tone: 'success',
                hint: props.t.kpi_attendance_hint,
            }),
            monthly('noshow', 'noshow_rate', props.t.kpi_noshow_rate, {
                format: 'pct',
                kind: 'pct',
                better: 'down',
                icon: 'ti ti-user-x',
                tone: 'danger',
                hint: props.t.kpi_noshow_rate_hint,
            }),
            monthly('new_patients', 'new_patients', props.t.kpi_new_patients, {
                icon: 'ti ti-user-plus',
                tone: 'indigo',
                url: props.access.patients ? route('panel.patients.index') : null,
            }),
            ...(finance
                ? [
                      monthly('income', 'income', props.t.kpi_income, {
                          format: 'money',
                          icon: 'ti ti-cash',
                          tone: 'teal',
                          url: biUrl(),
                      }),
                      {
                          key: 'receivable',
                          icon: 'ti ti-receipt',
                          tone: 'orange',
                          value: receivables?.total ?? null,
                          format: 'money',
                          label: props.t.kpi_receivable,
                          caption:
                              (receivables?.overdue ?? 0) > 0
                                  ? tx('kpi_overdue', { amount: money(receivables.overdue) })
                                  : props.t.kpi_nothing_overdue,
                          alert: (receivables?.overdue ?? 0) > 0,
                          hint: props.t.kpi_receivable_hint,
                          url: receivables?.cash?.url ?? null,
                          management: true,
                      },
                  ]
                : [
                      monthly('attended', 'attended', props.t.kpi_attended_month, {
                          icon: 'ti ti-stethoscope',
                          tone: 'success',
                      }),
                      examsCard(false),
                  ]),
        ];
    },

    financial: () => [
        monthly('income', 'income', props.t.kpi_income, { format: 'money', icon: 'ti ti-cash', tone: 'teal', url: biUrl() }),
        monthly('billed', 'billed', props.t.kpi_billed, {
            format: 'money',
            icon: 'ti ti-file-invoice',
            tone: 'primary',
            hint: props.t.kpi_billed_hint,
        }),
        monthly('paid', 'paid', props.t.kpi_paid, { format: 'money', icon: 'ti ti-building-bank', tone: 'success' }),
        monthly('glosa', 'glosa', props.t.kpi_glosa, {
            format: 'money',
            better: 'down',
            icon: 'ti ti-file-x',
            tone: 'danger',
            hint: props.t.kpi_glosa_hint,
        }),
        monthly('ticket', 'ticket', props.t.kpi_ticket, {
            format: 'money',
            icon: 'ti ti-receipt-2',
            tone: 'indigo',
            hint: props.t.kpi_ticket_hint,
        }),
        monthly('receipt_rate', 'receipt_rate', props.t.kpi_receipt_rate, {
            format: 'pct',
            kind: 'pct',
            icon: 'ti ti-percentage',
            tone: 'purple',
            hint: props.t.kpi_receipt_rate_hint,
        }),
    ],

    user: () => [
        {
            key: 'patients',
            icon: 'ti ti-users',
            tone: 'primary',
            value: props.stats.total_patients ?? 0,
            label: props.t.kpi_patients,
            caption: props.t.kpi_patients_hint,
            url: props.access.patients ? route('panel.patients.index') : null,
        },
        {
            key: 'today',
            icon: 'ti ti-calendar-check',
            tone: 'indigo',
            value: props.stats.today_count ?? 0,
            label: props.t.kpi_today,
            caption: todayCaption(),
            url: scheduleUrl(),
        },
        examsCard(false),
    ],
};

const cards = computed(() => (BUILDERS[props.profile] ?? BUILDERS.user)());

// Gestão (administração/financeiro): a faixa é do MÊS — título e período explícitos.
const isMonthStrip = computed(() => ['admin', 'financial'].includes(props.profile));

// Os números de gestão chegam junto com a página (sem eles — médico sem
// cadastro —, o card mostra "—"); esqueleto só enquanto "Atualizar" recalcula.
const loadingCard = (card) => !!card.management && props.loadingInsights;
</script>

<template>
    <section class="db-section" :aria-label="isMonthStrip ? t.kpis_month_title : t.section_kpis">
        <header v-if="isMonthStrip" class="db-section-head">
            <h2 class="db-section-title">
                {{ t.kpis_month_title }}
                <span v-if="monthName" class="db-section-title__muted">· {{ monthName }}</span>
            </h2>
            <p v-if="compareLabel" class="db-section-sub" data-test="kpi-period">
                {{ tx('kpis_month_compare', { period: compareLabel }) }}
            </p>
        </header>

        <!-- data-tour: âncoras do tour guiado (literais — PanelTourTest confere) -->
        <div class="db-kpi-grid" :style="{ '--db-kpi-cols': cards.length }" data-tour="dashboard-kpis">
            <KpiStatCard
                v-for="kpi in cards"
                :key="kpi.key"
                :kpi="kpi"
                :is-refreshing="isRefreshing"
                :loading="loadingCard(kpi)"
                :t="t"
            />
        </div>
    </section>
</template>
