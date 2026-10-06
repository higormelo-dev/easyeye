<script setup>
import { computed } from 'vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { parseDate } from './trend.js';

/**
 * Confirmações de HOJE e de AMANHÃ (recepção), lado a lado nas telas largas:
 * confirmadas × sem confirmação, situação do WhatsApp das que faltam, consultas
 * por turno e a lista "ligar para confirmar" com o telefone (link de discagem).
 * Hoje só entram na lista os horários que ainda não passaram.
 */
const props = defineProps({
    // ClinicOperationsService::reception()
    reception: { type: Object, default: null },
    canOpenSchedule: { type: Boolean, default: false },
    t: { type: Object, required: true },
});

const { locale, number } = useLocaleFormat();
const tx = (key, params = {}) =>
    Object.entries(params).reduce((text, [name, value]) => text.replace(`:${name}`, String(value)), props.t[key] ?? '');

const WHATSAPP = [
    { key: 'awaiting', icon: 'ti ti-clock-hour-4' },
    { key: 'queued', icon: 'ti ti-send' },
    { key: 'failed', icon: 'ti ti-alert-triangle' },
    { key: 'none', icon: 'ti ti-message-off' },
];

const SHIFTS = { morning: 'ti ti-sunrise', afternoon: 'ti ti-sun', evening: 'ti ti-moon' };

function dayView(key) {
    const day = props.reception?.days?.[key];
    if (!day) return null;

    const date = parseDate(day.date);
    const pct = day.total > 0 ? Math.round((day.confirmed / day.total) * 100) : 0;

    return {
        key,
        ...day,
        title: props.t[`confirm_${key}`],
        dateLabel: date
            ? new Intl.DateTimeFormat(locale.value, { weekday: 'short', day: 'numeric', month: 'short' }).format(date)
            : '',
        pct,
        chips: WHATSAPP.map((w) => ({ ...w, count: day.whatsapp?.[w.key] ?? 0 })).filter((w) => w.count > 0),
        more: Math.max(0, (day.to_call_total ?? 0) - (day.to_call?.length ?? 0)),
    };
}

const days = computed(() => ['today', 'tomorrow'].map(dayView).filter(Boolean));
</script>

<template>
    <section class="card db-card confirmations" data-tour="dashboard-confirmations" :aria-label="t.confirm_title">
        <div class="db-card-header">
            <h3 class="db-card-title">
                <i class="ti ti-phone-check" aria-hidden="true"></i>
                {{ t.confirm_title }}
            </h3>
            <span class="db-card-meta">{{ t.confirm_subtitle }}</span>
        </div>

        <div class="confirm-days">
            <div v-for="day in days" :key="day.key" class="confirm-day" :data-day="day.key">
                <div class="confirm-day__head">
                    <div>
                        <h4 class="confirm-day__title">{{ day.title }}</h4>
                        <span class="confirm-day__date">{{ day.dateLabel }}</span>
                    </div>
                    <a v-if="canOpenSchedule && day.url" :href="day.url" class="db-link">
                        {{ t.confirm_open_schedule }} <i class="ti ti-arrow-right" aria-hidden="true"></i>
                    </a>
                </div>

                <div v-if="day.total === 0" class="db-empty db-empty--inline">
                    <i class="ti ti-calendar-off" aria-hidden="true"></i>
                    <span>{{ t.confirm_no_schedules }}</span>
                </div>

                <template v-else>
                    <div class="confirm-meter">
                        <div class="confirm-meter__numbers">
                            <strong data-confirm="confirmed">{{ number(day.confirmed) }}</strong>
                            <span>{{ tx('confirm_of_total', { total: number(day.total) }) }}</span>
                            <span class="confirm-meter__pct">{{ number(day.pct) }}%</span>
                        </div>
                        <div
                            class="progress confirm-meter__bar"
                            role="img"
                            :aria-label="tx('confirm_meter_aria', { confirmed: number(day.confirmed), total: number(day.total) })"
                        >
                            <div class="progress-bar" :style="{ width: `${day.pct}%` }"></div>
                        </div>
                        <div class="confirm-meter__legend">
                            <span v-if="day.unconfirmed > 0" class="text-warning-emphasis" data-confirm="unconfirmed">
                                {{ tx('confirm_unconfirmed', { count: number(day.unconfirmed) }) }}
                            </span>
                            <span v-else class="text-success-emphasis">{{ t.confirm_all_done }}</span>
                            <span v-if="day.whatsapp_confirmed > 0">
                                · {{ tx('confirm_by_whatsapp', { count: number(day.whatsapp_confirmed) }) }}
                            </span>
                        </div>
                    </div>

                    <div v-if="day.shifts?.length" class="confirm-shifts" :aria-label="t.shifts_label">
                        <span v-for="shift in day.shifts" :key="shift.key" class="confirm-shift" :data-shift="shift.key">
                            <i :class="SHIFTS[shift.key]" aria-hidden="true"></i>
                            {{ t[`shift_${shift.key}`] }}
                            <strong>{{ number(shift.total) }}</strong>{{ ' ' }}<span class="confirm-shift__conf">{{ tx('confirm_shift_confirmed', { count: number(shift.confirmed) }) }}</span>
                        </span>
                    </div>

                    <div v-if="day.chips.length" class="confirm-wa" :aria-label="t.confirm_whatsapp_label">
                        <span class="confirm-wa__label"><i class="ti ti-brand-whatsapp" aria-hidden="true"></i></span>
                        <span v-for="chip in day.chips" :key="chip.key" class="db-chip" :class="`db-chip--${chip.key}`" :data-wa="chip.key">
                            <i :class="chip.icon" aria-hidden="true"></i>
                            {{ tx(`confirm_wa_${chip.key}`, { count: number(chip.count) }) }}
                        </span>
                    </div>

                    <div v-if="day.to_call?.length" class="confirm-call">
                        <div class="ds-section-title">{{ t.confirm_call_title }}</div>
                        <ul class="db-list db-list--dense">
                            <li v-for="item in day.to_call" :key="item.id" class="db-list__item">
                                <span class="confirm-call__time">{{ item.time }}</span>
                                <div class="db-list__main">
                                    <span class="db-list__title">{{ item.name }}</span>
                                    <span class="db-list__sub">
                                        {{ item.doctor }}
                                        <template v-if="item.whatsapp">· {{ t[`confirm_wa_state_${item.whatsapp}`] }}</template>
                                    </span>
                                </div>
                                <a
                                    v-if="item.phone_href"
                                    :href="item.phone_href"
                                    class="btn btn-sm db-btn-soft confirm-call__phone"
                                    :aria-label="tx('confirm_call_aria', { name: item.name, phone: item.phone })"
                                >
                                    <i class="ti ti-phone" aria-hidden="true"></i>
                                    <span>{{ item.phone }}</span>
                                </a>
                                <span v-else class="text-muted small">{{ t.confirm_no_phone }}</span>
                            </li>
                        </ul>
                        <p v-if="day.more > 0" class="db-card-note db-card-note--inline" role="note">
                            {{ tx('list_more', { count: number(day.more) }) }}
                        </p>
                    </div>
                </template>
            </div>
        </div>

        <p v-if="reception?.truncated" class="db-card-note" role="note">{{ t.confirm_truncated }}</p>
    </section>
</template>
