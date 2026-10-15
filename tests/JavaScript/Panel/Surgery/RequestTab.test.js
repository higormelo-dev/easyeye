import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async () => (await import('./inertiaMock.js')).inertiaMock());

const RequestTab = (await import('@/Components/Surgery/Tabs/RequestTab.vue')).default;

const t = {
    ui: {
        request: {
            edit: 'Editar pedido',
            procedures: 'Procedimentos',
            procedures_empty: 'Nenhum procedimento informado.',
            col_tuss: 'TUSS',
            col_description: 'Descrição',
            col_requested: 'Pedido',
            col_authorized: 'Autorizado',
            materials: 'OPME',
            materials_empty: 'Nenhum material informado.',
            iol: 'LIO planejada',
            iol_empty: 'Nenhuma LIO planejada.',
            iol_not_calculated: 'O sistema não calcula a potência.',
            team: 'Equipe',
            team_empty: 'Equipe ainda não informada.',
            exams: 'Exames de apoio',
            exams_empty: 'Nenhum exame vinculado.',
            safety: 'Segurança clínica',
            safety_none: 'Retrato não registrado.',
            allergies: 'Alergias',
            allergies_denied: 'Paciente nega alergias.',
            allergies_unknown: 'Alergias não informadas — confirme antes da cirurgia.',
            medications: 'Medicações em uso',
            exam_role_biometry: 'Biometria',
        },
    },
};

const base = () => ({
    procedures: [
        { id: 'p1', tuss_code: '30306019', description: 'Facoemulsificação', quantity: 1, authorized_quantity: 1 },
    ],
    materials: [],
    iol_plans: [],
    exams: [],
    team: [],
    safety: { taken_at: null, allergies: null, medications: [], risk_alerts: [] },
    can_edit_request: false,
    can_edit_clinical: false,
    can_edit_team: false,
});

const surgicalCase = { needs_opme: false, cid_codes: [], clinical_indication: null };

function mountTab(section = {}) {
    return mount(RequestTab, { props: { section: { ...base(), ...section }, surgicalCase, can: {}, urls: {}, t } });
}

describe('RequestTab', () => {
    it('mostra o procedimento pedido com a quantidade autorizada ao lado', () => {
        const row = mountTab().find('[data-testid="request-procedure"]');

        expect(row.text()).toContain('30306019');
        expect(row.text()).toContain('Facoemulsificação');
        expect(row.findAll('td').at(3).text()).toBe('1');
    });

    it('avisa quando as alergias não foram informadas e destaca o risco ocular (IFIS)', () => {
        const wrapper = mountTab({
            safety: {
                taken_at: '2026-10-15T10:00:00-03:00',
                allergies: { state: 'unknown', items: [] },
                medications: [{ name: 'Tansulosina' }],
                risk_alerts: [
                    { key: 'ifis', alert: 'Risco de íris flácida intraoperatória', medications: ['Tansulosina'] },
                ],
            },
        });

        expect(wrapper.find('[data-testid="safety-allergies"]').text()).toContain('Alergias não informadas');
        expect(wrapper.find('[data-testid="safety-risk"]').text()).toContain('Risco de íris flácida');
        expect(wrapper.text()).toContain('Tansulosina');
    });

    it('lista as alergias do retrato quando existem', () => {
        const wrapper = mountTab({
            safety: {
                taken_at: null,
                allergies: { state: 'has', items: [{ substance: 'Dipirona', reaction: 'urticária' }] },
                medications: [],
                risk_alerts: [],
            },
        });

        expect(wrapper.find('[data-testid="safety-allergies"]').text()).toContain('Dipirona');
        expect(wrapper.find('[data-testid="safety-allergies"]').text()).toContain('urticária');
    });

    it('só oferece "Editar pedido" a quem pode e emite edit', async () => {
        expect(mountTab().find('[data-testid="request-edit"]').exists()).toBe(false);

        const wrapper = mountTab({ can_edit_request: true });
        await wrapper.find('[data-testid="request-edit"]').trigger('click');

        expect(wrapper.emitted('edit')).toHaveLength(1);
    });

    it('não calcula LIO: mostra o aviso e a refração prevista informada', () => {
        const wrapper = mountTab({
            iol_plans: [
                {
                    id: 'i1',
                    eye_label: 'OD',
                    model_label: 'LIO X',
                    power: '21.50',
                    formula: 'SRK/T',
                    target_refraction: '0.00',
                    predicted_refraction: '-0.25',
                },
            ],
        });

        expect(wrapper.find('[data-testid="request-iol"]').text()).toContain('21.50');
        expect(wrapper.text()).toContain('não calcula');
    });
});
