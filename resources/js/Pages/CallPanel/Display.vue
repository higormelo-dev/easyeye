<script setup>
import { ref, onMounted, onUnmounted } from 'vue';

/**
 * TV da sala de espera — painel público de chamadas da clínica.
 *
 * Sem login (URL por token aleatório da clínica). Faz polling do feed a cada
 * 4s; chamada nova entra em destaque e é anunciada por voz (speechSynthesis,
 * pt-BR) quando o navegador da TV suporta. Recebe apenas snapshots de nome —
 * nenhum dado clínico/cadastral chega aqui.
 *
 * Clínica com o acesso bloqueado: "serviço indisponível" (o feed não traz
 * chamadas); a TV segue consultando e volta sozinha quando o acesso volta.
 */
const props = defineProps({
    clinic: { type: String, required: true },
    feed_url: { type: String, required: true },
    unavailable: { type: Boolean, default: false },
    texts: { type: Object, default: () => ({}) },
});

const tx = (key, fallback, replace = {}) =>
    Object.entries(replace).reduce((text, [k, v]) => text.replace(`:${k}`, v ?? ''), props.texts?.[key] ?? fallback);

const blocked = ref(props.unavailable);
const calls = ref([]);
const current = ref(null);
const clock = ref('');

let pollTimer = null;
let clockTimer = null;
let seenIds = new Set();
let firstLoad = true;

function speak(call) {
    try {
        if (!window.speechSynthesis) return;
        const phrase = call.doctor
            ? tx('speech_with_doctor', 'Paciente :patient. Dirigir-se ao consultório. :doctor.', {
                  patient: call.patient,
                  doctor: call.doctor,
              })
            : tx('speech', 'Paciente :patient. Dirigir-se ao consultório.', { patient: call.patient });
        const utter = new SpeechSynthesisUtterance(phrase);
        utter.lang = tx('speech_lang', 'pt-BR');
        utter.rate = 0.92;
        window.speechSynthesis.speak(utter);
    } catch {
        /**/
    }
}

async function poll() {
    try {
        const res = await fetch(props.feed_url, { headers: { Accept: 'application/json' } });
        if (!res.ok) return;
        const { data, unavailable } = await res.json();
        blocked.value = !!unavailable;
        if (blocked.value) {
            // Nada de paciente na tela enquanto o serviço está indisponível.
            calls.value = [];
            current.value = null;

            return;
        }
        calls.value = data ?? [];

        const newest = data[0] ?? null;
        if (newest && !seenIds.has(newest.id)) {
            data.forEach((c) => seenIds.add(c.id));
            current.value = newest;
            // Primeira carga não anuncia (senão a TV "repete" chamadas velhas
            // toda vez que a página recarrega).
            if (!firstLoad) speak(newest);
        }
        firstLoad = false;
    } catch {
        /**/
    }
}

function tickClock() {
    clock.value = new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
}

onMounted(() => {
    tickClock();
    poll();
    pollTimer = setInterval(poll, 4000);
    clockTimer = setInterval(tickClock, 1000);
});

onUnmounted(() => {
    clearInterval(pollTimer);
    clearInterval(clockTimer);
});
</script>

<template>
    <div class="cp-screen">
        <header class="cp-header">
            <span class="cp-clinic">{{ clinic }}</span>
            <span class="cp-clock">{{ clock }}</span>
        </header>

        <main class="cp-main">
            <div v-if="blocked" class="cp-unavailable" role="status" data-test="call-panel-unavailable">
                <div class="cp-unavailable-title">{{ tx('unavailable_title', 'Serviço indisponível no momento') }}</div>
                <div class="cp-unavailable-body">{{ tx('unavailable_body', '') }}</div>
            </div>
            <template v-else-if="current">
                <div class="cp-label">{{ tx('calling', 'Chamando') }}</div>
                <div class="cp-patient">{{ current.patient }}</div>
                <div v-if="current.doctor" class="cp-doctor">{{ current.doctor }}</div>
            </template>
            <div v-else class="cp-idle">{{ tx('idle', 'Aguardando chamadas…') }}</div>
        </main>

        <footer v-if="!blocked && calls.length > 1" class="cp-history">
            <div class="cp-history-title">{{ tx('history', 'Últimas chamadas') }}</div>
            <div class="cp-history-list">
                <div v-for="c in calls.slice(1, 5)" :key="c.id" class="cp-history-item">
                    <span class="cp-history-time">{{ c.called_at }}</span>
                    <span class="cp-history-name">{{ c.patient }}</span>
                    <span v-if="c.doctor" class="cp-history-doctor">{{ c.doctor }}</span>
                </div>
            </div>
        </footer>
    </div>
</template>

<style scoped>
.cp-unavailable {
    max-width: 70vw;
}
.cp-unavailable-title {
    font-size: 4vw;
    font-weight: 700;
    line-height: 1.15;
}
.cp-unavailable-body {
    margin-top: 2vh;
    font-size: 2vw;
    opacity: 0.8;
}
.cp-screen {
    min-height: 100vh;
    background: #0b1524;
    color: #fff;
    display: flex;
    flex-direction: column;
    font-family:
        system-ui,
        -apple-system,
        'Segoe UI',
        sans-serif;
}
.cp-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 2vh 3vw;
    border-bottom: 1px solid rgba(255, 255, 255, 0.12);
}
.cp-clinic {
    font-size: 2.2vw;
    font-weight: 700;
    letter-spacing: 0.02em;
}
.cp-clock {
    font-size: 2.2vw;
    font-variant-numeric: tabular-nums;
    opacity: 0.85;
}
.cp-main {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 0 4vw;
}
.cp-label {
    font-size: 2.4vw;
    text-transform: uppercase;
    letter-spacing: 0.35em;
    color: #4fc3f7;
    margin-bottom: 2vh;
}
.cp-patient {
    font-size: 7vw;
    font-weight: 800;
    line-height: 1.1;
    text-wrap: balance;
}
.cp-doctor {
    font-size: 3vw;
    margin-top: 2vh;
    opacity: 0.8;
}
.cp-idle {
    font-size: 3vw;
    opacity: 0.5;
}
.cp-history {
    padding: 2vh 3vw 3vh;
    border-top: 1px solid rgba(255, 255, 255, 0.12);
}
.cp-history-title {
    font-size: 1.4vw;
    text-transform: uppercase;
    letter-spacing: 0.2em;
    opacity: 0.6;
    margin-bottom: 1vh;
}
.cp-history-list {
    display: flex;
    gap: 3vw;
    flex-wrap: wrap;
}
.cp-history-item {
    display: flex;
    gap: 0.8vw;
    align-items: baseline;
}
.cp-history-time {
    font-size: 1.6vw;
    color: #4fc3f7;
    font-variant-numeric: tabular-nums;
}
.cp-history-name {
    font-size: 1.8vw;
    font-weight: 600;
}
.cp-history-doctor {
    font-size: 1.4vw;
    opacity: 0.65;
}
</style>
