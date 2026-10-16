import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';
import { mount, flushPromises } from '@vue/test-utils';
import { useDictation } from '@/composables/useDictation.js';

/**
 * Máquina de estados do ditado (idle → recording → sending → idle) com o
 * navegador substituído por fakes: stream, MediaRecorder e conversão para WAV.
 */
function makeDeps({ getStream, wavSeconds = 3 } = {}) {
    const track = { stop: vi.fn() };
    const stream = { getTracks: () => [track] };
    const recorders = [];

    return {
        track,
        recorders,
        deps: {
            getStream: getStream ?? vi.fn(() => Promise.resolve(stream)),
            createRecorder: vi.fn(() => {
                const recorder = {
                    state: 'inactive',
                    ondataavailable: null,
                    onstop: null,
                    start: vi.fn(() => {
                        recorder.state = 'recording';
                    }),
                    stop: vi.fn(() => {
                        recorder.state = 'inactive';
                        recorder.ondataavailable?.({ data: new Blob(['audio'], { type: 'audio/webm' }) });
                        recorder.onstop?.();
                    }),
                };
                recorders.push(recorder);

                return recorder;
            }),
            toWav: vi.fn(() => Promise.resolve({ wav: new Blob(['wav'], { type: 'audio/wav' }), seconds: wavSeconds })),
        },
    };
}

function mountHarness(options, deps) {
    let api;
    const wrapper = mount(
        defineComponent({
            setup() {
                api = useDictation(options, deps);

                return () => h('div');
            },
        }),
    );

    return { wrapper, api: () => api };
}

const tt = (key, fallback) => fallback ?? key;

beforeEach(() => {
    vi.useFakeTimers();
    window.axios = { post: vi.fn(() => Promise.resolve({ data: { text: ' Cristalino transparente. ' } })) };
});

afterEach(() => {
    vi.useRealTimers();
});

describe('useDictation — gravar, transcrever e devolver o texto', () => {
    it('grava, para, envia o WAV (campo audio) e devolve o texto transcrito', async () => {
        const onText = vi.fn();
        const { deps } = makeDeps();
        const { api } = mountHarness({ url: '/ditado', maxSeconds: 120, onText, onError: vi.fn(), tt }, deps);

        await api().start();
        expect(api().status.value).toBe('recording');

        api().stop();
        expect(api().status.value).toBe('sending');
        await vi.waitFor(() => expect(onText).toHaveBeenCalledWith('Cristalino transparente.'));

        const [url, form] = window.axios.post.mock.calls[0];
        expect(url).toBe('/ditado');
        expect(form.has('audio')).toBe(true); // o filename (ditado.wav) vem do append; o File do JSDOM não o repassa para Blob de outro realm
        expect(api().status.value).toBe('idle');
    });

    it('libera o microfone ao parar (a luz de gravação do navegador apaga)', async () => {
        const { deps, track } = makeDeps();
        const { api } = mountHarness({ url: '/d', onText: vi.fn(), onError: vi.fn(), tt }, deps);

        await api().start();
        api().stop();
        await flushPromises();

        expect(track.stop).toHaveBeenCalled();
    });

    it('para sozinho ao chegar no teto de duração', async () => {
        const { deps, recorders } = makeDeps();
        const { api } = mountHarness({ url: '/d', maxSeconds: 3, onText: vi.fn(), onError: vi.fn(), tt }, deps);

        await api().start();
        vi.advanceTimersByTime(3500);
        await nextTick();

        expect(recorders[0].stop).toHaveBeenCalled();
        expect(api().status.value).not.toBe('recording');
    });

    it('cancelar descarta o áudio: nada é enviado e nada é inserido', async () => {
        const onText = vi.fn();
        const { deps, track } = makeDeps();
        const { api } = mountHarness({ url: '/d', onText, onError: vi.fn(), tt }, deps);

        await api().start();
        api().cancel();
        await flushPromises();

        expect(window.axios.post).not.toHaveBeenCalled();
        expect(onText).not.toHaveBeenCalled();
        expect(api().status.value).toBe('idle');
        expect(track.stop).toHaveBeenCalled();
    });

    it('[GAP] resposta que chega depois de cancelar é ignorada (o texto não entra numa tela que já desistiu dele)', async () => {
        const onText = vi.fn();
        let resolvePost;
        window.axios.post = vi.fn(() => new Promise((resolve) => (resolvePost = resolve)));
        const { deps } = makeDeps();
        const { api } = mountHarness({ url: '/d', onText, onError: vi.fn(), tt }, deps);

        await api().start();
        api().stop();
        await vi.waitFor(() => expect(window.axios.post).toHaveBeenCalled());

        api().cancel(); // o usuário desistiu enquanto transcrevia
        resolvePost({ data: { text: 'texto tardio' } });
        await flushPromises();

        expect(onText).not.toHaveBeenCalled();
        expect(api().status.value).toBe('idle');
    });

    it('desmontar a tela no meio da gravação cancela (não deixa o microfone aberto)', async () => {
        const { deps, track } = makeDeps();
        const { api, wrapper } = mountHarness({ url: '/d', onText: vi.fn(), onError: vi.fn(), tt }, deps);

        await api().start();
        wrapper.unmount();

        expect(track.stop).toHaveBeenCalled();
    });

    it('permissão negada do microfone: avisa e continua parado', async () => {
        const onError = vi.fn();
        const { deps } = makeDeps({
            getStream: vi.fn(() => Promise.reject(Object.assign(new Error('x'), { name: 'NotAllowedError' }))),
        });
        const { api } = mountHarness({ url: '/d', onText: vi.fn(), onError, tt }, deps);

        await api().start();

        expect(onError).toHaveBeenCalledWith('Sem acesso ao microfone.');
        expect(api().status.value).toBe('idle');
    });

    it('cancelar enquanto o navegador ainda pede a permissão não deixa o microfone aberto', async () => {
        let grant;
        const track = { stop: vi.fn() };
        const { deps } = makeDeps({
            getStream: vi.fn(() => new Promise((resolve) => (grant = () => resolve({ getTracks: () => [track] })))),
        });
        const { api } = mountHarness({ url: '/d', onText: vi.fn(), onError: vi.fn(), tt }, deps);

        const starting = api().start();
        api().cancel();
        grant();
        await starting;

        expect(track.stop).toHaveBeenCalled();
        expect(api().status.value).toBe('idle');
        expect(deps.createRecorder).not.toHaveBeenCalled();
    });

    it('[F5] permissão antiga que resolve depois da nova não para o timer nem deixa microfone da nova sessão aberto', async () => {
        let grantFirst;
        let grantSecond;
        const firstTrack = { stop: vi.fn() };
        const secondTrack = { stop: vi.fn() };
        const { deps } = makeDeps({
            getStream: vi
                .fn()
                .mockImplementationOnce(
                    () => new Promise((resolve) => (grantFirst = () => resolve({ getTracks: () => [firstTrack] }))),
                )
                .mockImplementationOnce(
                    () => new Promise((resolve) => (grantSecond = () => resolve({ getTracks: () => [secondTrack] }))),
                ),
        });
        const { api } = mountHarness({ url: '/d', onText: vi.fn(), onError: vi.fn(), tt }, deps);

        const firstStart = api().start();
        expect(api().status.value).toBe('starting');
        api().cancel();

        const secondStart = api().start();
        expect(api().status.value).toBe('starting');
        grantSecond();
        await secondStart;
        expect(api().status.value).toBe('recording');

        grantFirst();
        await firstStart;
        expect(firstTrack.stop).toHaveBeenCalledTimes(1);
        expect(secondTrack.stop).not.toHaveBeenCalled();

        api().cancel();
        expect(secondTrack.stop).toHaveBeenCalledTimes(1);
    });

    it('erro do servidor mostra a mensagem dele; sem mensagem, o texto padrão', async () => {
        const onError = vi.fn();
        window.axios.post = vi.fn(() =>
            Promise.reject({ response: { data: { message: 'Nenhuma fala reconhecida.' } } }),
        );
        const { deps } = makeDeps();
        const { api } = mountHarness({ url: '/d', onText: vi.fn(), onError, tt }, deps);

        await api().start();
        api().stop();
        await vi.waitFor(() => expect(onError).toHaveBeenCalledWith('Nenhuma fala reconhecida.'));
        expect(api().status.value).toBe('idle');

        window.axios.post = vi.fn(() => Promise.reject(new Error('rede')));
        await api().start();
        api().stop();
        await vi.waitFor(() => expect(onError).toHaveBeenLastCalledWith('Não foi possível transcrever o ditado.'));
    });

    it('um segundo toque enquanto transcreve não inicia outra gravação', async () => {
        window.axios.post = vi.fn(() => new Promise(() => {}));
        const { deps } = makeDeps();
        const { api } = mountHarness({ url: '/d', onText: vi.fn(), onError: vi.fn(), tt }, deps);

        await api().start();
        api().stop();
        await api().start();

        expect(deps.createRecorder).toHaveBeenCalledTimes(1);
        expect(api().status.value).toBe('sending');
    });

    it('toggle alterna entre gravar e parar', async () => {
        const { deps } = makeDeps();
        const { api } = mountHarness({ url: '/d', onText: vi.fn(), onError: vi.fn(), tt }, deps);

        await api().toggle();
        expect(api().status.value).toBe('recording');
        api().toggle();
        expect(api().status.value).toBe('sending');
    });
});
