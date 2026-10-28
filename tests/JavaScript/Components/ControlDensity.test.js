import { describe, it, expect, afterEach } from 'vitest';
import { readFileSync } from 'node:fs';
import { defineComponent } from 'vue';
import { mount, flushPromises } from '@vue/test-utils';
import { resolve } from 'node:path';
import { compile } from 'sass';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';
import ActionDropdown from '@/Components/Panel/ActionDropdown.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';

/**
 * Densidade dos controles (design-system/MASTER.md §1 e §6).
 *
 * O layout real (alturas renderizadas) só se prova num navegador; aqui ficam o contrato do que é
 * código: o modal teleportado leva a região até o seu conteúdo, e os tokens do SCSS mantêm a conta
 * que faz campo, select, botão e paginação fecharem na MESMA altura.
 */
let wrapper;

afterEach(() => wrapper?.unmount());

describe.each([
    ['CenteredModal', CenteredModal],
    ['OffcanvasPanel', OffcanvasPanel],
])('%s (região de densidade)', (_name, Component) => {
    it('sem density não cria região: o modal segue com as medidas de sempre', () => {
        wrapper = mount(Component, { attachTo: document.body, props: { open: true } });

        expect(document.querySelector('[role="dialog"]').hasAttribute('data-ee-density')).toBe(false);
    });

    it.each(['compact', 'default', 'comfortable'])('density="%s" marca o diálogo como região', (density) => {
        wrapper = mount(Component, { attachTo: document.body, props: { open: true, density } });

        expect(document.querySelector('[role="dialog"]').getAttribute('data-ee-density')).toBe(density);
    });
});

describe('componentes compartilhados do lote 1', () => {
    it('PageHeader preserva h4 por padrao e permite h1 com a mesma aparencia', async () => {
        wrapper = mount(PageHeader, { props: { title: 'Título' } });
        const classes = wrapper.get('h4').attributes('class');
        await wrapper.setProps({ titleTag: 'h1' });
        expect(wrapper.get('h1').text()).toBe('Título');
        expect(wrapper.get('h1').attributes('class')).toBe(classes);
    });
    it('menu teleportado copia o ancestral mais proximo em cada abertura e remove fora de regiao', async () => {
        const Host = defineComponent({
            components: { ActionDropdown },
            template:
                '<div data-ee-density="comfortable"><div data-test="region" data-ee-density="compact"><ActionDropdown title="Ações"><li><button class="dropdown-item">Editar</button></li></ActionDropdown></div></div>',
        });
        wrapper = mount(Host, { attachTo: document.body });
        const trigger = wrapper.get('button');
        await trigger.trigger('click');
        await flushPromises();
        expect(document.body.querySelector('.dropdown-menu').getAttribute('data-ee-density')).toBe('compact');
        await trigger.trigger('click');
        wrapper.get('[data-test="region"]').element.setAttribute('data-ee-density', 'default');
        await trigger.trigger('click');
        await flushPromises();
        expect(document.body.querySelector('.dropdown-menu').getAttribute('data-ee-density')).toBe('default');
        await trigger.trigger('click');
        wrapper.get('[data-test="region"]').element.removeAttribute('data-ee-density');
        wrapper.element.removeAttribute('data-ee-density');
        await trigger.trigger('click');
        await flushPromises();
        expect(document.body.querySelector('.dropdown-menu').hasAttribute('data-ee-density')).toBe(false);
    });
    it.each(['CenteredModal', 'OffcanvasPanel'])(
        '%s reduz movimento apenas em regiao e preserva o estado visivel',
        (name) => {
            const source = readFileSync(resolve(process.cwd(), `resources/js/Components/Panel/${name}.vue`), 'utf8');
            const media = source.slice(source.indexOf('@media (prefers-reduced-motion: reduce)'));
            expect(source).toContain('@media (prefers-reduced-motion: reduce)');
            expect(media).toContain('transition: none');
            expect(media).toContain('opacity: 1');
            expect(media).toContain('transform: none');
            const lists = [...media.matchAll(/([^{}]+)\{/g)]
                .map((match) => match[1].trim())
                .filter((selector) => !selector.startsWith('@media'));
            for (const list of lists)
                for (const selector of list.split(',')) expect(selector).toContain('[data-ee-density]');
            expect(source).not.toContain('transition: all');
            expect(source).toContain('opacity 0.2s');
        },
    );
});

describe('_control-tokens.scss', () => {
    // Vitest roda da raiz do projeto (import.meta.url não é um file: URL no happy-dom).
    const source = resolve(process.cwd(), 'resources/css/system/_control-tokens.scss');
    // Sem aspas nem quebras: o Sass pode emitir [data-ee-density="compact"] ou [data-ee-density=compact].
    const css = compile(source).css.replace(/"/g, '').replace(/\s+/g, ' ');

    // Declarações do PRIMEIRO bloco com exatamente esse seletor (os de tokens vêm antes das regras).
    function tokens(selector, from = 0) {
        const start = css.indexOf(`${selector} {`, from);
        expect(start, `bloco ${selector}`).toBeGreaterThanOrEqual(0);
        const body = css.slice(start + selector.length + 3, css.indexOf('}', start));

        return Object.fromEntries(
            body
                .split(';')
                .map((d) => d.split(/:(.*)/s))
                .filter(([name]) => name.trim().startsWith('--'))
                .map(([name, value]) => [name.trim(), value.trim()]),
        );
    }

    const px = (rem) => parseFloat(rem) * 16;

    it.each([
        ['[data-ee-density=compact]', 32],
        ['[data-ee-density=comfortable]', 44],
    ])('%s: altura mínima = linha + 2 × padding vertical + 2 × borda de 1px (%d px)', (selector, height) => {
        const t = tokens(selector);

        expect(px(t['--ee-control-min-height'])).toBe(height);
        expect(px(t['--ee-control-line-height']) + 2 * px(t['--ee-control-padding-y']) + 2).toBe(height);
    });

    it('qualquer data-ee-density (inclusive valor desconhecido) cai no padrão de 40px', () => {
        const t = tokens('[data-ee-density]');

        expect(px(t['--ee-control-min-height'])).toBe(40);
        expect(px(t['--ee-control-line-height']) + 2 * px(t['--ee-control-padding-y']) + 2).toBe(40);
        expect(px(t['--ee-control-radius'])).toBe(8);
        expect(px(t['--ee-surface-radius'])).toBe(12);
        expect(px(t['--ee-control-gap'])).toBe(8);
    });

    it('toque ou tela pequena força 44px e fonte de 16px em toda região, inclusive a compacta', () => {
        const media = css.indexOf('@media (pointer: coarse), (max-width: 575.98px)');
        const t = tokens('[data-ee-density]', media);

        expect(media).toBeGreaterThanOrEqual(0);
        expect(px(t['--ee-control-min-height'])).toBe(44);
        expect(px(t['--ee-control-font-size'])).toBe(16);
    });

    it.each(['nav-tabs', 'nav-pills'])('%s de página segue altura, fonte e padding da região', (variant) => {
        const selector = `[data-ee-density] .${variant} .nav-link`;
        const rule = [...css.matchAll(/([^{}]+)\{([^{}]*)\}/g)].find((match) =>
            splitTopLevel(match[1]).includes(selector),
        )?.[2];
        expect(rule, `regra de página: ${selector}`).toBeDefined();
        expect(rule).toContain('min-height: var(--ee-control-min-height)');
        expect(rule).toContain('padding: var(--ee-control-padding-y) var(--ee-control-padding-x)');
        expect(rule).toContain('font-size: var(--ee-control-font-size)');
        expect(rule).toContain('line-height: var(--ee-control-line-height)');
        // Os tokens já provam 32/40/44px e 44px no toque; as abas usam a mesma conta.
        expect(css).toContain('[data-ee-density] .ee-modal__tabs .nav-tabs .nav-link');
    });

    it('os tokens só existem dentro de regiões: nada em :root muda o painel que não migrou', () => {
        expect(css).not.toMatch(/:root/);
    });

    it('a região vence as variantes legadas -sm sem !important', () => {
        expect(css).toContain('[data-ee-density] .input-group-sm > .form-control');
        expect(css).toContain('[data-ee-density] select.form-control-sm:not([size]):not([multiple])');
        expect(css).toContain('[data-ee-density] .search-select.multiselect');
        expect(css).not.toContain('!important');
    });

    // Quebra uma lista de seletores nas vírgulas de fora de parênteses (`:not(.a, .b)` fica inteiro).
    function splitTopLevel(list) {
        const parts = [];
        let depth = 0;
        let current = '';

        for (const char of list) {
            if (char === '(') depth++;
            if (char === ')') depth--;
            if (char === ',' && depth === 0) {
                parts.push(current.trim());
                current = '';
            } else {
                current += char;
            }
        }

        return [...parts, current.trim()].filter(Boolean);
    }

    it('todo seletor emitido é escopado em [data-ee-density]: fora de região nada muda', () => {
        const lists = [...css.matchAll(/([^{}]+)\{/g)]
            .map((m) => m[1].trim())
            .filter((s) => s && !s.startsWith('@media'));
        const selectors = lists.flatMap(splitTopLevel);

        expect(selectors.length).toBeGreaterThan(25);
        for (const selector of selectors) {
            expect(selector, `seletor fora de região: ${selector}`).toMatch(/^(:where\()?\[data-ee-density/);
        }
    });

    it.each([
        ['[data-ee-density=compact]', 12, 44],
        ['[data-ee-density]', 16, 52],
        ['[data-ee-density=comfortable]', 16, 56],
    ])(
        '%s: tabela segue padding horizontal %d e referencia de linha %d sem altura rigida',
        (selector, paddingX, rowHeight) => {
            const t = tokens(selector);
            expect(px(t['--ee-table-padding-x'])).toBe(paddingX);
            // Celula Bootstrap tem uma borda inferior de 1px; conteudo multilinha pode crescer.
            expect(px(t['--ee-control-line-height']) + 2 * px(t['--ee-table-padding-y']) + 1).toBe(rowHeight);
            const start = css.indexOf('[data-ee-density] .table > :not(caption) > * > *');
            const rule = css.slice(start, css.indexOf('}', start));
            expect(start).toBeGreaterThanOrEqual(0);
            expect(rule).toContain('var(--ee-table-padding-x)');
            expect(rule).toContain('var(--ee-table-padding-y)');
            expect(rule).not.toMatch(/(?:^|[;{])\s*height:/);
        },
    );
    it('checkbox/radio tem indicador 18-20px e linha/label clicavel pela densidade', () => {
        expect(px(tokens('[data-ee-density]')['--ee-choice-size'])).toBe(18);
        const media = css.indexOf('@media (pointer: coarse), (max-width: 575.98px)');
        expect(px(tokens('[data-ee-density]', media)['--ee-choice-size'])).toBe(20);
        for (const selector of ['.form-check', '.form-check-label']) {
            const start = css.indexOf(`[data-ee-density] ${selector} {`);
            expect(start).toBeGreaterThanOrEqual(0);
            expect(css.slice(start, css.indexOf('}', start))).toContain('min-height: var(--ee-control-min-height)');
        }
        expect(css).toContain('[data-ee-density] .form-switch .form-check-input');
    });
    it('itens de dropdown e numeros tabulares ficam apenas na regiao', () => {
        const start = css.indexOf('[data-ee-density] .dropdown-item');
        expect(start).toBeGreaterThanOrEqual(0);
        expect(css.slice(start, css.indexOf('}', start))).toContain('min-height: var(--ee-control-min-height)');
        expect(css).toContain('[data-ee-density] .ee-tabular { font-variant-numeric: tabular-nums; }');
    });

    it('campo inválido ou válido reserva o lado direito para o ícone do Bootstrap', () => {
        // Sem isto o padding da região (12px) substitui os 33px do .is-invalid e o texto passa sob o ícone.
        const start = css.indexOf('[data-ee-density] .form-control.is-invalid');
        const rule = css.slice(start, css.indexOf('}', start));

        expect(start).toBeGreaterThanOrEqual(0);
        expect(rule).toContain('.form-control.is-valid');
        expect(rule).toContain('padding-right: calc(1.5em + var(--ee-control-padding-x))');
    });

    it('o raio do botão tem especificidade menor que os cantos internos do .btn-group do Bootstrap', () => {
        // Com o prefixo comum (0,3,0) o raio de 8px vencia o btn-group e arredondava os cantos de dentro.
        const buttons = '.btn:not(.btn-xs, .btn-link, .btn-icon, .btn-close)';
        const main = css.indexOf(`[data-ee-density] ${buttons} {`);
        const mainBody = css.slice(main, css.indexOf('}', main));

        expect(css).toContain(`:where([data-ee-density]) ${buttons} { border-radius: var(--ee-control-radius); }`);
        expect(main).toBeGreaterThanOrEqual(0);
        expect(mainBody).not.toContain('border-radius');
    });
});
