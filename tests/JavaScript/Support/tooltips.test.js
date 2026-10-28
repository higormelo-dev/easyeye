import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import Tooltip from 'bootstrap/js/dist/tooltip.js';
import { hideTooltip, installTooltips, uninstallTooltips } from '@/Support/tooltips.js';

/**
 * Tooltips do template em toda a SPA: sob demanda (hover/foco), um por vez,
 * descartados ao sair — com o Tooltip REAL do Bootstrap.
 */
const settle = () => new Promise((resolve) => setTimeout(resolve, 20));

function pointer(type, target, pointerType = 'mouse', relatedTarget = null) {
    const event = new Event(type, { bubbles: true, cancelable: true });
    Object.defineProperty(event, 'pointerType', { value: pointerType });
    Object.defineProperty(event, 'relatedTarget', { value: relatedTarget });
    target.dispatchEvent(event);
}

const balloon = () => document.querySelector('.tooltip');

let loadTooltip;

beforeEach(() => {
    document.body.innerHTML = `
        <button id="icon" title="Editar preço"><i class="ti ti-edit"></i></button>
        <button id="text" title="Salvar alterações">Salvar</button>
        <span id="plain">sem dica</span>
        <input id="field" title="Valor em USD" aria-describedby="field-hint" />
        <div class="tox"><button id="editor" title="Negrito">B</button></div>
        <button id="off" title="Nativo" data-tooltip="off">x</button>
        <button id="template" data-bs-toggle="tooltip" data-bs-title="Do template">t</button>
        <button id="xss" title="<img src=x onerror=alert(1)>">!</button>
        <button id="disabled" title="Prontuário assinado — não editável" disabled>Editar</button>
    `;
    document.documentElement.style.setProperty('--bs-body-font-family', 'Inter');
    loadTooltip = vi.fn(() => Promise.resolve(Tooltip));
    installTooltips({ loadTooltip, delay: 0, hideDelay: 0 });
});

afterEach(() => {
    uninstallTooltips();
    document.documentElement.style.removeProperty('--bs-body-font-family');
    document.body.innerHTML = '';
});

describe('tooltips do template (global, sob demanda)', () => {
    it('hover mostra o balão do Bootstrap e tira o title (sem o nativo duplicado)', async () => {
        const icon = document.getElementById('icon');

        pointer('pointerover', icon.querySelector('i'));
        await settle();

        expect(balloon()?.textContent).toBe('Editar preço');
        expect(icon.hasAttribute('title')).toBe(false);
        expect(icon.getAttribute('aria-describedby')).toBe(balloon().id);
    });

    it('sair devolve o elemento ao estado original (title, sem aria-label/atributos do Bootstrap)', async () => {
        const icon = document.getElementById('icon');

        pointer('pointerover', icon);
        await settle();
        expect(icon.getAttribute('aria-label')).toBe('Editar preço'); // botão só com ícone

        pointer('pointerover', document.getElementById('plain'));
        await settle();

        expect(balloon()).toBeNull();
        expect(icon.getAttribute('title')).toBe('Editar preço');
        expect(icon.hasAttribute('aria-label')).toBe(false);
        expect(icon.hasAttribute('aria-describedby')).toBe(false);
        expect(icon.hasAttribute('data-bs-original-title')).toBe(false);
    });

    it('um por vez: passar para outro elemento troca o balão', async () => {
        pointer('pointerover', document.getElementById('icon'));
        await settle();
        pointer('pointerover', document.getElementById('text'));
        await settle();

        expect(document.querySelectorAll('.tooltip')).toHaveLength(1);
        expect(balloon().textContent).toBe('Salvar alterações');
        expect(document.getElementById('icon').getAttribute('title')).toBe('Editar preço');
    });

    it('passagem rápida (antes do atraso) não abre nada', async () => {
        uninstallTooltips();
        installTooltips({ loadTooltip, delay: 50, hideDelay: 0 });

        pointer('pointerover', document.getElementById('icon'));
        pointer('pointerover', document.getElementById('plain'));
        await new Promise((resolve) => setTimeout(resolve, 80));

        expect(balloon()).toBeNull();
        expect(loadTooltip).not.toHaveBeenCalled();
    });

    it('toque e caneta não abrem (o balão ficaria preso) — fica o nativo', async () => {
        pointer('pointerover', document.getElementById('icon'), 'touch');
        pointer('pointerover', document.getElementById('text'), 'pen');
        await settle();

        expect(balloon()).toBeNull();
        expect(document.getElementById('icon').getAttribute('title')).toBe('Editar preço');
    });

    it('teclado: foco pelo Tab mostra na hora; Esc e sair do foco fecham; foco por clique não', async () => {
        const text = document.getElementById('text');

        pointer('pointerdown', text);
        text.dispatchEvent(new FocusEvent('focusin', { bubbles: true }));
        await settle();
        expect(balloon()).toBeNull();

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true }));
        text.dispatchEvent(new FocusEvent('focusin', { bubbles: true }));
        await settle();
        expect(balloon()?.textContent).toBe('Salvar alterações');

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        expect(balloon()).toBeNull();

        text.dispatchEvent(new FocusEvent('focusin', { bubbles: true }));
        await settle();
        text.dispatchEvent(new FocusEvent('focusout', { bubbles: true }));
        expect(balloon()).toBeNull();
    });

    it('clique, navegação da SPA e elemento removido pelo Vue fecham o balão', async () => {
        const icon = document.getElementById('icon');

        pointer('pointerover', icon);
        await settle();
        pointer('pointerdown', icon);
        expect(balloon()).toBeNull();

        pointer('pointerover', document.getElementById('plain'));
        pointer('pointerover', icon);
        await settle();
        document.dispatchEvent(new Event('inertia:start'));
        expect(balloon()).toBeNull();

        pointer('pointerover', document.getElementById('plain'));
        pointer('pointerover', icon);
        await settle();
        icon.remove();
        await settle();
        expect(balloon()).toBeNull();
    });

    it('title trocado pelo Vue com o balão aberto: atualiza o texto e devolve o novo', async () => {
        const icon = document.getElementById('icon');

        pointer('pointerover', icon);
        await settle();

        icon.title = 'Desativar'; // como o Vue faz no patch
        await settle();
        expect(balloon().textContent).toBe('Desativar');
        expect(icon.hasAttribute('title')).toBe(false);

        hideTooltip();
        expect(icon.getAttribute('title')).toBe('Desativar');
    });

    it('[a11y] descrição original do campo (aria-describedby) somada e devolvida', async () => {
        const field = document.getElementById('field');

        pointer('pointerover', field);
        await settle();
        expect(field.getAttribute('aria-describedby')).toBe(`field-hint ${balloon().id}`);

        hideTooltip();
        expect(field.getAttribute('aria-describedby')).toBe('field-hint');
    });

    it('[SEGURANÇA] title nunca vira HTML', async () => {
        pointer('pointerover', document.getElementById('xss'));
        await settle();

        expect(balloon().querySelector('img')).toBeNull();
        expect(balloon().textContent).toBe('<img src=x onerror=alert(1)>');
    });

    it('[WCAG 1.4.13] dá para levar o mouse até o balão sem ele sumir', async () => {
        uninstallTooltips();
        installTooltips({ loadTooltip, delay: 0, hideDelay: 60 });

        const icon = document.getElementById('icon');
        pointer('pointerover', icon);
        await settle();

        pointer('pointerover', document.getElementById('plain')); // atravessando o vão
        pointer('pointerover', balloon().querySelector('.tooltip-inner'));
        await new Promise((resolve) => setTimeout(resolve, 100));
        expect(balloon()?.textContent).toBe('Editar preço');

        pointer('pointerover', document.getElementById('plain'));
        await new Promise((resolve) => setTimeout(resolve, 100));
        expect(balloon()).toBeNull();
        expect(icon.getAttribute('title')).toBe('Editar preço');
    });

    it('posição pela janela (fixed): vale dentro de modal e não depende do CSS do <html>', async () => {
        pointer('pointerover', document.getElementById('text'));
        await settle();

        expect(balloon().style.position).toBe('fixed');
    });

    it('botão desabilitado mostra o motivo (title)', async () => {
        pointer('pointerover', document.getElementById('disabled'));
        await settle();

        expect(balloon()?.textContent).toBe('Prontuário assinado — não editável');
    });

    it('marcação do template (data-bs-toggle + data-bs-title) também funciona', async () => {
        pointer('pointerover', document.getElementById('template'));
        await settle();

        expect(balloon()?.textContent).toBe('Do template');
    });

    it('fora: editor rico (TinyMCE), data-tooltip="off" e página sem o CSS do Bootstrap', async () => {
        pointer('pointerover', document.getElementById('editor'));
        await settle();
        pointer('pointerover', document.getElementById('off'));
        await settle();
        expect(balloon()).toBeNull();

        uninstallTooltips();
        document.documentElement.style.removeProperty('--bs-body-font-family');
        installTooltips({ loadTooltip, delay: 0, hideDelay: 0 });

        pointer('pointerover', document.getElementById('icon'));
        await settle();
        expect(balloon()).toBeNull();
        expect(document.getElementById('icon').getAttribute('title')).toBe('Editar preço');
    });

    it('sob Cypress (E2E) não liga — os testes acham elementos pelo title', async () => {
        uninstallTooltips();
        window.Cypress = {};
        installTooltips({ loadTooltip, delay: 0, hideDelay: 0 });

        pointer('pointerover', document.getElementById('icon'));
        await settle();

        delete window.Cypress;
        expect(balloon()).toBeNull();
        expect(document.getElementById('icon').getAttribute('title')).toBe('Editar preço');
    });

    it('falha ao carregar o Bootstrap: fica o nativo e tenta de novo depois', async () => {
        uninstallTooltips();
        const flaky = vi.fn().mockRejectedValueOnce(new Error('chunk 404')).mockResolvedValue(Tooltip);
        installTooltips({ loadTooltip: flaky, delay: 0, hideDelay: 0 });

        pointer('pointerover', document.getElementById('icon'));
        await settle();
        expect(balloon()).toBeNull();
        expect(document.getElementById('icon').getAttribute('title')).toBe('Editar preço');

        pointer('pointerover', document.getElementById('plain'));
        pointer('pointerover', document.getElementById('icon'));
        await settle();
        expect(balloon()?.textContent).toBe('Editar preço');
        expect(flaky).toHaveBeenCalledTimes(2);
    });
});
