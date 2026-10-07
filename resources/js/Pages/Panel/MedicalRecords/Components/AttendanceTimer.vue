<script setup>
import { computed } from 'vue';
import { useAttendanceClock, formatClock, formatDuration } from '@/composables/useAttendanceClock.js';

/**
 * Cronômetro do atendimento no cabeçalho do prontuário.
 *
 *  - Com agendamento: desde a 1ª vez "Em consulta" (o "Atender" — histórico de
 *    situações do agendamento, `flow.started_at`).
 *  - Sem agendamento (ou antes desse registro existir): desde a abertura.
 *  - Atendimento já encerrado (Atendido): mostra a duração final, parado.
 *    Encerrado sem os horários, ou prontuário assinado sem eles: não aparece.
 */
const props = defineProps({
    // scheduleFlow de buildFormProps ({ situation, started_at, finished_at } | null)
    flow: { type: Object, default: null },
    locked: { type: Boolean, default: false },
    t: { type: Object, default: () => ({}) },
});

const TERMINAL = [7, 8, 9];

const terminal = computed(() => Boolean(props.flow) && TERMINAL.includes(Number(props.flow.situation)));
const running = computed(() => !props.locked && !terminal.value);

const clock = useAttendanceClock({
    startedAt: () => props.flow?.started_at ?? null,
    finishedAt: () => props.flow?.finished_at ?? null,
    running: () => running.value,
});

const visible = computed(() => running.value || clock.finished.value);

const tt = (key, fallback) => props.t?.[key] ?? fallback;

const durationText = computed(() =>
    formatDuration(clock.elapsedMs.value, {
        minutes: tt('timer_minutes', ':m min'),
        hours: tt('timer_hours', ':h h :m min'),
        underMinute: tt('timer_under_minute', 'menos de 1 min'),
    }),
);

const text = computed(() =>
    clock.finished.value
        ? tt('timer_done', 'Atendimento: :time').replace(':time', durationText.value)
        : formatClock(clock.elapsedMs.value),
);

const startLabel = computed(() =>
    new Date(clock.originMs.value).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }),
);

const hint = computed(() =>
    clock.fromServer.value
        ? tt('timer_since_start', 'Contando desde o início do atendimento, às :time').replace(':time', startLabel.value)
        : tt('timer_since_open', 'Contando desde a abertura do prontuário'),
);

// Leitor de tela: minutos (o relógio de segundos não é anunciado).
const ariaLabel = computed(() =>
    clock.finished.value
        ? text.value
        : `${tt('timer_label', 'Tempo de atendimento')}: ${durationText.value}. ${hint.value}`,
);
</script>

<template>
    <span
        v-if="visible"
        class="pmr-timer"
        :class="{ 'pmr-timer--running': !clock.finished.value }"
        role="timer"
        aria-live="off"
        :aria-label="ariaLabel"
        :title="hint"
        data-testid="pmr-timer"
    >
        <i class="fas fa-stopwatch pmr-timer__icon" aria-hidden="true"></i>
        <span v-if="!clock.finished.value" class="pmr-timer__label d-none d-sm-inline" aria-hidden="true">{{
            tt('timer_label', 'Tempo de atendimento')
        }}</span>
        <span class="pmr-timer__value" aria-hidden="true">{{ text }}</span>
    </span>
</template>
