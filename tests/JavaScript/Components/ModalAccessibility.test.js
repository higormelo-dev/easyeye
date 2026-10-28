import { describe, it, expect, beforeEach, afterEach } from 'vitest';
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

    it('fechando com o foco ainda DENTRO do diálogo (a transição de saída mantém o elemento no DOM), devolve o foco a quem abriu', async () => {
        const opener = document.createElement('button');
        document.body.appendChild(opener);
        opener.focus();

        // Transição de verdade (o VTU a troca por um stub que remove na hora):
        // no navegador o diálogo fica no DOM por ~200 ms depois de fechar e o
        // foco continua no botão de dentro — sem tratar isso o foco "se perdia"
        // pro <body> quando o elemento enfim saía.
        wrapper = mount(Component, {
            attachTo: document.body,
            props: { open: false },
            slots: { header: '<h5>T</h5>' },
            global: { stubs: { transition: false } },
        });
        await wrapper.setProps({ open: true });
        await new Promise((resolve) => setTimeout(resolve));
        document.querySelector('.btn-close').focus();

        await wrapper.setProps({ open: false });
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

/**
 * EE-15 — CenteredModal/OffcanvasPanel declaravam aria-modal mas não
 * implementavam a interação de verdade: sem foco de entrada, sem Tab
 * contido, sem Escape, sem fundo inerte. Teclado dava pra continuar
 * operando o conteúdo atrás do diálogo. Contrato completo agora é
 * compartilhado via useModalA11y (resources/js/Support/useModalA11y.js).
 */
function pressKey(key, { shiftKey = false } = {}) {
    window.dispatchEvent(new KeyboardEvent('keydown', { key, shiftKey, bubbles: true }));
}

describe.each([
    ['CenteredModal', CenteredModal],
    ['OffcanvasPanel', OffcanvasPanel],
])('%s (foco de entrada e Tab contido)', (_name, Component) => {
    it('ao abrir, foco entra no diálogo (primeiro elemento focável = botão fechar)', async () => {
        wrapper = mount(Component, {
            attachTo: document.body,
            props: { open: false },
            slots: { header: '<h5>T</h5>', default: '<input id="first-field" />' },
        });
        await wrapper.setProps({ open: true });
        await new Promise((resolve) => setTimeout(resolve));

        expect(document.activeElement).toBe(document.querySelector('.btn-close'));
    });

    it('Tab no último elemento focável volta pro primeiro (não escapa pro resto da página)', async () => {
        wrapper = mount(Component, {
            attachTo: document.body,
            props: { open: false },
            slots: { header: '<h5>T</h5>', footer: '<button id="last-btn">Salvar</button>' },
        });
        await wrapper.setProps({ open: true });
        await new Promise((resolve) => setTimeout(resolve));

        document.getElementById('last-btn').focus();
        pressKey('Tab');

        expect(document.activeElement).toBe(document.querySelector('.btn-close'));
    });

    it('Shift+Tab no primeiro elemento focável volta pro último (contenção nos 2 sentidos)', async () => {
        wrapper = mount(Component, {
            attachTo: document.body,
            props: { open: false },
            slots: { header: '<h5>T</h5>', footer: '<button id="last-btn">Salvar</button>' },
        });
        await wrapper.setProps({ open: true });
        await new Promise((resolve) => setTimeout(resolve));

        document.querySelector('.btn-close').focus();
        pressKey('Tab', { shiftKey: true });

        expect(document.activeElement).toBe(document.getElementById('last-btn'));
    });
});

describe.each([
    ['CenteredModal', CenteredModal],
    ['OffcanvasPanel', OffcanvasPanel],
])('%s (Escape fecha)', (_name, Component) => {
    it('Escape emite close, de qualquer lugar da página (não só com foco dentro do diálogo)', async () => {
        wrapper = mount(Component, { attachTo: document.body, props: { open: false } });
        await wrapper.setProps({ open: true });
        await new Promise((resolve) => setTimeout(resolve));

        document.body.focus();
        pressKey('Escape');

        expect(wrapper.emitted('close')).toHaveLength(1);
    });
});

describe('Fundo inerte enquanto o diálogo está aberto', () => {
    let app;

    beforeEach(() => {
        app = document.createElement('div');
        app.id = 'app';
        document.body.appendChild(app);
    });

    afterEach(() => {
        wrapper?.unmount();
        app.remove();
    });

    it.each([
        ['CenteredModal', CenteredModal],
        ['OffcanvasPanel', OffcanvasPanel],
    ])('%s: liga inert no #app ao abrir, desliga ao fechar', async (_name, Component) => {
        wrapper = mount(Component, { attachTo: document.body, props: { open: false } });
        await wrapper.setProps({ open: true });
        expect(app.hasAttribute('inert')).toBe(true);

        await wrapper.setProps({ open: false });
        expect(app.hasAttribute('inert')).toBe(false);
    });
});

describe('Diálogos aninhados — só o do topo reage ao teclado', () => {
    it('Escape fecha só o diálogo de cima; fechar o de cima devolve o Escape pro de baixo', async () => {
        const bottom = mount(CenteredModal, {
            attachTo: document.body,
            props: { open: false },
            slots: { header: '<h5>De baixo</h5>' },
        });
        await bottom.setProps({ open: true });
        await new Promise((resolve) => setTimeout(resolve));

        const top = mount(OffcanvasPanel, {
            attachTo: document.body,
            props: { open: false },
            slots: { header: '<h5>De cima</h5>' },
        });
        await top.setProps({ open: true });
        await new Promise((resolve) => setTimeout(resolve));

        pressKey('Escape');
        expect(top.emitted('close')).toHaveLength(1);
        expect(bottom.emitted('close')).toBeFalsy();

        await top.setProps({ open: false });
        await new Promise((resolve) => setTimeout(resolve));

        pressKey('Escape');
        expect(bottom.emitted('close')).toHaveLength(1);

        top.unmount();
        bottom.unmount();
    });
});

describe('Exceção do editor TinyMCE (classe .tox) no trap de foco', () => {
    it('foco indo pra um elemento .tox (diálogo/menu próprio do TinyMCE) não é puxado de volta', async () => {
        const toxDialog = document.createElement('div');
        toxDialog.className = 'tox tox-dialog-wrap';
        const toxInput = document.createElement('input');
        toxDialog.appendChild(toxInput);
        document.body.appendChild(toxDialog);

        wrapper = mount(CenteredModal, {
            attachTo: document.body,
            props: { open: false },
            slots: { header: '<h5>T</h5>' },
        });
        await wrapper.setProps({ open: true });
        await new Promise((resolve) => setTimeout(resolve));

        toxInput.focus();
        await new Promise((resolve) => setTimeout(resolve));

        expect(document.activeElement).toBe(toxInput);
        toxDialog.remove();
    });
});
