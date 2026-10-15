import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import Index from '@/Pages/Panel/Settings/SurgeryTemplates/Index.vue';

/**
 * Configurações > Modelos cirúrgicos: lista, adoção dos padrões, aviso por
 * WhatsApp (desligado de fábrica) e exclusão com confirmação.
 */
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/Components/Panel/PageHeader.vue', () => ({ default: { template: '<div><slot name="actions" /></div>' } }));

const t = {
    documents: {
        templates: {
            title: 'Modelos cirúrgicos',
            subtitle: 'sub',
            review_notice: 'Revise os modelos padrão antes de usar.',
            add: 'Novo modelo',
            adopt: 'Adotar modelos padrão',
            adopt_hint: 'Cria os que faltam.',
            adopt_done: 'Todos já adotados.',
            empty: 'Nenhum modelo ainda.',
            any_category: 'Qualquer cirurgia',
            default_badge: 'Padrão do sistema',
            active: 'Ativo',
            inactive: 'Inativo',
            confirm_delete_title: 'Excluir modelo?',
            confirm_delete_text: 'O modelo “:name” deixa de ser oferecido.',
            confirm: 'Excluir',
            cancel: 'Cancelar',
        },
        notices: {
            title: 'Aviso por WhatsApp',
            description: 'neutro',
            toggle_label: 'Enviar aviso',
            requirements: 'reqs',
        },
    },
};

const urls = {
    adopt: '/adopt',
    active: '/tpl/__ID__/active',
    duplicate: '/tpl/__ID__/duplicate',
    destroy: '/tpl/__ID__',
    show: '/tpl/__ID__',
    update: '/tpl/__ID__',
    store: '/tpl',
    preview: '/preview',
    notices: '/notices',
};

const tpl = (over = {}) => ({
    id: 't1',
    type: 'consent',
    type_label: 'Termo de consentimento (TCLE)',
    category: 1,
    category_label: 'CIRURGIAS DE CATARATA',
    name: 'TCLE — Catarata',
    version: 2,
    active: true,
    is_default: true,
    updated_at: '2026-10-15T10:00:00-03:00',
    ...over,
});

function mountPage(props = {}) {
    return mount(Index, {
        attachTo: document.body,
        props: {
            templates: [tpl()],
            types: [],
            categories: [],
            variables: [],
            missingDefaults: 3,
            notices: { authorization_enabled: false },
            urls,
            t,
            ...props,
        },
    });
}

beforeEach(() => {
    document.body.innerHTML = '';
    vi.clearAllMocks();
    window.Swal = undefined;
});

describe('Configurações > Modelos cirúrgicos', () => {
    it('lista os modelos com tipo, categoria, versão e a marca de padrão do sistema', () => {
        const wrapper = mountPage();
        const row = wrapper.find('[data-testid="tpl-row"]');

        expect(row.text()).toContain('TCLE — Catarata');
        expect(row.text()).toContain('Padrão do sistema');
        expect(row.text()).toContain('v2');
        expect(row.text()).toContain('Ativo');
        expect(wrapper.find('[data-testid="tpl-review-notice"]').text()).toContain('Revise');
    });

    it('lista vazia mostra a orientação para adotar os padrões', () => {
        expect(mountPage({ templates: [] }).find('[data-testid="tpl-empty"]').text()).toBe('Nenhum modelo ainda.');
    });

    it('adotar os padrões chama o servidor; sem pendência o botão fica desabilitado', async () => {
        const wrapper = mountPage();
        await wrapper.find('[data-testid="tpl-adopt"]').trigger('click');

        expect(router.post).toHaveBeenCalledWith('/adopt', {}, expect.objectContaining({ preserveScroll: true }));

        const done = mountPage({ missingDefaults: 0 });
        expect(done.find('[data-testid="tpl-adopt"]').attributes('disabled')).toBeDefined();
        expect(done.find('[data-testid="tpl-adopt"]').attributes('title')).toBe('Todos já adotados.');
    });

    it('o aviso por WhatsApp nasce desligado e o interruptor responde ao clique', async () => {
        const wrapper = mountPage();
        const toggle = wrapper.find('[data-testid="tpl-notice-toggle"]');

        expect(toggle.element.checked).toBe(false);
        await toggle.setValue(true);
        expect(toggle.element.checked).toBe(true);
    });

    it('inativar e duplicar usam as rotas do modelo', async () => {
        const wrapper = mountPage();

        await wrapper.find('[data-testid="tpl-toggle"]').trigger('click');
        expect(router.patch).toHaveBeenCalledWith('/tpl/t1/active', { active: false }, expect.any(Object));

        await wrapper.find('[data-testid="tpl-duplicate"]').trigger('click');
        expect(router.post).toHaveBeenCalledWith('/tpl/t1/duplicate', {}, expect.any(Object));
    });

    it('excluir pede confirmação e só então chama o servidor', async () => {
        window.Swal = { fire: vi.fn(() => Promise.resolve({ isConfirmed: false })) };
        const wrapper = mountPage();

        await wrapper.find('[data-testid="tpl-delete"]').trigger('click');
        await flushPromises();
        expect(window.Swal.fire).toHaveBeenCalledWith(
            expect.objectContaining({ text: 'O modelo “TCLE — Catarata” deixa de ser oferecido.' }),
        );
        expect(router.delete).not.toHaveBeenCalled();

        window.Swal.fire.mockResolvedValueOnce({ isConfirmed: true });
        await wrapper.find('[data-testid="tpl-delete"]').trigger('click');
        await flushPromises();
        expect(router.delete).toHaveBeenCalledWith('/tpl/t1', expect.any(Object));
    });
});
