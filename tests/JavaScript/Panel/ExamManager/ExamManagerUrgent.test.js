import { describe, it, expect, vi, afterEach } from 'vitest';
import { shallowMount, flushPromises } from '@vue/test-utils';
import Menu from '@/Pages/Panel/ExamManager/ExamManagerContextMenu.vue';
import { renderIndex, exam } from './examManagerTestHelpers.js';
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /><slot name="top-actions" /></div>' } }));
let wrapper;
afterEach(() => {
    wrapper?.unmount();
    vi.unstubAllGlobals();
});
describe('Gerenciador de exames: urgente', () => {
    it.each([false, true])('médico alterna urgência de %s sem mutar a prop', async (urgent) => {
        const put = vi.fn().mockResolvedValue({ data: { urgent: !urgent } });
        vi.stubGlobal('axios', { put });
        wrapper = shallowMount(Menu, {
            props: { open: true, isDoctor: true, exam: exam({ urgent }), urls: { urgent: '/exam/__ID__/urgent' } },
            global: { stubs: { Teleport: true } },
        });
        await wrapper.get('[data-testid="exam-urgent-toggle"]').trigger('click');
        await flushPromises();
        expect(put).toHaveBeenCalledExactlyOnceWith('/exam/exam-a/urgent', { urgent: !urgent });
        expect(wrapper.emitted('updated')[0][0].changes).toEqual({ urgent: !urgent });
        expect(wrapper.props('exam').urgent).toBe(urgent);
        await wrapper.setProps({ exam: exam({ urgent: !urgent }) });
        expect(wrapper.get('[data-testid="exam-urgent-toggle"]').text()).toContain(!urgent ? 'Remover' : 'Marcar');
    });
    it('não oferece ação para secretária e não atualiza estado em erro', async () => {
        const put = vi.fn().mockRejectedValue(new Error('403'));
        vi.stubGlobal('axios', { put });
        wrapper = shallowMount(Menu, {
            props: { open: true, exam: exam(), urls: { urgent: '/exam/__ID__/urgent' } },
            global: { stubs: { Teleport: true } },
        });
        expect(wrapper.find('[data-testid="exam-urgent-toggle"]').exists()).toBe(false);
        await wrapper.setProps({ isDoctor: true });
        await wrapper.get('[data-testid="exam-urgent-toggle"]').trigger('click');
        await flushPromises();
        expect(wrapper.emitted('updated')).toBeUndefined();
    });
    it('exibe badge apenas no urgente, aplica evento e envia/limpa filtro', async () => {
        const result = await renderIndex({
            patients: [
                { id: 'p1', full_name: 'Paciente', exams: [exam({ urgent: true }), exam({ id: 'b', urgent: false })] },
            ],
            filters: { urgent: true },
        });
        wrapper = result.wrapper;
        expect(wrapper.vm.showFilters).toBe(true);
        await wrapper.vm.selectPatient(wrapper.vm.patients[0]);
        await flushPromises();
        expect(wrapper.findAll('[data-testid="exam-urgent-badge"]')).toHaveLength(1);
        wrapper.vm.onContextMenuUpdated({ exam: wrapper.vm.selectedPatient.exams[1], changes: { urgent: true } });
        await flushPromises();
        expect(wrapper.findAll('[data-testid="exam-urgent-badge"]')).toHaveLength(2);
        await wrapper.vm.fetchPatients();
        expect(new URL(result.fetchMock.mock.calls.at(-1)[0], 'https://local').searchParams.get('urgent')).toBe('1');
        wrapper.vm.clearFilters();
        await flushPromises();
        await wrapper.vm.fetchPatients();
        expect(new URL(result.fetchMock.mock.calls.at(-1)[0], 'https://local').searchParams.has('urgent')).toBe(false);
    });
});
