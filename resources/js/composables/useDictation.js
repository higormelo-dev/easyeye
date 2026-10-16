import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { browserDictationDeps, dictationSupported } from '@/Support/dictation.js';

/**
 * Ditado por voz (Fase 5): grava, converte para WAV e manda ao servidor, que
 * transcreve no provedor aprovado pela política LGPD e devolve o texto.
 *
 * Estados: idle → starting → recording → sending → idle. Cancelar (ou fechar a tela)
 * descarta tudo; uma resposta que chega depois de cancelar é ignorada — o
 * texto só entra se o usuário ainda estava esperando por ele.
 *
 * @param {object} options
 * @param {string}   options.url          POST do ditado (multipart, campo `audio`)
 * @param {number}   options.maxSeconds   para sozinho ao chegar aqui
 * @param {(text:string)=>void}   options.onText
 * @param {(message:string)=>void} options.onError
 * @param {(key:string, fallback:string)=>string} options.tt  tradução
 * @param {object} [deps]  peças do navegador (testes trocam)
 */
export function useDictation({ url, maxSeconds = 120, onText, onError, tt }, deps = browserDictationDeps) {
    const status = ref('idle'); // idle | starting | recording | sending
    const elapsed = ref(0);
    // Só se sabe no navegador: no SSR (Node) não há window/navigator, e decidir
    // aqui no setup faria o servidor e o cliente renderizarem coisas diferentes.
    const supported = ref(false);
    onMounted(() => {
        supported.value = dictationSupported();
    });

    let session = 0;
    let active = null;

    const recording = computed(() => status.value === 'recording');
    const sending = computed(() => status.value === 'sending');
    const busy = computed(() => status.value !== 'idle');

    function fail(message) {
        onError?.(message);
    }

    function releaseHardware(run) {
        clearInterval(run?.timer);
        if (run) run.timer = null;
        run?.stream?.getTracks().forEach((track) => track.stop());
        if (run) run.stream = null;
    }

    function reset(run = active) {
        releaseHardware(run);
        if (active === run) active = null;
        elapsed.value = 0;
        status.value = 'idle';
    }

    async function start() {
        if (status.value !== 'idle') return;

        const mine = ++session;
        status.value = 'starting';
        elapsed.value = 0;
        let stream;

        try {
            stream = await deps.getStream();
        } catch (e) {
            // Permissão negada ou sem microfone: não há gravação a limpar.
            if (mine === session) {
                status.value = 'idle';
                fail(
                    e?.name === 'NotAllowedError' || e?.name === 'SecurityError'
                        ? tt('dictation_mic_denied', 'Sem acesso ao microfone.')
                        : tt('dictation_failed', 'Não foi possível gravar o ditado.'),
                );
            }
            return;
        }

        // Cancelou (ou fechou a tela) enquanto o navegador pedia a permissão.
        if (mine !== session) {
            stream?.getTracks().forEach((track) => track.stop());
            return;
        }

        const run = { id: mine, stream, recorder: null, chunks: [], timer: null, startedAt: 0 };
        active = run;
        let recorder;
        try {
            recorder = deps.createRecorder(stream);
        } catch {
            if (mine === session && active === run) {
                reset(run);
                fail(tt('dictation_failed', 'Não foi possível gravar o ditado.'));
            } else {
                releaseHardware(run);
            }
            return;
        }
        run.recorder = recorder;
        recorder.ondataavailable = (event) => {
            if (event.data && event.data.size > 0) run.chunks.push(event.data);
        };
        recorder.onstop = () => handleStopped(run);

        try {
            recorder.start();
        } catch {
            if (mine === session && active === run) {
                reset(run);
                fail(tt('dictation_failed', 'Não foi possível gravar o ditado.'));
            } else {
                releaseHardware(run);
            }
            return;
        }
        run.startedAt = Date.now();
        elapsed.value = 0;
        status.value = 'recording';
        run.timer = setInterval(() => {
            if (mine !== session || active !== run) return;
            elapsed.value = Math.floor((Date.now() - run.startedAt) / 1000);
            if (elapsed.value >= maxSeconds) stop();
        }, 250);
    }

    function stop() {
        const run = active;
        if (status.value !== 'recording' || !run?.recorder) return;
        status.value = 'sending';
        clearInterval(run.timer);
        run.timer = null;
        if (run.recorder.state !== 'inactive') run.recorder.stop();
    }

    function cancel() {
        session += 1; // invalida qualquer resposta em voo
        const run = active;
        reset(run);
        if (run?.recorder && run.recorder.state !== 'inactive') run.recorder.stop();
    }

    async function handleStopped(run) {
        const mine = run.id;
        releaseHardware(run);

        if (mine !== session || active !== run) return;

        try {
            const { wav } = await deps.toWav(new Blob(run.chunks, { type: run.chunks[0]?.type || 'audio/webm' }));
            if (mine !== session || active !== run) return;

            const form = new FormData();
            form.append('audio', wav, 'ditado.wav');
            const { data } = await window.axios.post(url, form);
            if (mine !== session || active !== run) return;

            const text = String(data?.text ?? '').trim();
            if (text) onText?.(text);
        } catch (e) {
            if (mine !== session || active !== run) return;
            fail(
                e?.response?.data?.message ??
                    e?.response?.data?.errors?.audio?.[0] ??
                    tt('dictation_failed', 'Não foi possível transcrever o ditado.'),
            );
        } finally {
            if (mine === session && active === run) reset(run);
        }
    }

    function toggle() {
        return status.value === 'recording' ? stop() : start();
    }

    onBeforeUnmount(cancel);

    return { status, elapsed, supported, recording, sending, busy, start, stop, cancel, toggle };
}
