import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';

let wrapper;
afterEach(() => wrapper?.unmount());
async function render(confirmClose) {
    wrapper = mount(OffcanvasPanel, {
        props: { open: false, confirmClose },
        slots: { header: 'Cadastro sintético', default: '<input aria-label="Campo" />' },
        global: { stubs: { teleport: true } },
        attachTo: document.body,
    });
    await wrapper.setProps({ open: true });
    await flushPromises();
}
describe('OffcanvasPanel — confirmação em todas as saídas', () => {
    it.each(['backdrop', 'wrapper', 'close', 'escape'])('veto em %s preserva o diálogo e o foco', async (exit) => {
        const guard = vi.fn(() => false);
        await render(guard);
        if (exit === 'backdrop') await wrapper.get('.ee-modal__backdrop').trigger('click');
        if (exit === 'wrapper') await wrapper.get('.ee-modal__wrap').trigger('click');
        if (exit === 'close') await wrapper.get('.btn-close').trigger('click');
        if (exit === 'escape') window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        expect(guard).toHaveBeenCalledOnce();
        expect(wrapper.emitted('close')).toBeUndefined();
        expect(wrapper.get('[role="dialog"]').element.contains(document.activeElement)).toBe(true);
    });
    it('default preserva close; guard aceito é chamado; exceção mantém aberto', async () => {
        await render(null);
        await wrapper.get('.btn-close').trigger('click');
        const guard = vi.fn(() => true);
        await wrapper.setProps({ confirmClose: guard });
        await wrapper.get('.ee-modal__backdrop').trigger('click');
        expect(guard).toHaveBeenCalledOnce();
        expect(wrapper.emitted('close')).toHaveLength(2);
        await wrapper.setProps({
            confirmClose: () => {
                throw new Error('synthetic');
            },
        });
        await wrapper.get('.btn-close').trigger('click');
        expect(wrapper.emitted('close')).toHaveLength(2);
    });
});
