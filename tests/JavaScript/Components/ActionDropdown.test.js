import { describe, it, expect, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import ActionDropdown from '@/Components/Panel/ActionDropdown.vue';

/**
 * Menu teleportado para o fim do <body>: sem gestão de foco, quem usa teclado
 * abria o menu e nunca alcançava os itens (WCAG 2.1.1).
 */
let wrapper;

function mountMenu() {
    wrapper = mount(ActionDropdown, {
        attachTo: document.body,
        // Transição instantânea: no happy-dom a animação de saída nunca termina
        // e o menu fechado ficaria no DOM (o setup global troca os stubs padrão).
        global: { stubs: { transition: true } },
        props: { title: 'Mais ações' },
        slots: {
            default: `
                <li><button type="button" class="dropdown-item" data-test="edit">Editar</button></li>
                <li><button type="button" class="dropdown-item" disabled data-test="disabled">Indisponível</button></li>
                <li><hr class="dropdown-divider"></li>
                <li><a href="/x" class="dropdown-item" data-test="view">Ver</a></li>
                <li><button type="button" class="dropdown-item text-danger" data-test="delete">Excluir</button></li>
            `,
        },
    });

    return wrapper;
}

const trigger = () => wrapper.get('button[aria-haspopup="menu"]');
const menu = () => document.querySelector('.dropdown-menu');
const item = (name) => document.querySelector(`[data-test="${name}"]`);

async function keydown(key) {
    document.activeElement.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true }));
    await nextTick();
}

afterEach(() => wrapper?.unmount());

describe('ActionDropdown (teclado)', () => {
    it('aberto pelo mouse não move o foco', async () => {
        mountMenu();

        await trigger().trigger('click', { detail: 1 });

        expect(menu()).not.toBeNull();
        expect(trigger().attributes('aria-expanded')).toBe('true');
        expect(trigger().attributes('aria-controls')).toBe(menu().id);
        expect(document.activeElement).not.toBe(item('edit'));
    });

    it('aberto pelo teclado (Enter/Espaço) leva o foco ao 1º item', async () => {
        mountMenu();

        await trigger().trigger('click', { detail: 0 });
        await nextTick();

        expect(document.activeElement).toBe(item('edit'));
    });

    it('setas/Home/End navegam pulando itens desabilitados e divisores, com volta ao início', async () => {
        mountMenu();
        await trigger().trigger('click', { detail: 0 });
        await nextTick();

        await keydown('ArrowDown');
        expect(document.activeElement).toBe(item('view'));

        await keydown('ArrowDown');
        expect(document.activeElement).toBe(item('delete'));

        await keydown('ArrowDown');
        expect(document.activeElement).toBe(item('edit'));

        await keydown('ArrowUp');
        expect(document.activeElement).toBe(item('delete'));

        await keydown('Home');
        expect(document.activeElement).toBe(item('edit'));

        await keydown('End');
        expect(document.activeElement).toBe(item('delete'));
    });

    it('Esc fecha e devolve o foco ao botão', async () => {
        mountMenu();
        await trigger().trigger('click', { detail: 0 });
        await nextTick();

        await keydown('Escape');

        expect(menu()).toBeNull();
        expect(document.activeElement).toBe(trigger().element);
    });

    it('Tab fecha e devolve o foco ao botão (não se perde no fim da página)', async () => {
        mountMenu();
        await trigger().trigger('click', { detail: 0 });
        await nextTick();

        await keydown('Tab');

        expect(menu()).toBeNull();
        expect(document.activeElement).toBe(trigger().element);
    });

    it('seta para baixo no botão abre o menu já no 1º item', async () => {
        mountMenu();

        await trigger().trigger('keydown', { key: 'ArrowDown' });
        await nextTick();
        await nextTick();

        expect(menu()).not.toBeNull();
        expect(document.activeElement).toBe(item('edit'));
    });

    it('clicar num item fecha o menu (comportamento de antes)', async () => {
        mountMenu();
        await trigger().trigger('click', { detail: 1 });

        item('edit').click();
        await nextTick();

        expect(menu()).toBeNull();
    });
});

describe('ActionDropdown (menu com formulário e retorno de foco)', () => {
    function mountFormMenu() {
        wrapper = mount(ActionDropdown, {
            attachTo: document.body,
            global: { stubs: { transition: true } },
            props: { title: 'Exportar' },
            slots: {
                default: `
                    <li><input type="date" data-test="from"></li>
                    <li><button type="button" class="dropdown-item" data-test="export">Exportar</button></li>
                `,
            },
        });
    }

    it('aberto pelo teclado foca o 1º campo; setas e Tab ficam com o campo (menu não intercepta)', async () => {
        mountFormMenu();
        await trigger().trigger('click', { detail: 0 });
        await nextTick();

        expect(document.activeElement).toBe(item('from'));

        const arrow = new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true, cancelable: true });
        item('from').dispatchEvent(arrow);
        expect(arrow.defaultPrevented).toBe(false);

        item('export').focus();
        const tab = new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true });
        item('export').dispatchEvent(tab);
        await nextTick();

        expect(tab.defaultPrevented).toBe(false);
        expect(menu()).not.toBeNull();
    });

    it('item escolhido pelo teclado devolve o foco ao botão (o item some do DOM)', async () => {
        mountMenu();
        await trigger().trigger('click', { detail: 0 });
        await nextTick();

        item('edit').click();
        await nextTick();

        expect(menu()).toBeNull();
        expect(document.activeElement).toBe(trigger().element);
    });
});

describe('ActionDropdown (semântica de menu)', () => {
    it('lista com role=menu, itens role=menuitem, <li> role=none e divisor role=separator', async () => {
        mountMenu();
        await trigger().trigger('click', { detail: 1 });
        await nextTick();

        expect(menu().getAttribute('role')).toBe('menu');
        expect(item('edit').getAttribute('role')).toBe('menuitem');
        expect(item('view').getAttribute('role')).toBe('menuitem');
        expect(item('edit').closest('li').getAttribute('role')).toBe('none');
        expect(menu().querySelector('hr.dropdown-divider').getAttribute('role')).toBe('separator');
    });

    it('menu com campos de formulário não recebe papéis de menu (seriam inválidos)', async () => {
        wrapper = mount(ActionDropdown, {
            attachTo: document.body,
            global: { stubs: { transition: true } },
            props: { title: 'Exportar' },
            slots: {
                default:
                    '<li><input type="date" data-test="from"></li><li><button type="button" data-test="export">Exportar</button></li>',
            },
        });
        await trigger().trigger('click', { detail: 1 });
        await nextTick();

        expect(menu().hasAttribute('role')).toBe(false);
        expect(item('export').hasAttribute('role')).toBe(false);
    });
});
