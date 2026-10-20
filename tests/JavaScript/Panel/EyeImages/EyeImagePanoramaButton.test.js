import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { shallowMount, flushPromises } from '@vue/test-utils';
import Index from '@/Pages/Panel/EyeImages/Index.vue';
import CompareModal from '@/Pages/Panel/EyeImages/EyeImageCompareModal.vue';
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /><slot name="top-actions" /></div>' } }));
const notice = 'Composição aproximada de teste';
const range = 'Selecione de 2 a 6 imagens de teste';
const photos = Array.from({ length: 7 }, (_, index) => ({
    id: `image-${index}`,
    exam_id: 'synthetic-type',
    archive: `synthetic-${index}.png`,
    created_at: '2026-10-18T12:00:00Z',
}));
const video = { id: 'video', archive: 'synthetic.WEBM', exam_id: 'synthetic-type', created_at: '2026-10-18T12:00:00Z' };
const wrappers = [];
const originalCreate = URL.createObjectURL;
const originalRevoke = URL.revokeObjectURL;
let post;
let downloads;
beforeEach(() => {
    post = vi.fn().mockResolvedValue({ data: new Blob(['synthetic PNG'], { type: 'image/png' }) });
    vi.stubGlobal('axios', { post });
    vi.stubGlobal('showSuccessToast', vi.fn());
    vi.stubGlobal('showErrorToast', vi.fn());
    URL.createObjectURL = vi.fn(() => 'blob:synthetic');
    URL.revokeObjectURL = vi.fn();
    downloads = [];
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function () {
        downloads.push(this.download);
    });
});
afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    if (originalCreate) URL.createObjectURL = originalCreate;
    else delete URL.createObjectURL;
    if (originalRevoke) URL.revokeObjectURL = originalRevoke;
    else delete URL.revokeObjectURL;
});
async function render({ ids = ['image-0', 'image-1'], url = '/panorama', align = null } = {}) {
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => ({ json: async () => ({ urls: {}, url: '' }) })),
    );
    const wrapper = shallowMount(Index, {
        props: {
            entity: { id: 'synthetic-entity' },
            urls: {
                panorama: url,
                align,
                montage: '/montage',
                patient_urls: '/patient/__ID__/urls',
                image_url: '/exam/__ID__/url',
            },
            t: { panorama_approximate_notice: notice, panorama_select_range: range },
        },
        global: { stubs: { Teleport: true }, renderStubDefaultSlot: true },
    });
    wrappers.push(wrapper);
    wrapper.vm.selectedPatient = {
        id: 'synthetic-patient',
        full_name: 'Paciente sintético',
        exams: [...photos, video],
    };
    wrapper.vm.selectedExamIds = ids;
    await flushPromises();
    return wrapper;
}
describe('Panorama automático', () => {
    it('URL nula esconde botão e não permite chamada programática', async () => {
        const wrapper = await render({ url: null });
        expect(wrapper.find('[data-testid="panorama-button"]').exists()).toBe(false);
        await wrapper.vm.downloadPanorama();
        expect(post).not.toHaveBeenCalled();
    });
    it.each([2, 6])('%i imagens habilitam botão e enviam ids na ordem da seleção', async (count) => {
        const ids = photos
            .slice(0, count)
            .map((image) => image.id)
            .reverse();
        const wrapper = await render({ ids });
        expect(wrapper.get('[data-testid="panorama-button"]').element.disabled).toBe(false);
        expect(wrapper.get('[data-testid="panorama-button"]').attributes('title')).toContain(notice);
        expect(wrapper.get('[data-testid="panorama-button"]').attributes('title')).toContain(range);
        await wrapper.get('[data-testid="panorama-button"]').trigger('click');
        await flushPromises();
        expect(post).toHaveBeenCalledExactlyOnceWith('/panorama', { exam_ids: ids }, { responseType: 'blob' });
        expect(downloads).toEqual(['panorama.png']);
        expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:synthetic');
    });
    it.each([0, 1, 7])('%i imagens bloqueiam botão e chamada direta', async (count) => {
        const wrapper = await render({ ids: photos.slice(0, count).map((image) => image.id) });
        expect(wrapper.get('[data-testid="panorama-button"]').element.disabled).toBe(true);
        await wrapper.vm.downloadPanorama();
        expect(post).not.toHaveBeenCalled();
        expect(window.showErrorToast).toHaveBeenCalledWith(range);
    });
    it('exclui vídeo antes da contagem e do payload', async () => {
        const wrapper = await render({ ids: ['image-1', 'video', 'image-0'] });
        await wrapper.vm.downloadPanorama();
        expect(post).toHaveBeenCalledWith('/panorama', { exam_ids: ['image-1', 'image-0'] }, { responseType: 'blob' });
        wrapper.vm.selectedExamIds = ['video', 'image-0'];
        await flushPromises();
        expect(wrapper.get('[data-testid="panorama-button"]').element.disabled).toBe(true);
        await wrapper.vm.downloadPanorama();
        expect(post).toHaveBeenCalledTimes(1);
    });
    it('mostra aviso de composição a cada geração bem-sucedida', async () => {
        const wrapper = await render();
        await wrapper.vm.downloadPanorama();
        await wrapper.vm.downloadPanorama();
        expect(window.showSuccessToast).toHaveBeenCalledTimes(2);
        expect(window.showSuccessToast).toHaveBeenNthCalledWith(1, notice);
        expect(window.showSuccessToast).toHaveBeenNthCalledWith(2, notice);
    });
    it('lê message do erro Blob e libera o estado ocupado sem toast de sucesso', async () => {
        post.mockRejectedValueOnce({
            response: {
                status: 422,
                data: new Blob([JSON.stringify({ message: 'Sobreposição insuficiente de teste' })]),
            },
        });
        const wrapper = await render();
        await wrapper.vm.downloadPanorama();
        expect(window.showErrorToast).toHaveBeenCalledWith('Sobreposição insuficiente de teste');
        expect(window.showSuccessToast).not.toHaveBeenCalled();
        expect(downloads).toEqual([]);
        expect(wrapper.vm.panoramaBusy).toBe(false);
    });
    it('429 usa panorama_busy com fallback e preserva message do Montage', async () => {
        post.mockRejectedValue({
            response: { status: 429, data: new Blob([JSON.stringify({ message: 'Too Many Attempts.' })]) },
        });
        const wrapper = await render();
        await wrapper.setProps({ t: { panorama_busy: 'Busy panorama translation' } });
        await wrapper.vm.downloadPanorama();
        expect(window.showErrorToast).toHaveBeenLastCalledWith('Busy panorama translation');
        expect(wrapper.vm.panoramaBusy).toBe(false);
        expect(downloads).toEqual([]);
        expect(window.showSuccessToast).not.toHaveBeenCalled();
        await wrapper.setProps({ t: {} });
        await wrapper.vm.downloadPanorama();
        expect(window.showErrorToast).toHaveBeenLastCalledWith(
            'O servidor está ocupado gerando outra imagem; tente de novo em instantes.',
        );
        await wrapper.vm.downloadMontage();
        expect(window.showErrorToast).toHaveBeenLastCalledWith('Too Many Attempts.');
        expect(wrapper.vm.montageBusy).toBe(false);
    });
    it('mantém fallback traduzido para erro sem JSON', async () => {
        post.mockRejectedValueOnce(new Error('offline'));
        const wrapper = await render();
        await wrapper.setProps({ t: { panorama_failed: 'Falha traduzida' } });
        await wrapper.vm.downloadPanorama();
        expect(window.showErrorToast).toHaveBeenCalledWith('Falha traduzida');
    });
    it('bloqueia envios duplicados durante geração e libera após resposta', async () => {
        let resolve;
        post.mockImplementationOnce(
            () =>
                new Promise((done) => {
                    resolve = done;
                }),
        );
        const wrapper = await render();
        const request = wrapper.vm.downloadPanorama();
        await flushPromises();
        expect(wrapper.get('[data-testid="panorama-button"]').element.disabled).toBe(true);
        expect(wrapper.get('[data-testid="panorama-button"] .spinner-border').exists()).toBe(true);
        await wrapper.vm.downloadPanorama();
        expect(post).toHaveBeenCalledTimes(1);
        resolve({ data: new Blob(['synthetic PNG']) });
        await request;
        await flushPromises();
        expect(wrapper.get('[data-testid="panorama-button"]').element.disabled).toBe(false);
    });
    it('encaminha alignUrl ao modal sem alterar seu contrato', async () => {
        const wrapper = await render({ align: '/align' });
        expect(wrapper.findComponent(CompareModal).props('alignUrl')).toBe('/align');
    });
});
