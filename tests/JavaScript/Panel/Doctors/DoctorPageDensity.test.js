import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import ImportPage from '@/Pages/Panel/Doctors/Import.vue';
import WorkSchedule from '@/Pages/Panel/Doctors/WorkSchedule.vue';
import DoctorDetailDrawer from '@/Pages/Panel/Doctors/DoctorDetailDrawer.vue';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';

vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<main><slot /></main>' } }));
vi.mock('@/composables/useImportProgress', () => ({ useImportProgress: () => ({ realtimeConnected: true }) }));
const wrappers = [];
afterEach(() => wrappers.splice(0).forEach((wrapper) => wrapper.unmount()));
const render = (component, props) => {
    const wrapper = mount(component, { props, global: { stubs: { Teleport: true } } });
    wrappers.push(wrapper);
    return wrapper;
};

describe('Densidade nas páginas restantes de médicos', () => {
    it('importação tem uma região e controles sem medida local', () => {
        const wrapper = render(ImportPage, { urls: { store: '/import', template: '/template', doctors: '/doctors' } });
        expect(wrapper.get('.container-fluid').attributes('data-ee-density')).toBe('default');
        expect(wrapper.get('button.btn-primary').classes()).not.toContain('btn-sm');
        expect(wrapper.html()).not.toMatch(/\b(btn-sm|form-control-sm)\b/);
    });
    it('escala mantém todos os controles na região, incluindo ações só com ícone', () => {
        const wrapper = render(WorkSchedule, {
            doctor: { id: 'd1', name: 'Médico sintético', color: '#123456' },
            days: [
                {
                    day: 1,
                    name: 'Segunda',
                    active: true,
                    ranges: [
                        { starts_at: '08:00', ends_at: '12:00' },
                        { starts_at: '13:00', ends_at: '17:00' },
                    ],
                },
            ],
            blocks: [{ id: 'b1', type: 'absence', starts_at: '2026-11-01 08:00', ends_at: '2026-11-01 12:00' }],
            urls: { back: '/doctors', sync: '/schedule', store_block: '/blocks', destroy_block: '/blocks' },
        });
        const region = wrapper.get('[data-ee-density="default"]');
        expect(region.findAll('input').length).toBeGreaterThan(0);
        expect(region.get('.card-footer button.btn-info').classes()).not.toContain('btn-sm');
        expect(wrapper.html()).not.toMatch(/\b(btn-sm|form-control-sm)\b/);
        const iconActions = region
            .findAll('button')
            .filter((button) => button.find('i').exists() && !button.text().trim());
        expect(iconActions).toHaveLength(4);
        for (const button of iconActions) {
            expect(button.classes()).toContain('btn-icon');
            expect(button.attributes('aria-label')).toBeTruthy();
        }
    });
    it('gaveta teleportada declara densidade padrão', () => {
        const wrapper = render(DoctorDetailDrawer, { open: true });
        expect(wrapper.getComponent(OffcanvasPanel).props('density')).toBe('default');
    });
});
