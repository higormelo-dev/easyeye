import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, shallowMount, flushPromises } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import MergeModal from '@/Pages/Panel/Patients/PatientMergeModal.vue';
import PatientTable from '@/Pages/Panel/Patients/PatientTable.vue';
import PatientIndex from '@/Pages/Panel/Patients/Index.vue';
import PatientDrawer from '@/Pages/Panel/Patients/PatientDetailDrawer.vue';
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const actual = await importOriginal();
    return {
        ...actual,
        usePage: () => ({
            props: {
                t_ui: { patient_merge: { title: 'Mesclar cadastro', merged_into: 'Mesclado em :code' } },
                auth: { user: { preferences: {} } },
            },
        }),
        router: { reload: vi.fn(), get: vi.fn(), post: vi.fn(), on: vi.fn(() => () => {}) },
    };
});
const labels = {
    title: 'Mesclar cadastro',
    hint: 'Manter :code, sem desfazer',
    duplicates: 'Duplicados prováveis',
    homonyms: 'Homônimos',
    none: 'Nenhum candidato',
    confirm: 'Confirmar',
    cancel: 'Cancelar',
    error: 'Erro ao mesclar',
    loading: 'Carregando',
    retry: 'Tentar novamente',
    birth_date_unknown: 'Nascimento não informado',
    direction: 'Transferir :source para :target',
    processing: 'Mesclando',
};
const candidate = {
    id: 'source',
    code: 'PAC-2',
    full_name: 'Paciente Origem',
    birth_date: '1980-01-02',
    kind: 'duplicate',
};
const patient = { id: 'target', code: 'PAC-1', full_name: 'Paciente Destino' };
const reply = (data, ok = true, status = 200) => ({ ok, status, json: async () => data });
let wrapper;
beforeEach(() => {
    vi.clearAllMocks();
    window.localStorage.clear();
    window.history.replaceState({}, '', '/panel/patients');
});
afterEach(() => {
    wrapper?.unmount();
    vi.unstubAllGlobals();
});
async function render(fetchMock, extra = {}) {
    vi.stubGlobal('fetch', fetchMock);
    wrapper = mount(MergeModal, {
        attachTo: document.body,
        props: { open: true, patient, labels, ...extra },
        global: { stubs: { Teleport: true } },
    });
    await flushPromises();
}
async function selectAndSubmit() {
    await wrapper.get('input[value="source"]').setValue(true);
    await wrapper.get('form').trigger('submit');
    await flushPromises();
}
describe('Mesclar cadastro', () => {
    it('carrega ao abrir, agrupa candidatos, exige seleção e informa direção', async () => {
        const fetchMock = vi
            .fn()
            .mockResolvedValue(
                reply({ data: [{ ...candidate, id: 'other', kind: 'homonym', birth_date: null }, candidate] }),
            );
        await render(fetchMock, { open: false });
        expect(fetchMock).not.toHaveBeenCalled();
        await wrapper.setProps({ open: true });
        await flushPromises();
        expect(fetchMock.mock.calls[0][0]).toBe('/_routes/panel.patients.merge-candidates/target');
        expect(wrapper.get('[data-testid="patient-merge-confirm"]').element.disabled).toBe(true);
        expect(wrapper.findAll('legend').map((node) => node.text())).toEqual(['Duplicados prováveis', 'Homônimos']);
        expect(wrapper.text()).toContain('Nascimento não informado');
        await wrapper.get('input[value="source"]').setValue(true);
        expect(wrapper.get('[data-testid="patient-merge-direction"]').text()).toBe('Transferir PAC-2 para PAC-1');
        expect(wrapper.get('[data-testid="patient-merge-confirm"]').element.disabled).toBe(false);
    });
    it('POST transfere candidato para cadastro aberto, inclui CSRF e emite merged/close com sucesso Swal', async () => {
        const data = { message: 'Cadastros mesclados', source: candidate, target: patient };
        const fetchMock = vi
            .fn()
            .mockResolvedValueOnce(reply({ data: [candidate] }))
            .mockResolvedValueOnce(reply(data));
        const fire = vi.fn();
        vi.stubGlobal('Swal', { fire });
        await render(fetchMock);
        await selectAndSubmit();
        const [url, options] = fetchMock.mock.calls[1];
        expect(url).toBe('/_routes/panel.patients.merge/source');
        expect(options.method).toBe('POST');
        expect(JSON.parse(options.body)).toEqual({ target_id: 'target', confirm: true });
        expect(options.headers['X-CSRF-TOKEN']).toBe('test-csrf-token');
        expect(fire).toHaveBeenCalledWith({ icon: 'success', text: data.message });
        expect(wrapper.emitted('merged')[0]).toEqual([data]);
        expect(wrapper.emitted('close')).toHaveLength(1);
    });
    it('422 exibe mensagem do servidor sem fechar ou anunciar sucesso', async () => {
        const fetchMock = vi
            .fn()
            .mockResolvedValueOnce(reply({ data: [candidate] }))
            .mockResolvedValueOnce(
                reply(
                    {
                        message: 'Prontuário assinado na origem: abra o outro cadastro',
                        errors: { target_id: ['Outro texto'] },
                    },
                    false,
                    422,
                ),
            );
        await render(fetchMock);
        await selectAndSubmit();
        expect(wrapper.get('[role="alert"]').text()).toContain('Prontuário assinado na origem');
        expect(wrapper.emitted('merged')).toBeUndefined();
        expect(wrapper.emitted('close')).toBeUndefined();
        expect(wrapper.get('[data-testid="patient-merge-confirm"]').element.disabled).toBe(false);
    });
    it('bloqueia envio duplicado e fechamento durante POST em voo', async () => {
        let resolve;
        const fetchMock = vi
            .fn()
            .mockResolvedValueOnce(reply({ data: [candidate] }))
            .mockImplementationOnce(
                () =>
                    new Promise((done) => {
                        resolve = done;
                    }),
            );
        await render(fetchMock);
        await selectAndSubmit();
        expect(wrapper.get('[data-testid="patient-merge-confirm"]').element.disabled).toBe(true);
        await wrapper.get('form').trigger('submit');
        wrapper.vm.close();
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(wrapper.emitted('close')).toBeUndefined();
        resolve(reply({ message: 'ok' }));
        await flushPromises();
        expect(wrapper.emitted('merged')).toHaveLength(1);
    });
    it('mostra vazio e falha de carga traduzida com nova tentativa', async () => {
        const fetchMock = vi
            .fn()
            .mockRejectedValueOnce(new Error('offline'))
            .mockResolvedValueOnce(reply({ data: [] }));
        await render(fetchMock);
        expect(wrapper.get('[role="alert"]').text()).toBe(labels.error);
        expect(wrapper.get('[data-testid="patient-merge-confirm"]').element.disabled).toBe(true);
        await wrapper
            .findAll('button')
            .find((node) => node.text() === labels.retry)
            .trigger('click');
        await flushPromises();
        expect(wrapper.text()).toContain(labels.none);
    });
    it('falha de POST mostra erro e não emite sucesso', async () => {
        const fetchMock = vi
            .fn()
            .mockResolvedValueOnce(reply({ data: [candidate] }))
            .mockRejectedValueOnce(new Error('offline'));
        await render(fetchMock);
        await selectAndSubmit();
        expect(wrapper.get('[role="alert"]').text()).toBe(labels.error);
        expect(wrapper.emitted('merged')).toBeUndefined();
    });
    it('troca de paciente descarta candidatos atrasados e limpa seleção', async () => {
        let resolve;
        const fetchMock = vi
            .fn()
            .mockImplementationOnce(
                () =>
                    new Promise((done) => {
                        resolve = done;
                    }),
            )
            .mockResolvedValueOnce(reply({ data: [] }));
        await render(fetchMock);
        await wrapper.setProps({ patient: { id: 'new', code: 'PAC-3' } });
        await flushPromises();
        resolve(reply({ data: [candidate] }));
        await flushPromises();
        expect(wrapper.findAll('input[type="radio"]')).toHaveLength(0);
        expect(wrapper.get('[data-testid="patient-merge-confirm"]').element.disabled).toBe(true);
    });
    it('ação só em full com can_merge e emite identidade do cadastro aberto', async () => {
        wrapper = shallowMount(PatientTable, {
            props: {
                patients: {
                    data: [
                        { ...patient, mode: 'full', can_merge: true },
                        { id: 'forbidden', mode: 'full', can_merge: false },
                        { id: 'readonly', mode: 'view_only', can_merge: true },
                        { id: 'deleted', mode: 'restore', can_merge: true },
                    ],
                },
            },
            global: { renderStubDefaultSlot: true, mocks: { route: globalThis.route } },
        });
        expect(wrapper.findAll('[data-testid="patient-merge-action"]')).toHaveLength(1);
        await wrapper.get('[data-testid="patient-merge-action"]').trigger('click');
        expect(wrapper.emitted('merge')[0]).toEqual([patient]);
    });
    it('página abre modal pela tabela e recarrega uma vez após merged', async () => {
        wrapper = shallowMount(PatientIndex, {
            props: { patients: { data: [] } },
            global: { renderStubDefaultSlot: true, mocks: { route: globalThis.route } },
        });
        wrapper.findComponent(PatientTable).vm.$emit('merge', patient);
        await flushPromises();
        expect(wrapper.findComponent(MergeModal).props('patient')).toEqual(patient);
        expect(wrapper.findComponent(MergeModal).props('open')).toBe(true);
        wrapper.findComponent(MergeModal).vm.$emit('merged', { message: 'ok' });
        await flushPromises();
        expect(router.reload).toHaveBeenCalledExactlyOnceWith({ preserveScroll: true });
        expect(wrapper.findComponent(MergeModal).props('open')).toBe(false);
    });
    it('gaveta mostra destino da mesclagem somente quando informado', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async (url) =>
                reply({
                    data: String(url).includes('clinical-safety') ? null : { ...patient, merged_into_code: 'PAC-99' },
                }),
            ),
        );
        wrapper = mount(PatientDrawer, {
            props: { open: false, patientId: 'source' },
            global: { stubs: { Teleport: true } },
        });
        await wrapper.setProps({ open: true });
        await flushPromises();
        expect(wrapper.get('[data-testid="patient-merged-warning"]').text()).toBe('Mesclado em PAC-99');
        wrapper.vm.patient.merged_into_code = null;
        await flushPromises();
        expect(wrapper.find('[data-testid="patient-merged-warning"]').exists()).toBe(false);
    });
});
