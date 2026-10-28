import axios from 'axios';
import { onBeforeUnmount } from 'vue';

const BACKOFF_MS = [1500, 2000, 3000, 4500, 6000, 8000];
const FINAL_STATUSES = ['approved', 'rejected', 'failed', 'cancelled'];

/**
 * Dispara uma execução de IA do painel financeiro (análise ou chat) e
 * acompanha até o fim, consultando o run com espera crescente. Para sozinho
 * quando o componente sai da tela (sem requisições "fantasma").
 *
 * start() devolve o run final ({status: 'approved', ...}), {status: 'timeout'}
 * ou {status: 'stopped'}; erro HTTP (validação, limite, sem permissão) sobe
 * como exceção do axios.
 *
 * @param {{digest: string, chat: string, show: string}} urls
 */
export function useFinanceAiRun(urls, timeoutMs = 120000) {
    let active = true;
    const timers = new Set();

    onBeforeUnmount(() => {
        active = false;
        timers.forEach(clearTimeout);
        timers.clear();
    });

    function wait(ms) {
        return new Promise((resolve) => {
            const id = setTimeout(() => {
                timers.delete(id);
                resolve();
            }, ms);
            timers.add(id);
        });
    }

    async function start(key, payload) {
        const { data } = await axios.post(urls[key], payload);
        const deadline = Date.now() + timeoutMs;

        for (let attempt = 0; active; attempt += 1) {
            const { data: run } = await axios.get(urls.show.replace('__ID__', data.run_id));

            if (FINAL_STATUSES.includes(run.status)) return run;
            if (Date.now() > deadline) return { status: 'timeout' };

            await wait(BACKOFF_MS[Math.min(attempt, BACKOFF_MS.length - 1)]);
        }

        return { status: 'stopped' };
    }

    return { start };
}
