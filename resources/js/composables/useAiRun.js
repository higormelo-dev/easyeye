import { ref } from 'vue';

/**
 * Ciclo de vida reutilizável de uma execução de IA (estimate → store → poll →
 * approve/reject), alinhado ao AiRunsController. Compartilhado entre o prontuário,
 * o EyeImages e o painel de IA.
 *
 * @param {object} ai  Bloco de props de IA da página: { urls, balance, ... }
 */
export function useAiRun(ai) {
    const estimating = ref(false);
    const running    = ref(false);
    const polling    = ref(false);
    const error      = ref('');
    const estimate   = ref(null);              // { normalized_credits, minimum_applied, ... }
    const balance    = ref(ai?.balance ?? null);
    const runId      = ref(null);
    const status     = ref(null);
    const output     = ref('');                // final_output
    const safety     = ref([]);                // safety_notes

    const urls       = () => ai?.urls ?? {};
    const showUrl    = (id) => (urls().show ?? '').replace('__ID__', id);
    const approveUrl = (id) => (urls().approve ?? '').replace('__ID__', id);
    const rejectUrl  = (id) => (urls().reject ?? '').replace('__ID__', id);
    const sleep      = (ms) => new Promise((r) => setTimeout(r, ms));

    function reset() {
        error.value    = '';
        runId.value    = null;
        status.value   = null;
        output.value   = '';
        safety.value   = [];
        estimate.value = null;
    }

    /** Prévia de custo (não reserva créditos). */
    async function getEstimate(payload) {
        estimating.value = true;
        error.value      = '';
        try {
            const { data } = await window.axios.post(urls().estimate, payload);
            estimate.value = data.estimate;
            balance.value  = data.balance;
            return data;
        } catch (e) {
            error.value = e?.response?.data?.message ?? 'Falha ao estimar o custo.';
            throw e;
        } finally {
            estimating.value = false;
        }
    }

    /** Faz polling do run até um estado terminal (waiting_approval/approved/failed/...). */
    async function poll(id, { interval = 2500, tries = 48 } = {}) {
        polling.value = true;
        try {
            for (let i = 0; i < tries; i++) {
                const { data } = await window.axios.get(showUrl(id));
                const run      = data.data ?? data;
                status.value   = run.status;

                if (['waiting_approval', 'approved'].includes(run.status)) {
                    output.value = run.final_output ?? '';
                    safety.value = run.safety_notes ?? [];
                    return run;
                }
                if (['failed', 'rejected', 'cancelled'].includes(run.status)) {
                    error.value = run.error_message ?? 'Execução não concluída.';
                    return run;
                }
                await sleep(interval);
            }
            error.value = 'Tempo de processamento excedido. Veja em /painel/ia.';
            return null;
        } finally {
            polling.value = false;
        }
    }

    /** estimate (servidor) → store → poll. Retorna o run em waiting_approval ou null. */
    async function run(payload, pollOpts) {
        reset();
        running.value = true;
        try {
            const { data } = await window.axios.post(urls().store, payload);
            runId.value   = data.run_id;
            status.value  = data.status;
            running.value = false;
            return await poll(data.run_id, pollOpts);
        } catch (e) {
            running.value = false;
            const d       = e?.response?.data;
            error.value   = d?.message ?? 'Falha ao executar a IA.';
            if (d?.details) {
                error.value += ` (${d.details.requested}/${d.details.available} créditos)`;
            }
            throw e;
        }
    }

    /** Aprova o run — para laudos, isto persiste a documentação (ai_run_id). */
    async function approve(id, finalOutput = null) {
        const body     = finalOutput !== null ? { final_output: finalOutput } : {};
        const { data } = await window.axios.post(approveUrl(id), body);
        status.value   = 'approved';
        return data;
    }

    async function reject(id, reason = null) {
        const { data } = await window.axios.post(rejectUrl(id), reason ? { reason } : {});
        status.value   = 'rejected';
        return data;
    }

    return {
        estimating, running, polling, error,
        estimate, balance, runId, status, output, safety,
        getEstimate, run, poll, approve, reject, reset,
    };
}
