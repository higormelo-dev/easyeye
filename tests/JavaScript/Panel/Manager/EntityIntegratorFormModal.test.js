import { describe, it, expect, vi, afterEach } from 'vitest';
import { shallowMount, flushPromises } from '@vue/test-utils';
import Modal from '@/Pages/Panel/Manager/EntityIntegrators/EntityIntegratorFormModal.vue';

afterEach(() => vi.unstubAllGlobals());

async function open(data) {
    const fetch = vi.fn().mockResolvedValue({ ok: true, status: 200, json: async () => ({ data }) });
    vi.stubGlobal('fetch', fetch);
    const wrapper = shallowMount(Modal, {
        props: {
            open: false,
            entityId: 'clinic',
            userIntegratorId: 'actor',
            itemId: 'device',
            editDataUrl: '/edit',
            updateUrl: '/update',
        },
    });
    await wrapper.setProps({ open: true });
    await flushPromises();
    return { wrapper, fetch };
}

describe('Manager integrator authorization hydration', () => {
    it.each(['support', 'worklist'])('preserves %s and pilot cohort on a name-only edit', async (profile) => {
        const original = {
            name: 'Original',
            ip: '192.0.2.1',
            mac: '00:11:22:33:44:55',
            active: true,
            token_profile: profile,
            update_channel: 'pilot',
            update_cohort: 'win7-pilot',
        };
        const { wrapper, fetch } = await open(original);
        expect(wrapper.findAll('select').map((select) => select.element.value)).toEqual([profile, 'pilot']);
        await wrapper.get('input[maxlength="100"]').setValue('Renamed');
        await wrapper.get('button.btn-primary').trigger('click');
        await flushPromises();
        expect(JSON.parse(fetch.mock.calls[1][1].body)).toEqual({ ...original, name: 'Renamed', _method: 'PATCH' });
    });

    it('blocks saving incomplete edit authorization rather than substituting create defaults', async () => {
        const { wrapper, fetch } = await open({
            name: 'Original',
            ip: '192.0.2.1',
            mac: '00:11:22:33:44:55',
            active: true,
        });
        expect(wrapper.text()).toContain('Erro ao carregar dados');
        expect(wrapper.get('button.btn-primary').attributes('disabled')).toBeDefined();
        await wrapper.get('button.btn-primary').trigger('click');
        expect(fetch).toHaveBeenCalledOnce();
    });
});
