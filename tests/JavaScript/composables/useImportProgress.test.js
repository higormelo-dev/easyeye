import { describe, it, expect, vi, beforeEach } from 'vitest';
import { defineComponent, h, ref, nextTick } from 'vue';
import { mount } from '@vue/test-utils';

/**
 * Progresso de importação via WebSocket (Echo/Reverb) — sem polling HTTP.
 */
const echoState = vi.hoisted(() => ({ channels: {}, left: [], status: null }));
vi.mock('@laravel/echo-vue', async () => {
    const { ref: vueRef } = await import('vue');
    echoState.status = vueRef('connected');
    const fakeEcho = {
        private(name) {
            const channel = { name, handlers: {}, onSubscribed: null };
            channel.subscribed = (cb) => ((channel.onSubscribed = cb), channel);
            channel.listen = (event, cb) => ((channel.handlers[event] = cb), channel);
            echoState.channels[name] = channel;
            return channel;
        },
        leave: (name) => echoState.left.push(name),
    };
    return {
        echo: () => fakeEcho,
        echoIsConfigured: () => true,
        useConnectionStatus: () => echoState.status,
    };
});

import { useImportProgress } from '@/composables/useImportProgress';

function mountWith(initial, options = {}) {
    const state = ref(initial);
    let api;
    const wrapper = mount(
        defineComponent({
            setup() {
                api = useImportProgress(state, options);
                return () => h('div');
            },
        }),
    );
    return { state, wrapper, api: () => api };
}

const running = { id: 'imp-1', channel: 'imports.patients.imp-1', is_done: false, processed_rows: 0 };

describe('useImportProgress', () => {
    beforeEach(() => {
        echoState.channels = {};
        echoState.left = [];
        echoState.status.value = 'connected';
    });

    it('assina o canal privado da importação em andamento e aplica cada evento', () => {
        const { state } = mountWith({ ...running });

        echoState.channels['imports.patients.imp-1'].handlers['.import.progress']({
            id: 'imp-1',
            processed_rows: 40,
            progress: 40,
            is_done: false,
        });

        expect(state.value.processed_rows).toBe(40);
        expect(state.value.progress).toBe(40);
    });

    it('ignora evento de outra importação', () => {
        const { state } = mountWith({ ...running });

        echoState.channels['imports.patients.imp-1'].handlers['.import.progress']({ id: 'outra', processed_rows: 99 });

        expect(state.value.processed_rows).toBe(0);
    });

    it('ao terminar: chama onDone e sai do canal', () => {
        const onDone = vi.fn();
        mountWith({ ...running }, { onDone });

        echoState.channels['imports.patients.imp-1'].handlers['.import.progress']({ id: 'imp-1', is_done: true });

        expect(onDone).toHaveBeenCalledOnce();
        expect(echoState.left).toContain('imports.patients.imp-1');
    });

    it('ressincroniza ao confirmar a assinatura (eventos antes da conexão não se perdem)', () => {
        const onResync = vi.fn();
        mountWith({ ...running }, { onResync });

        echoState.channels['imports.patients.imp-1'].onSubscribed();

        expect(onResync).toHaveBeenCalledOnce();
    });

    it('ressincroniza ao reconectar depois de uma queda e expõe o estado da conexão', async () => {
        const onResync = vi.fn();
        const { api } = mountWith({ ...running }, { onResync });

        echoState.status.value = 'reconnecting';
        await nextTick();
        expect(api().realtimeConnected.value).toBe(false);

        echoState.status.value = 'connected';
        await nextTick();
        expect(onResync).toHaveBeenCalledOnce();
        expect(api().realtimeConnected.value).toBe(true);
    });

    it('sem tempo real: ao voltar para a aba relê o estado uma vez; conectado ou terminada, não', async () => {
        const onResync = vi.fn();
        const { state } = mountWith({ ...running }, { onResync });
        const setVisibility = (value) => {
            Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => value });
            document.dispatchEvent(new Event('visibilitychange'));
        };

        setVisibility('visible'); // conectado: não precisa
        expect(onResync).not.toHaveBeenCalled();

        echoState.status.value = 'disconnected';
        await nextTick();
        setVisibility('hidden');
        expect(onResync).not.toHaveBeenCalled();
        setVisibility('visible');
        expect(onResync).toHaveBeenCalledOnce();

        state.value = { ...running, is_done: true };
        setVisibility('visible');
        expect(onResync).toHaveBeenCalledOnce();
    });

    it('resync() relê o estado sob demanda (botão "Atualizar status")', () => {
        const onResync = vi.fn();
        const { api } = mountWith({ ...running }, { onResync });

        api().resync();

        expect(onResync).toHaveBeenCalledOnce();
    });

    it('importação já terminada não assina nada; desmontar sai do canal', () => {
        mountWith({ ...running, is_done: true });
        expect(echoState.channels).toEqual({});

        const { wrapper } = mountWith({ ...running });
        wrapper.unmount();
        expect(echoState.left).toContain('imports.patients.imp-1');
    });
});
