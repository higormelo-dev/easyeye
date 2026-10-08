import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { reactive } from 'vue';

/**
 * Aba Clínico do cadastro (Pacientes e Agenda) — Fase 2 / A1-A2: paciente
 * novo leva alergias/medicações junto com o cadastro; paciente existente grava
 * cada item na hora, carregando a situação só ao abrir a aba (leitura de dado
 * sensível fica registrada no servidor).
 */
vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }) }));

const PatientFormSections = (await import('@/Pages/Panel/Patients/PatientFormSections.vue')).default;
const PatientAllergiesPanel = (await import('@/Components/Panel/ClinicalSafety/PatientAllergiesPanel.vue')).default;

function mountSection({ patientId = null, section = 'clinical' } = {}) {
    const form = reactive({
        covenant_id: '',
        card_number: '',
        allergies: [],
        allergies_denied: false,
        medications: [],
        errors: {},
    });
    const wrapper = mount(PatientFormSections, {
        props: { form, section, patientId },
        global: { stubs: { SearchSelect: true }, directives: { mask: {} } },
    });
    return { wrapper, form };
}

describe('Cadastro de paciente — aba Clínico: alergias e medicações', () => {
    beforeEach(() => {
        globalThis.fetch = vi.fn(() =>
            Promise.resolve({
                ok: true,
                status: 200,
                json: () =>
                    Promise.resolve({
                        data: {
                            allergy_state: 'unknown',
                            allergies: [],
                            medications: [],
                            risk_alerts: [],
                            can_review: false,
                        },
                    }),
            }),
        );
    });

    it('paciente novo: a alergia vai para o formulário (enviada com o cadastro), sem chamar o servidor', async () => {
        const { wrapper, form } = mountSection();

        await wrapper.find('[data-testid="allergy-add"]').trigger('click');
        await wrapper.find('[data-testid="allergy-substance"]').setValue('Iodo');
        await wrapper.find('[data-testid="allergy-save"]').trigger('click');

        expect(form.allergies).toHaveLength(1);
        expect(form.allergies[0].substance).toBe('Iodo');
        expect(globalThis.fetch).not.toHaveBeenCalled();
    });

    it('paciente existente: carrega a situação só quando a aba Clínico abre', async () => {
        const { wrapper } = mountSection({ patientId: 'p1', section: 'personal' });
        await flushPromises();
        expect(globalThis.fetch).not.toHaveBeenCalled();

        await wrapper.setProps({ section: 'clinical' });
        await flushPromises();

        expect(globalThis.fetch).toHaveBeenCalledTimes(1);
        expect(globalThis.fetch.mock.calls[0][0]).toBe('/_routes/panel.patients.clinical-safety.show/p1');
        expect(wrapper.findComponent(PatientAllergiesPanel).props('mode')).toBe('live');
        expect(wrapper.text()).toContain('Você registra como "informada pelo paciente"');
    });

    it('trocar de paciente com a aba aberta: nada do anterior fica, e a resposta atrasada dele é descartada (2ª revisão, P1-B)', async () => {
        const pending = [];
        globalThis.fetch = vi.fn(
            (url) =>
                new Promise((resolve) =>
                    pending.push({
                        url: String(url),
                        respond: (name, ok = true) =>
                            resolve({
                                ok,
                                status: ok ? 200 : 500,
                                json: () =>
                                    Promise.resolve({
                                        data: {
                                            allergy_state: 'has',
                                            allergies: [
                                                {
                                                    id: `a-${name}`,
                                                    substance: `ALERGIA DE ${name}`,
                                                    status: 'confirmed',
                                                    category: 'medication',
                                                },
                                            ],
                                            medications: [],
                                            risk_alerts: [],
                                            can_review: false,
                                        },
                                    }),
                            }),
                    }),
                ),
        );
        const { wrapper } = mountSection({ patientId: 'A' });
        await flushPromises();
        pending.find((p) => p.url.endsWith('/A')).respond('A');
        await flushPromises();
        expect(wrapper.text()).toContain('ALERGIA DE A');

        // Recarrega o A (ex.: reabriu) e troca para o B antes de responder.
        await wrapper.setProps({ patientId: 'A2' });
        await wrapper.setProps({ patientId: 'B' });
        expect(wrapper.text()).not.toContain('ALERGIA DE A');

        // A carga do B falha: a tela do B não pode mostrar o que era do A.
        pending.find((p) => p.url.endsWith('/B')).respond('B', false);
        await flushPromises();
        expect(wrapper.text()).not.toContain('ALERGIA DE A');

        // A resposta atrasada do A também não entra.
        pending.find((p) => p.url.endsWith('/A2')).respond('A');
        await flushPromises();
        expect(wrapper.text()).not.toContain('ALERGIA DE A');
    });

    it('carga inicial falhou: mostra aviso (não fica em branco pra sempre) e tenta de novo ao trocar de aba (18ª revisão Codex, P2)', async () => {
        globalThis.fetch = vi
            .fn()
            .mockResolvedValueOnce({ ok: false, status: 500, json: () => Promise.resolve({ message: 'Erro' }) })
            .mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: () =>
                    Promise.resolve({
                        data: {
                            allergy_state: 'has',
                            allergies: [{ id: 'a1', substance: 'Látex', status: 'confirmed', category: 'other' }],
                            medications: [],
                            risk_alerts: [],
                            can_review: false,
                        },
                    }),
            });
        const { wrapper } = mountSection({ patientId: 'p1', section: 'personal' });
        await flushPromises();

        await wrapper.setProps({ section: 'clinical' });
        await flushPromises();

        expect(globalThis.fetch).toHaveBeenCalledTimes(1);
        expect(wrapper.text()).toContain('Não foi possível carregar alergias e medicações.');

        // Trocou de aba e voltou: tenta de novo (sem isto, ficava em branco pra sempre).
        await wrapper.setProps({ section: 'personal' });
        await wrapper.setProps({ section: 'clinical' });
        await flushPromises();

        expect(globalThis.fetch).toHaveBeenCalledTimes(2);
        expect(wrapper.text()).toContain('Látex');
    });

    it('erro do servidor num item pendente (alergia ou medicação) aparece na linha certa, não só a aba marcada (10ª revisão Codex, P2)', async () => {
        const { wrapper, form } = mountSection();
        form.allergies = [{ _key: 'a1', substance: 'Dipirona' }];
        form.medications = [{ _key: 'm1', name: 'Secotex', started_on: '2099-01-01' }];
        form.errors = {
            'allergies.0.substance': 'Já existe uma alergia igual.',
            'medications.0.started_on': 'A data de início não pode ser no futuro.',
        };
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="allergy-pending-error"]').text()).toBe('Já existe uma alergia igual.');
        expect(wrapper.find('[data-testid="medication-pending-error"]').text()).toBe(
            'A data de início não pode ser no futuro.',
        );
    });

    it('erro de lista inteira (sem índice) aparece como aviso geral, não preso a um item (10ª revisão Codex, P2)', async () => {
        const { wrapper, form } = mountSection();
        form.medications = [{ _key: 'm1', name: 'Secotex' }];
        form.errors = { medications: 'Limite de 10 medicações excedido.' };
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="medications-list-error"]').text()).toBe('Limite de 10 medicações excedido.');
    });
});
