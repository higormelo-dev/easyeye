import { describe, expect, it, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import CatalogFormModal from '@/Pages/Panel/Settings/Catalog/CatalogFormModal.vue';
vi.mock('@/Components/Panel/SearchSelect.vue', () => ({ default: true }));
describe('CatalogFormModal dates', () => {
    it('renderiza date e normaliza o valor de edição para Y-m-d', async () => {
        global.fetch = vi.fn().mockResolvedValue({ json: async () => ({ data: { starts: '2030-01-02T12:00:00Z' } }) });
        const wrapper = mount(CatalogFormModal, {
            props: {
                open: false,
                itemId: '1',
                fields: [{ key: 'starts', label: 'Start', type: 'date' }],
                crudFields: { starts: null },
                storeUrl: '/store',
                urlTemplates: { show: '/__ID__', update: '/__ID__/update' },
                t: {},
            },
        });
        await wrapper.setProps({ open: true });
        await flushPromises();
        expect(wrapper.get('input[type="date"]').element.value).toBe('2030-01-02');
    });
});
