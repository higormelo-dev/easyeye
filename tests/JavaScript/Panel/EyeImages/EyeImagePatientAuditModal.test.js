import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import AuditModal from '@/Pages/Panel/EyeImages/EyeImagePatientAuditModal.vue';
import { renderIndex } from './eyeImagesTestHelpers.js';
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /><slot name="top-actions" /></div>' } }));
const t = {
    audit_title: 'Histórico',
    audit_event_updated: 'Atualizado',
    audit_target_exam: 'Exame',
    audit_target_patient: 'Cadastro',
    audit_actor_system: 'Sistema',
    audit_actor_integrator: 'Integração',
    audit_value_hidden: 'valor não exibido',
    audit_empty: 'Nenhum evento',
    audit_error: 'Erro ao carregar',
    audit_load_more: 'Carregar mais',
    audit_value_yes: 'Sim',
    audit_value_no: 'Não',
    audit_value_empty: '—',
    close: 'Fechar',
};
const event = (extra = {}) => ({
    id: '1',
    at: '2026-10-18T14:00:00Z',
    event: 'updated',
    target: 'exam',
    target_code: 'EXM-1',
    actor_kind: 'system',
    actor_name: null,
    changes: [],
    ...extra,
});
const reply = (entries, current = 1, last = 1) => ({
    ok: true,
    json: async () => ({ entries, meta: { current_page: current, last_page: last } }),
});
let wrapper;
afterEach(() => {
    wrapper?.unmount();
    vi.unstubAllGlobals();
});
async function render(fetchMock, extra = {}) {
    vi.stubGlobal('fetch', fetchMock);
    wrapper = mount(AuditModal, {
        attachTo: document.body,
        props: {
            open: true,
            patient: { id: 'p1', code: 'PAC-1', full_name: 'Paciente' },
            url: '/patients/__ID__/audit',
            t,
            ...extra,
        },
        global: { stubs: { Teleport: true } },
    });
    await flushPromises();
    return wrapper;
}
describe('Histórico do paciente', () => {
    it('renderiza alvos, atores e updated vazio; nunca expõe valores redigidos', async () => {
        const fetchMock = vi.fn().mockResolvedValue(
            reply([
                event({
                    changes: [
                        { field: 'laterality', old: 1, new: 2, redacted: false },
                        { field: 'observation', old: 'SEGREDO', new: 'PRIVADO', redacted: true },
                    ],
                }),
                event({ id: '2', target: 'patient', target_code: 'PAC-1', actor_name: 'Dra Ana' }),
                event({
                    id: '3',
                    actor_kind: 'integrator',
                    changes: [{ field: 'urgent', old: false, new: true, redacted: false }],
                }),
            ]),
        );
        await render(fetchMock);
        expect(fetchMock.mock.calls[0][0]).toBe('/patients/p1/audit?page=1');
        expect(wrapper.findAll('[data-testid="patient-audit-entry"]')).toHaveLength(3);
        for (const text of ['1 → 2', 'valor não exibido', 'Sistema', 'Integração', 'Dra Ana', 'PAC-1', 'Não → Sim'])
            expect(wrapper.text()).toContain(text);
        expect(wrapper.text()).not.toMatch(/SEGREDO|PRIVADO/);
    });
    it('acrescenta próxima página sem duplicar carga e esconde botão no fim', async () => {
        let resolve;
        const fetchMock = vi
            .fn()
            .mockResolvedValueOnce(reply([event()], 1, 2))
            .mockImplementationOnce(
                () =>
                    new Promise((done) => {
                        resolve = done;
                    }),
            );
        await render(fetchMock);
        await wrapper.get('[data-testid="patient-audit-more"]').trigger('click');
        expect(wrapper.get('[data-testid="patient-audit-more"]').element.disabled).toBe(true);
        resolve(reply([event({ id: '2' })], 2, 2));
        await flushPromises();
        expect(fetchMock.mock.calls[1][0]).toBe('/patients/p1/audit?page=2');
        expect(wrapper.findAll('[data-testid="patient-audit-entry"]')).toHaveLength(2);
        expect(wrapper.find('[data-testid="patient-audit-more"]').exists()).toBe(false);
    });
    it('mostra vazio e erro HTTP traduzido, permitindo tentar novamente', async () => {
        const fetchMock = vi
            .fn()
            .mockResolvedValueOnce({ ok: false, json: async () => ({ message: 'Detalhe interno' }) })
            .mockResolvedValueOnce(reply([]));
        await render(fetchMock);
        expect(wrapper.get('[role="alert"]').text()).toBe('Erro ao carregar');
        await wrapper.get('[data-testid="patient-audit-more"]').trigger('click');
        await flushPromises();
        expect(wrapper.get('[data-testid="patient-audit-empty"]').text()).toBe('Nenhum evento');
    });
    it('ignora resposta atrasada ao trocar paciente ou fechar e Escape emite close', async () => {
        let resolve;
        const fetchMock = vi
            .fn()
            .mockImplementationOnce(
                () =>
                    new Promise((done) => {
                        resolve = done;
                    }),
            )
            .mockResolvedValueOnce(reply([event({ id: 'new', target_code: 'EXM-NOVO' })]));
        await render(fetchMock);
        await wrapper.setProps({ patient: { id: 'p2' } });
        await flushPromises();
        resolve(reply([event({ target_code: 'EXM-ANTIGO' })]));
        await flushPromises();
        expect(wrapper.text()).toContain('EXM-NOVO');
        expect(wrapper.text()).not.toContain('EXM-ANTIGO');
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await flushPromises();
        expect(wrapper.emitted('close')).toHaveLength(1);
        await wrapper.setProps({ open: false });
        expect(wrapper.find('[data-testid="patient-audit-entry"]').exists()).toBe(false);
    });
    it('botão do card só aparece com permissão e abre o paciente selecionado', async () => {
        const result = await renderIndex({ patients: [{ id: 'p1', full_name: 'Paciente', exams: [] }] });
        wrapper = result.wrapper;
        await wrapper.vm.selectPatient(wrapper.vm.patients[0]);
        await flushPromises();
        expect(wrapper.find('[data-testid="patient-audit-open"]').exists()).toBe(false);
        await wrapper.setProps({ can_view_patient_audit: true });
        await wrapper.get('[data-testid="patient-audit-open"]').trigger('click');
        expect(wrapper.vm.auditPatient.id).toBe('p1');
    });
});
