import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import PatientsIndex from '@/Pages/Panel/Patients/Index.vue';

/**
 * Deep-links da lista de pacientes: ?open=<id> abre o cadastro (Agenda e
 * "Ver" dos pacientes recentes do Dashboard) e ?new=1 abre o formulário vazio
 * ("Novo paciente" do Dashboard). O parâmetro sai da URL sem perder o modal.
 */

vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/Pages/Panel/Patients/PatientTable.vue', () => ({ default: { template: '<div />' } }));
vi.mock('@/Pages/Panel/Patients/PatientCards.vue', () => ({ default: { template: '<div />' } }));
vi.mock('@/Pages/Panel/Patients/PatientDetailDrawer.vue', () => ({ default: { template: '<div />' } }));
vi.mock('@/Pages/Panel/Patients/PatientFormModal.vue', () => ({
    default: { props: ['open', 'patientId'], template: '<div class="form-stub" :data-open="open" :data-id="patientId ?? \'\'" />' },
}));

const PROPS = { patients: { data: [], links: [], meta: {} } };
const mountAt = async (search) => {
    window.history.replaceState({}, '', `/panel/patients${search}`);
    const wrapper = mount(PatientsIndex, { props: PROPS, global: { mocks: { route: globalThis.route } } });
    await nextTick(); // o deep-link abre no onMounted

    return wrapper;
};

beforeEach(() => vi.mocked(router.get).mockClear());
afterEach(() => window.history.replaceState({}, '', '/'));

describe('Pacientes: deep-links', () => {
    it('?new=1 abre o formulário vazio e limpa o parâmetro mantendo os demais', async () => {
        const form = (await mountAt('?new=1&search=ana')).find('.form-stub');

        expect(form.attributes('data-open')).toBe('true');
        expect(form.attributes('data-id')).toBe('');
        expect(router.get).toHaveBeenCalledWith('/_routes/panel.patients.index', { search: 'ana' }, expect.objectContaining({ replace: true, preserveState: true }));
    });

    it('?open=<id> abre o cadastro do paciente', async () => {
        const form = (await mountAt('?open=p-1')).find('.form-stub');

        expect(form.attributes('data-open')).toBe('true');
        expect(form.attributes('data-id')).toBe('p-1');
    });

    it('sem parâmetro: nada abre e a URL não é tocada', async () => {
        const form = (await mountAt('')).find('.form-stub');

        expect(form.attributes('data-open')).toBe('false');
        expect(router.get).not.toHaveBeenCalled();
    });
});
