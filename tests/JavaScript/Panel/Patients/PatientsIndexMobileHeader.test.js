import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import PatientsIndex from '@/Pages/Panel/Patients/Index.vue';

/**
 * Fase 2 / G5 — Lista de Pacientes no celular (390 px): o cabeçalho (título +
 * total + alternar tabela/cards + Importar + Novo paciente) era uma linha flex
 * SEM quebra e passava 118 px da tela (rolagem lateral da página inteira,
 * medida no Chrome). A linha e o grupo de ações precisam quebrar.
 */
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/Pages/Panel/Patients/PatientTable.vue', () => ({ default: { template: '<div />' } }));
vi.mock('@/Pages/Panel/Patients/PatientCards.vue', () => ({ default: { template: '<div />' } }));
vi.mock('@/Pages/Panel/Patients/PatientDetailDrawer.vue', () => ({ default: { template: '<div />' } }));
vi.mock('@/Pages/Panel/Patients/PatientFormModal.vue', () => ({ default: { template: '<div />' } }));

describe('Pacientes: cabeçalho sem rolagem lateral no celular', () => {
    it('a linha do cabeçalho e o grupo de ações quebram (flex-wrap); as ações continuam lá', () => {
        const wrapper = mount(PatientsIndex, {
            props: { patients: { data: [], links: [], total: 3 } },
            global: { mocks: { route: globalThis.route } },
        });

        const header = wrapper.find('[data-testid="patients-header"]');
        const actions = wrapper.find('[data-testid="patients-header-actions"]');

        expect(header.classes()).toEqual(expect.arrayContaining(['d-flex', 'flex-wrap']));
        expect(actions.classes()).toEqual(expect.arrayContaining(['d-flex', 'flex-wrap']));
        expect(actions.text()).toContain('Importar');
        expect(actions.text()).toContain('Novo paciente');
        expect(header.text()).toContain('Total: 3');
    });

    it('é uma região de densidade padrão: alternador, busca e botões seguem a densidade, sem medida local', () => {
        const wrapper = mount(PatientsIndex, {
            props: { patients: { data: [], links: [], total: 3 } },
            global: { mocks: { route: globalThis.route } },
        });
        const page = wrapper.get('.page-patients');

        expect(page.attributes('data-ee-density')).toBe('default');
        expect(page.find('.page-header-toggle').exists()).toBe(true);
        expect(page.html()).not.toMatch(/\b(btn-sm|btn-md|fs-13|input-group-sm)\b/);
    });
});
