import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import PatientsIndex from '@/Pages/Panel/Patients/Index.vue';

/**
 * "Restaurar" paciente excluído: a listagem mostrava a ação, mas chamava uma
 * rota inexistente (panel.patients.restore). Agora a rota existe (PATCH, mesma
 * permissão da exclusão) e a tela a chama com o id da linha.
 */
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/Pages/Panel/Patients/PatientTable.vue', () => ({
    default: { emits: ['restore'], template: '<button class="restore" @click="$emit(\'restore\', \'p-9\')" />' },
}));
vi.mock('@/Pages/Panel/Patients/PatientCards.vue', () => ({ default: { template: '<div />' } }));
vi.mock('@/Pages/Panel/Patients/PatientDetailDrawer.vue', () => ({ default: { template: '<div />' } }));
vi.mock('@/Pages/Panel/Patients/PatientFormModal.vue', () => ({ default: { template: '<div />' } }));

beforeEach(() => {
    vi.clearAllMocks();
    window.localStorage.clear();
    window.history.replaceState({}, '', '/panel/patients');
});

describe('Pacientes: restaurar excluído', () => {
    it('chama PATCH panel.patients.restore com o id da linha', async () => {
        const wrapper = mount(PatientsIndex, {
            props: { patients: { data: [], links: [], meta: {} } },
            global: { mocks: { route: globalThis.route } },
        });

        await wrapper.find('.restore').trigger('click');

        expect(globalThis.route).toHaveBeenCalledWith('panel.patients.restore', 'p-9');
        expect(router.patch).toHaveBeenCalledWith(
            '/_routes/panel.patients.restore/p-9',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
        expect(router.get).not.toHaveBeenCalledWith(
            expect.stringContaining('restore'),
            expect.anything(),
            expect.anything(),
        );
    });
});
