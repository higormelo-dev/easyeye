import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

/**
 * Condições crônicas na gaveta do cadastro (Fase 6): o cartão só existe para quem o servidor liberou (prop
 * `recallConditions`: médico com cadastro ativo, num plano com o recall). Sem a prop, a gaveta nem faz a requisição.
 */
vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const actual = await importOriginal();

    return {
        ...actual,
        usePage: () => ({ props: { locale: 'pt_BR', t_ui: {}, auth: { user: { preferences: {} } } } }),
        router: { post: vi.fn(), get: vi.fn(), visit: vi.fn(), reload: vi.fn(), on: vi.fn(() => () => {}) },
    };
});

const { default: PatientDetailDrawer } = await import('@/Pages/Panel/Patients/PatientDetailDrawer.vue');
const { default: conditionsT } = await import('../Recall/support/recall_conditions.json');

const route = vi.fn(
    (name, params) => `/_routes/${name}${params === undefined ? '' : `/${[].concat(params).join('/')}`}`,
);

let urls;
function serve() {
    urls = [];
    globalThis.fetch = vi.fn((url) => {
        urls.push(String(url));
        const body = String(url).includes('recall-conditions')
            ? {
                  data: {
                      patient_id: 'p-1',
                      today: '2026-01-15',
                      conditions: [],
                      history: [],
                      protocols: [],
                      eyes: [],
                      t: conditionsT,
                  },
              }
            : String(url).includes('clinical-safety')
              ? {
                    data: {
                        allergy_state: 'unknown',
                        denied: null,
                        allergies: [],
                        medications: [],
                        risk_alerts: [],
                        can_review: false,
                    },
                }
              : { data: { id: 'p-1', full_name: 'PACIENTE UM', code: 'PAC-1', active: true } };

        return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(body) });
    });
}

const mountDrawer = async (props = {}) => {
    const wrapper = mount(PatientDetailDrawer, {
        props: { open: false, patientId: 'p-1', ...props },
        global: { stubs: { teleport: true } },
    });
    await wrapper.setProps({ open: true });
    await flushPromises();

    return wrapper;
};

describe('Gaveta do paciente — condições crônicas', () => {
    beforeEach(() => {
        globalThis.route = route;
        serve();
    });

    it('a gaveta declara densidade padrão sem tamanho local nos botões', async () => {
        const wrapper = await mountDrawer();
        expect(wrapper.getComponent({ name: 'OffcanvasPanel' }).props('density')).toBe('default');
        expect(wrapper.html()).not.toMatch(/\bbtn-sm\b/);
        wrapper.unmount();
    });

    it('com a permissão do servidor: o cartão aparece e carrega as condições DO PACIENTE aberto', async () => {
        const wrapper = await mountDrawer({ recallConditions: true });

        expect(wrapper.find('[data-testid="patient-conditions"]').exists()).toBe(true);
        expect(urls).toContain('/_routes/panel.patients.recall-conditions.index/p-1');
        expect(wrapper.text()).toContain('PACIENTE UM');
    });

    it('sem a permissão (secretária, admin, plano sem recall): nenhum cartão e NENHUMA requisição de condições', async () => {
        const wrapper = await mountDrawer({ recallConditions: false });

        expect(wrapper.find('[data-testid="patient-conditions"]').exists()).toBe(false);
        expect(urls.filter((url) => url.includes('recall-conditions'))).toEqual([]);
        expect(wrapper.text()).toContain('PACIENTE UM');
    });

    it('o padrão da prop é fechado: quem não passa a prop não vê nem pede as condições', async () => {
        const wrapper = await mountDrawer();

        expect(wrapper.find('[data-testid="patient-conditions"]').exists()).toBe(false);
        expect(urls.some((url) => url.includes('recall-conditions'))).toBe(false);
    });
});
