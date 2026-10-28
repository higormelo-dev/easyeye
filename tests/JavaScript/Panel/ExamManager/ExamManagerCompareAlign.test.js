import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises, DOMWrapper } from '@vue/test-utils';
import CompareModal from '@/Pages/Panel/ExamManager/ExamManagerCompareModal.vue';
const images = [
    { id: 'synthetic-a', url: '/synthetic-a.png', label: 'Imagem sintética A' },
    { id: 'synthetic-b', url: '/synthetic-b.png', label: 'Imagem sintética B' },
];
let wrapper;
const dialog = () => new DOMWrapper(document.body);
let post;
beforeEach(() => {
    post = vi.fn().mockResolvedValue({ data: { dx: 12.5, dy: -3, confidence: 0.83 } });
    vi.stubGlobal('axios', { post });
    vi.stubGlobal('showErrorToast', vi.fn());
});
afterEach(() => {
    wrapper?.unmount();
    vi.unstubAllGlobals();
});
function render(props = {}, { clientWidth = 410, naturalWidth = 820 } = {}) {
    wrapper = mount(CompareModal, {
        props: { open: true, images: images.map((image) => ({ ...image })), alignUrl: '/align', ...props },
        global: { stubs: { Teleport: false } },
    });
    const base = dialog().find('img').element;
    if (base) {
        Object.defineProperty(base, 'clientWidth', { configurable: true, value: clientWidth });
        Object.defineProperty(base, 'naturalWidth', { configurable: true, value: naturalWidth });
    }
    return wrapper;
}
describe('Auto-alinhar exames', () => {
    it.each([
        [410, 820, 6.25, -1.5],
        [820, 410, 25, -6],
    ])('converte natural para tela com clientWidth=%i/naturalWidth=%i', async (clientWidth, naturalWidth, x, y) => {
        render({}, { clientWidth, naturalWidth });
        await dialog().get('[data-testid="compare-auto-align"]').trigger('click');
        await flushPromises();
        expect(post).toHaveBeenCalledExactlyOnceWith('/align', { exam_id_a: 'synthetic-a', exam_id_b: 'synthetic-b' });
        expect(wrapper.vm.offset).toEqual({ x, y });
        expect(dialog().findAll('img')[1].attributes('style')).toContain(`translate(${x}px, ${y}px)`);
        expect(window.showErrorToast).not.toHaveBeenCalled();
    });
    it('erro do servidor mantém offset manual e exibe message', async () => {
        post.mockRejectedValueOnce({
            response: { status: 422, data: { message: 'Alinhamento de teste não confiável' } },
        });
        render();
        Object.assign(wrapper.vm.offset, { x: 31, y: -11 });
        await dialog().get('[data-testid="compare-auto-align"]').trigger('click');
        await flushPromises();
        expect(wrapper.vm.offset).toEqual({ x: 31, y: -11 });
        expect(window.showErrorToast).toHaveBeenCalledWith('Alinhamento de teste não confiável');
        expect(wrapper.vm.alignBusy).toBe(false);
    });
    it('429 usa align_busy e conserva offset com tradução e fallback', async () => {
        post.mockRejectedValue({ response: { status: 429, data: { message: 'Too Many Attempts.' } } });
        render({ t: { align_busy: 'Busy alignment translation' } });
        Object.assign(wrapper.vm.offset, { x: 31, y: -11 });
        await wrapper.vm.autoAlign();
        expect(window.showErrorToast).toHaveBeenLastCalledWith('Busy alignment translation');
        expect(wrapper.vm.offset).toEqual({ x: 31, y: -11 });
        expect(wrapper.vm.alignBusy).toBe(false);
        await wrapper.setProps({ t: {} });
        await wrapper.vm.autoAlign();
        expect(window.showErrorToast).toHaveBeenLastCalledWith(
            'O servidor está ocupado gerando outra imagem; tente de novo em instantes.',
        );
        expect(wrapper.vm.offset).toEqual({ x: 31, y: -11 });
    });
    it('erro de rede usa tradução sem alterar posição', async () => {
        post.mockRejectedValueOnce(new Error('offline'));
        render({ t: { align_failed: 'Falha traduzida' } });
        Object.assign(wrapper.vm.offset, { x: 4, y: 5 });
        await wrapper.vm.autoAlign();
        expect(window.showErrorToast).toHaveBeenCalledWith('Falha traduzida');
        expect(wrapper.vm.offset).toEqual({ x: 4, y: 5 });
    });
    it.each([
        [410, 0],
        [0, 820],
    ])('não aplica resultado sem dimensões válidas (%i/%i)', async (clientWidth, naturalWidth) => {
        render({}, { clientWidth, naturalWidth });
        Object.assign(wrapper.vm.offset, { x: 8, y: -7 });
        await wrapper.vm.autoAlign();
        expect(wrapper.vm.offset).toEqual({ x: 8, y: -7 });
        expect(window.showErrorToast).toHaveBeenCalledWith('Não foi possível alinhar automaticamente.');
    });
    it('resposta malformada não grava NaN nos estilos', async () => {
        post.mockResolvedValueOnce({ data: { dx: null, dy: 2 } });
        render();
        Object.assign(wrapper.vm.offset, { x: 8, y: -7 });
        await wrapper.vm.autoAlign();
        expect(wrapper.vm.offset).toEqual({ x: 8, y: -7 });
        expect(window.showErrorToast).toHaveBeenCalled();
    });
    it('URL nula esconde botão e bloqueia chamada direta', async () => {
        render({ alignUrl: null });
        expect(dialog().find('[data-testid="compare-auto-align"]').exists()).toBe(false);
        await wrapper.vm.autoAlign();
        expect(post).not.toHaveBeenCalled();
    });
    it('existe somente em Sobrepor e exige duas imagens', async () => {
        render();
        wrapper.vm.mode = 'side';
        await flushPromises();
        expect(dialog().find('[data-testid="compare-auto-align"]').exists()).toBe(false);
        await wrapper.vm.autoAlign();
        expect(post).not.toHaveBeenCalled();
        wrapper.vm.mode = 'overlay';
        await wrapper.setProps({ images: [images[0]] });
        expect(dialog().get('[data-testid="compare-auto-align"]').element.disabled).toBe(true);
        await wrapper.vm.autoAlign();
        expect(post).not.toHaveBeenCalled();
    });
    it('loading desabilita botão e bloqueia envio duplicado, depois libera', async () => {
        let resolve;
        post.mockImplementationOnce(
            () =>
                new Promise((done) => {
                    resolve = done;
                }),
        );
        render();
        const request = wrapper.vm.autoAlign();
        await flushPromises();
        expect(dialog().get('[data-testid="compare-auto-align"]').element.disabled).toBe(true);
        expect(dialog().get('[data-testid="compare-auto-align"] .spinner-border').exists()).toBe(true);
        await wrapper.vm.autoAlign();
        expect(post).toHaveBeenCalledTimes(1);
        resolve({ data: { dx: 20, dy: -10, confidence: 0.9 } });
        await request;
        await flushPromises();
        expect(dialog().get('[data-testid="compare-auto-align"]').element.disabled).toBe(false);
        expect(wrapper.vm.offset).toEqual({ x: 10, y: -5 });
    });
    it.each(['close', 'pair', 'mode'])('descarta resposta atrasada após mudança de %s', async (change) => {
        let resolve;
        post.mockImplementationOnce(
            () =>
                new Promise((done) => {
                    resolve = done;
                }),
        );
        render();
        Object.assign(wrapper.vm.offset, { x: 3, y: 4 });
        const request = wrapper.vm.autoAlign();
        if (change === 'close') await wrapper.setProps({ open: false });
        if (change === 'pair') await wrapper.setProps({ images: [{ ...images[0], id: 'new-a' }, images[1]] });
        if (change === 'mode') {
            wrapper.vm.mode = 'side';
            await flushPromises();
        }
        resolve({ data: { dx: 100, dy: 100, confidence: 0.9 } });
        await request;
        await flushPromises();
        expect(wrapper.vm.offset).toEqual({ x: 3, y: 4 });
        expect(window.showErrorToast).not.toHaveBeenCalled();
    });
});
