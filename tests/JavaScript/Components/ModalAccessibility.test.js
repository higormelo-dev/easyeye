import { describe, it, expect, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';

let wrapper;

afterEach(() => wrapper?.unmount());

describe.each([
    ['CenteredModal', CenteredModal],
    ['OffcanvasPanel', OffcanvasPanel],
])('%s (acessibilidade)', (_name, Component) => {
    it('diálogo nomeado pelo título do #header', () => {
        wrapper = mount(Component, {
            attachTo: document.body,
            props: { open: true },
            slots: { header: '<h5 class="mb-0">Excluir lançamento</h5>', default: '<p>Corpo</p>' },
        });

        const dialog = document.querySelector('[role="dialog"]');
        const title = document.getElementById(dialog.getAttribute('aria-labelledby'));

        expect(dialog.getAttribute('aria-modal')).toBe('true');
        expect(title.textContent).toContain('Excluir lançamento');
    });

    it('botão fechar com rótulo (padrão do idioma ou o informado) e emite close', async () => {
        wrapper = mount(Component, {
            attachTo: document.body,
            props: { open: true, closeLabel: 'Close dialog' },
            slots: { header: '<h5>Título</h5>' },
        });

        const close = document.querySelector('.btn-close');
        expect(close.getAttribute('aria-label')).toBe('Close dialog');

        close.click();
        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('sem closeLabel usa o texto padrão (nunca botão mudo)', () => {
        wrapper = mount(Component, { attachTo: document.body, props: { open: true } });

        expect(document.querySelector('.btn-close').getAttribute('aria-label')).toBeTruthy();
        // Sem #header não aponta aria-labelledby para um elemento vazio.
        expect(document.querySelector('[role="dialog"]').hasAttribute('aria-labelledby')).toBe(false);
    });
});

describe.each([
    ['CenteredModal', CenteredModal],
    ['OffcanvasPanel', OffcanvasPanel],
])('%s (retorno de foco)', (_name, Component) => {
    it('ao fechar, devolve o foco a quem abriu', async () => {
        const opener = document.createElement('button');
        document.body.appendChild(opener);
        opener.focus();

        wrapper = mount(Component, {
            attachTo: document.body,
            props: { open: false },
            slots: { header: '<h5>T</h5>' },
        });
        await wrapper.setProps({ open: true });
        document.querySelector('.btn-close').focus();

        await wrapper.setProps({ open: false });
        document.activeElement.blur();
        await new Promise((resolve) => setTimeout(resolve));

        expect(document.activeElement).toBe(opener);
        opener.remove();
    });

    it('não rouba o foco se a página já o moveu para outro lugar', async () => {
        const opener = document.createElement('button');
        const other = document.createElement('input');
        document.body.append(opener, other);
        opener.focus();

        wrapper = mount(Component, { attachTo: document.body, props: { open: false } });
        await wrapper.setProps({ open: true });
        await wrapper.setProps({ open: false });
        other.focus();
        await new Promise((resolve) => setTimeout(resolve));

        expect(document.activeElement).toBe(other);
        opener.remove();
        other.remove();
    });
});
