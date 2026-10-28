import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Relógio do atendimento no prontuário (cronômetro do cabeçalho e duração no
 * "Finalizar consulta").
 *
 * Início: a 1ª vez que o agendamento ficou "Em consulta" (histórico de
 * situações, `startedAt` do servidor — o "Atender"); sem agendamento (ou sem
 * esse registro ainda), a abertura da tela. Com `finishedAt` (já atendido) o
 * relógio fica parado na duração final. Um intervalo só, limpo ao sair.
 *
 * @param {{ startedAt: () => (string|null), finishedAt: () => (string|null), running: () => boolean }} options
 */
export function useAttendanceClock({ startedAt, finishedAt, running }) {
    const openedAt = Date.now();
    const now = ref(openedAt);
    let timer = null;

    const parse = (value) => {
        const ms = Date.parse(value ?? '');
        return Number.isNaN(ms) ? null : ms;
    };

    const fromServer = computed(() => parse(startedAt()) !== null);
    const originMs = computed(() => parse(startedAt()) ?? openedAt);
    const endMs = computed(() => parse(finishedAt()));
    const finished = computed(() => endMs.value !== null && fromServer.value);
    const elapsedMs = computed(() => Math.max(0, (finished.value ? endMs.value : now.value) - originMs.value));

    onMounted(() => {
        if (finished.value || !running()) return;
        timer = setInterval(() => {
            now.value = Date.now();
        }, 1000);
    });

    onBeforeUnmount(() => {
        clearInterval(timer);
        timer = null;
    });

    /** Atualiza o "agora" (relógio parado, ex.: duração no modal de finalizar). */
    function refresh() {
        now.value = Date.now();
    }

    return { elapsedMs, originMs, fromServer, finished, refresh };
}

/** "07:05" (min:seg) ou "1:07:05" (h:min:seg). */
export function formatClock(ms) {
    const total = Math.max(0, Math.floor(ms / 1000));
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = total % 60;
    const pad = (n) => String(n).padStart(2, '0');

    return h > 0 ? `${h}:${pad(m)}:${pad(s)}` : `${pad(m)}:${pad(s)}`;
}

/** "23 min" / "1 h 05 min" / "menos de 1 min" — duração curta (leitor de tela, modal). */
export function formatDuration(ms, { minutes = ':m min', hours = ':h h :m min', underMinute = 'menos de 1 min' } = {}) {
    const totalMinutes = Math.max(0, Math.floor(ms / 60_000));
    if (totalMinutes === 0) return underMinute;
    if (totalMinutes < 60) return minutes.replace(':m', String(totalMinutes));

    return hours
        .replace(':h', String(Math.floor(totalMinutes / 60)))
        .replace(':m', String(totalMinutes % 60).padStart(2, '0'));
}
