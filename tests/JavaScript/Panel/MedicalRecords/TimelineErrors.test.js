import { afterEach, describe, expect, it, vi } from 'vitest';
import { shallowMount, flushPromises } from '@vue/test-utils';
import Index from '@/Pages/Panel/MedicalRecords/Index.vue';
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
const row = (id) => ({ id, code: `SYNTH-${id}`, documentations: [], doctor_name: 'Médico sintético' });
const response = (data, ok = true) => Promise.resolve({ ok, json: vi.fn(async () => data) });
let wrapper;
afterEach(() => wrapper?.unmount());
async function render() {
    wrapper = shallowMount(Index, {
        props: {
            patient: { id: 'synthetic' },
            urls: { ajax_list: '/timeline' },
            t: {
                timeline_load_error: 'Load failed',
                timeline_load_more_error: 'More failed',
                timeline_retry: 'Retry',
                no_records: 'Empty',
            },
        },
        global: { stubs: { AppLayout: { template: '<div><slot /></div>' } } },
    });
    await flushPromises();
}
describe('Timeline — erro não é ausência de prontuário', () => {
    it('500 inicial não lê JSON clínico como sucesso; retry recupera sem mostrar vazio', async () => {
        const json = vi.fn();
        globalThis.fetch = vi
            .fn()
            .mockResolvedValueOnce({ ok: false, json })
            .mockImplementation(() => response({ data: [row('1')], has_more: false, total: 1 }));
        await render();
        expect(json).not.toHaveBeenCalled();
        expect(wrapper.get('[data-testid="timeline-initial-error"]').text()).toContain('Load failed');
        expect(wrapper.text()).not.toContain('Empty');
        await wrapper.get('[data-testid="timeline-initial-error"] button').trigger('click');
        await flushPromises();
        expect(wrapper.findAll('.timeline-item')).toHaveLength(1);
        expect(wrapper.find('[role="alert"]').exists()).toBe(false);
    });
    it('falha na página 2 preserva itens e página; retry não duplica e pode terminar a lista', async () => {
        globalThis.fetch = vi
            .fn()
            .mockImplementationOnce(() =>
                response({ data: [row('1'), row('2')], has_more: true, next_page: 2, total: 3 }),
            )
            .mockImplementationOnce(() => response({}, false))
            .mockImplementationOnce(() => response({ data: [row('3')], has_more: false, next_page: 3, total: 3 }));
        await render();
        await wrapper.vm.loadPage(2);
        await flushPromises();
        expect(wrapper.findAll('.timeline-item')).toHaveLength(2);
        expect(wrapper.vm.nextPage).toBe(2);
        expect(wrapper.get('[data-testid="timeline-more-error"]').text()).toContain('More failed');
        await wrapper.get('[data-testid="timeline-more-error"] button').trigger('click');
        await flushPromises();
        expect(globalThis.fetch.mock.calls.at(-1)[0]).toContain('page=2');
        expect(wrapper.findAll('.timeline-item')).toHaveLength(3);
        expect(wrapper.find('[role="alert"]').exists()).toBe(false);
    });
    it('mudar de paciente aborta a leitura anterior e resposta atrasada não vence', async () => {
        let old;
        globalThis.fetch = vi
            .fn()
            .mockImplementationOnce(
                () =>
                    new Promise((resolve) => {
                        old = resolve;
                    }),
            )
            .mockImplementation(() => response({ data: [], has_more: false, total: 0 }));
        await render();
        const signal = globalThis.fetch.mock.calls[0][1].signal;
        await wrapper.setProps({ patient: { id: 'new' }, urls: { ajax_list: '/new-timeline' } });
        await flushPromises();
        expect(signal.aborted).toBe(true);
        old(await response({ data: [row('old')], has_more: false, total: 1 }));
        await flushPromises();
        expect(wrapper.findAll('.timeline-item')).toHaveLength(0);
        expect(wrapper.text()).toContain('Empty');
    });
});
