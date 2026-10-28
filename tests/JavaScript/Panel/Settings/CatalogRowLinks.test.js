import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import CatalogTable from '@/Pages/Panel/Settings/Catalog/CatalogTable.vue';

/**
 * Ações extras por linha do catálogo (BaseSettingController::getRowLinks):
 * em Configurações › Recursos, "Escala e bloqueios" leva à tela da sala/
 * equipamento. Só para registro da clínica (mode full) — excluído não tem.
 */
vi.mock('@/Components/Panel/ActionDropdown.vue', () => ({
    default: { template: '<div class="dd"><slot name="trigger" /><slot /></div>' },
}));
vi.mock('@/Components/Panel/ActionIconButton.vue', () => ({
    default: { props: ['title', 'icon'], emits: ['click'], template: '<button type="button" :title="title" />' },
}));
vi.mock('@/Components/Panel/ActionIconGroup.vue', () => ({ default: { template: '<div><slot /></div>' } }));

const rowLinks = [
    {
        key: 'work_schedule',
        label: 'Escala e bloqueios',
        icon: 'ti ti-calendar-time',
        url: '/panel/resources/__ID__/work-schedule',
    },
];

function mountTable(rows) {
    return mount(CatalogTable, {
        props: {
            items: { data: rows, total: rows.length, last_page: 1, current_page: 1, links: [] },
            columns: [{ key: 'name', label: 'Nome', type: 'text' }],
            t: {},
            storageKey: 'clinic-resources_view',
            rowLinks,
        },
    });
}

beforeEach(() => window.localStorage.clear());

describe('CatalogTable — links por linha', () => {
    it('recurso da clínica: "Escala e bloqueios" aponta para a tela do próprio recurso', () => {
        const link = mountTable([{ id: 'r1', name: 'SALA 1', active: true, mode: 'full' }]).find(
            '[data-testid="row-link-work_schedule"]',
        );

        expect(link.exists()).toBe(true);
        expect(link.attributes('href')).toBe('/panel/resources/r1/work-schedule');
        expect(link.text()).toContain('Escala e bloqueios');
    });

    it('registro excluído (restaurar) não mostra o link', () => {
        const wrapper = mountTable([{ id: 'r2', name: 'SALA 2', active: true, deleted: true, mode: 'restore' }]);

        expect(wrapper.find('[data-testid="row-link-work_schedule"]').exists()).toBe(false);
    });
});
