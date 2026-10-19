import { describe, it, expect, vi, afterEach } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import { renderIndex, exam } from './eyeImagesTestHelpers.js';
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /><slot name="top-actions" /></div>' } }));
let wrapper;
afterEach(() => {
    wrapper?.unmount();
    vi.unstubAllGlobals();
});
describe('Eye Images: estado visto', () => {
    it('marca uma vez ao exibir no painel, atualiza a bolinha e não repete na reabertura', async () => {
        const put = vi.fn().mockResolvedValue({ data: { viewed_at: '2026-10-18T15:00:00Z' } });
        vi.stubGlobal('axios', { put });
        ({ wrapper } = await renderIndex({
            patients: [{ id: 'p1', code: 'PAC-1', full_name: 'Paciente', exams: [exam()] }],
        }));
        await wrapper.vm.selectPatient(wrapper.vm.patients[0]);
        await flushPromises();
        expect(wrapper.find('[data-testid="exam-unviewed-dot"]').exists()).toBe(true);
        wrapper.vm.openViewerModal(wrapper.vm.selectedPatient.exams, 0, 1);
        await flushPromises();
        expect(put).toHaveBeenCalledExactlyOnceWith('/exam/exam-a/viewed');
        expect(wrapper.vm.selectedPatient.exams[0].viewed_at).toBe('2026-10-18T15:00:00Z');
        expect(wrapper.find('[data-testid="exam-unviewed-dot"]').exists()).toBe(false);
        wrapper.vm.setPanelExam(0, wrapper.vm.selectedPatient.exams[0]);
        await flushPromises();
        expect(put).toHaveBeenCalledTimes(1);
    });
    it('deduplica requisições em voo e permite tentar novamente após falha', async () => {
        let reject;
        const put = vi
            .fn()
            .mockImplementationOnce(
                () =>
                    new Promise((_, fail) => {
                        reject = fail;
                    }),
            )
            .mockResolvedValue({ data: { viewed_at: 'now' } });
        vi.stubGlobal('axios', { put });
        ({ wrapper } = await renderIndex());
        const image = exam();
        wrapper.vm.setPanelExam(0, image);
        wrapper.vm.setPanelExam(0, image);
        expect(put).toHaveBeenCalledTimes(1);
        reject(new Error('offline'));
        await flushPromises();
        wrapper.vm.setPanelExam(0, image);
        await flushPromises();
        expect(put).toHaveBeenCalledTimes(2);
    });
    it('não marca exame já visto e envia o filtro de não vistos', async () => {
        const put = vi.fn();
        vi.stubGlobal('axios', { put });
        const result = await renderIndex({ filters: { viewed: 'nao_vistos' } });
        wrapper = result.wrapper;
        expect(wrapper.vm.showFilters).toBe(true);
        wrapper.vm.setPanelExam(0, exam({ viewed_at: 'yesterday' }));
        expect(put).not.toHaveBeenCalled();
        await wrapper.vm.fetchPatients();
        expect(new URL(result.fetchMock.mock.calls.at(-1)[0], 'https://local').searchParams.get('viewed')).toBe(
            'nao_vistos',
        );
        wrapper.vm.clearFilters();
        await flushPromises();
        await wrapper.vm.fetchPatients();
        expect(new URL(result.fetchMock.mock.calls.at(-1)[0], 'https://local').searchParams.has('viewed')).toBe(false);
    });
});
