import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { echo, echoIsConfigured, useConnectionStatus } from '@laravel/echo-vue';

/**
 * Progresso de importação em tempo real (pacientes, médicos, agenda,
 * catálogo de medicamentos) — WebSocket via Reverb, sem polling HTTP.
 *
 * `importRef` guarda o estado da importação (formato de progressPayload() no
 * servidor, com `id`, `channel` e `is_done`). Enquanto ela não termina, o
 * composable assina o canal privado e mescla cada evento `import.progress`
 * (App\Events\ImportProgressUpdated) no próprio ref.
 *
 * - onResync: chamado quando a assinatura confirma (e a cada reconexão) —
 *   eventos emitidos ANTES de assinar (importação curta que termina antes da
 *   página conectar) não se perdem: a tela relê as props uma vez.
 * - onDone: chamado uma vez quando a importação termina.
 *
 * @param {import('vue').Ref<Object|null>} importRef
 * @param {{ onDone?: Function, onResync?: Function }} [options]
 */
export function useImportProgress(importRef, { onDone, onResync } = {}) {
    const configured = echoIsConfigured();
    const status = configured ? useConnectionStatus() : ref('disconnected');
    let current = null;

    function leave() {
        if (current) {
            echo().leave(current);
            current = null;
        }
    }

    function subscribe(channelName) {
        if (channelName === current) return;
        leave();
        if (!channelName || !configured) return;

        current = channelName;
        echo()
            .private(channelName)
            .subscribed(() => onResync?.())
            .listen('.import.progress', (payload) => {
                if (!importRef.value || payload.id !== importRef.value.id) return;
                importRef.value = { ...importRef.value, ...payload };
                if (payload.is_done) {
                    leave();
                    onDone?.(importRef.value);
                }
            });
    }

    watch(() => (importRef.value && !importRef.value.is_done ? importRef.value.channel : null), subscribe, {
        immediate: true,
    });

    // Voltou a conexão depois de uma queda: pode ter perdido eventos. (A 1ª
    // conexão já ressincroniza pelo `subscribed` acima.)
    let wasConnected = status.value === 'connected';
    watch(status, (now) => {
        if (now === 'connected') {
            if (wasConnected && current) onResync?.();
            wasConnected = true;
        }
    });

    const realtimeConnected = computed(() => configured && status.value === 'connected');
    const running = () => !!importRef.value && !importRef.value.is_done;

    // Sem tempo real (Reverb fora do ar/reconectando) a tela ficaria parada:
    // ao voltar para a aba relê o estado UMA vez. Sem polling — só reage ao
    // usuário (aqui e no botão "Atualizar status" da tela, via resync()).
    function onVisible() {
        if (document.visibilityState === 'visible' && running() && !realtimeConnected.value) onResync?.();
    }

    if (typeof document !== 'undefined') document.addEventListener('visibilitychange', onVisible);

    onBeforeUnmount(() => {
        leave();
        if (typeof document !== 'undefined') document.removeEventListener('visibilitychange', onVisible);
    });

    return {
        /** false = sem tempo real no momento (servidor fora / reconectando). */
        realtimeConnected,
        /** Relê o estado da importação sob demanda (ação do usuário). */
        resync: () => onResync?.(),
    };
}
