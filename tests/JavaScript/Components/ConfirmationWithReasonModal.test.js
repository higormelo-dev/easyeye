import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import ConfirmationWithReasonModal from '@/Components/Panel/ConfirmationWithReasonModal.vue';

function mountModal(props = {}) {
    return mount(ConfirmationWithReasonModal, {
        props: { open: true, title: 'Reabrir caixa', message: 'Reabre o período.', minLength: 10, ...props },
        attachTo: document.body,
    });
}

describe('ConfirmationWithReasonModal (acessibilidade)', () => {
    it('é um diálogo modal nomeado pelo título e descrito pelo aviso', () => {
        const wrapper = mountModal();
        const dialog  = wrapper.get('[role="dialog"]');

        expect(dialog.attributes('aria-modal')).toBe('true');
        expect(wrapper.get(`#${dialog.attributes('aria-labelledby')}`).text()).toContain('Reabrir caixa');
        expect(wrapper.get(`#${dialog.attributes('aria-describedby')}`).text()).toContain('Reabre o período.');
        wrapper.unmount();
    });

    it('rótulo associado ao campo, obrigatório e descrito pela dica e pelo contador', () => {
        const wrapper  = mountModal();
        const textarea = wrapper.get('textarea');

        expect(wrapper.get(`label[for="${textarea.attributes('id')}"]`).exists()).toBe(true);
        expect(textarea.attributes('aria-required')).toBe('true');
        expect(textarea.attributes('aria-describedby').split(' ')).toHaveLength(2);
        expect(wrapper.get('.btn-close').attributes('aria-label')).toBeTruthy();
        wrapper.unmount();
    });

    it('Esc fecha, exceto enquanto salva', async () => {
        const wrapper = mountModal();

        await wrapper.get('[role="dialog"]').trigger('keydown', { key: 'Escape' });
        expect(wrapper.emitted('close')).toHaveLength(1);

        await wrapper.setProps({ saving: true });
        await wrapper.get('[role="dialog"]').trigger('keydown', { key: 'Escape' });
        expect(wrapper.emitted('close')).toHaveLength(1);
        wrapper.unmount();
    });

    it('erro do servidor aparece dentro do modal com role=alert e ligado ao campo', () => {
        const wrapper  = mountModal({ error: 'Sem permissão para reabrir.' });
        const alert    = wrapper.get('[role="alert"]');
        const textarea = wrapper.get('textarea');

        expect(alert.text()).toContain('Sem permissão para reabrir.');
        expect(textarea.attributes('aria-describedby')).toContain(alert.attributes('id'));
        wrapper.unmount();
    });

    it('só confirma com o mínimo de caracteres e emite o motivo aparado', async () => {
        const wrapper = mountModal();
        const confirm = wrapper.findAll('.modal-footer button').at(-1);

        await wrapper.get('textarea').setValue('curto');
        expect(confirm.attributes('disabled')).toBeDefined();

        await wrapper.get('textarea').setValue('  motivo suficiente  ');
        await confirm.trigger('click');
        expect(wrapper.emitted('confirm').at(-1)).toEqual(['motivo suficiente']);
        wrapper.unmount();
    });
});

describe('ConfirmationWithReasonModal (foco)', () => {
    it('já aberto na montagem: campo limpo e focado', async () => {
        const wrapper = mountModal();
        await new Promise((resolve) => setTimeout(resolve));

        expect(document.activeElement).toBe(wrapper.get('textarea').element);
        wrapper.unmount();
    });

    it('ao fechar, devolve o foco a quem abriu', async () => {
        const opener = document.createElement('button');
        document.body.appendChild(opener);
        opener.focus();

        const wrapper = mountModal({ open: false });
        await wrapper.setProps({ open: true });
        await new Promise((resolve) => setTimeout(resolve));
        await wrapper.setProps({ open: false });
        await new Promise((resolve) => setTimeout(resolve));

        expect(document.activeElement).toBe(opener);
        wrapper.unmount();
        opener.remove();
    });
});
