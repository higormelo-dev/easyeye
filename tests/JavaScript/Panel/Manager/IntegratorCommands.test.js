import { describe, it, expect, vi, beforeEach } from 'vitest';
import { shallowMount, flushPromises } from '@vue/test-utils';
import Index from '@/Pages/Panel/Manager/IntegratorCommands/Index.vue';
const { reload } = vi.hoisted(() => ({ reload: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({ Head: { template: '<span />' }, router: { reload } }));
function render(commands = []) {
    return shallowMount(Index, {
        props: {
            entityId: 'clinic',
            userIntegrator: 'actor',
            integrator: { id: 'device', name: 'Synthetic integrator' },
            allowedTypes: ['run_diagnostics', 'resync_now', 'reload_config'],
            commands,
        },
        global: { renderStubDefaultSlot: true },
    });
}
beforeEach(() => {
    vi.clearAllMocks();
    window.axios = { post: vi.fn().mockResolvedValue({ status: 201 }) };
});
describe('Manager command workflow', () => {
    it('sends a selected bounded command and refreshes the resulting backlog', async () => {
        const wrapper = render();
        await wrapper.get('select').setValue('resync_now');
        await wrapper.findAll('button')[0].trigger('click');
        await flushPromises();
        expect(window.axios.post).toHaveBeenCalledWith(
            '/panel/manager/entities/clinic/user-integrators/actor/integrators/device/commands',
            { type: 'resync_now', timeout_minutes: 15 },
        );
        expect(reload).toHaveBeenCalledOnce();
        expect(wrapper.text()).toContain('expiram em 15 minutos');
    });
    it('shows expiration, typed diagnostics and explicitly truncated devices', () => {
        const wrapper = render([
            {
                id: 'command',
                type: 'run_diagnostics',
                status: 'expired',
                expires_at: '2026-10-02T13:00:00Z',
                result: {
                    app_version: '1.0.0',
                    pending: 2,
                    devices: [{ equipment_id: 3, issue_code: 'emr_invalid_structure' }],
                    total_devices: 40,
                    devices_truncated: true,
                },
            },
        ]);
        expect(wrapper.text()).toContain('Expirado');
        expect(wrapper.text()).toContain('2 pendentes');
        expect(wrapper.text()).toContain('emr_invalid_structure');
        expect(wrapper.text()).toContain('parte de 40 aparelhos');
    });
    it('keeps a failed request visible and permits retry', async () => {
        window.axios.post.mockRejectedValue({ response: { data: { message: 'Limite de comandos pendentes.' } } });
        const wrapper = render();
        await wrapper.findAll('button')[0].trigger('click');
        await flushPromises();
        expect(wrapper.get('[role=alert]').text()).toContain('Limite de comandos');
        expect(wrapper.findAll('button')[0].attributes('disabled')).toBeUndefined();
        expect(reload).not.toHaveBeenCalled();
    });
});
