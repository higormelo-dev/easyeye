import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import Form from '@/Pages/Panel/Settings/ReportSettings/Form.vue';

/**
 * Configurações › Modelos (edição): conteúdo personalizado pela clínica cujo
 * modelo padrão mudou — selo "Atualização disponível do modelo padrão", ver
 * os dois textos, aplicar (com confirmação) ou manter o meu. O envio do
 * formulário leva o `id` de cada conteúdo (antes recriava tudo sem slug).
 */
const inertia = vi.hoisted(() => ({ posts: [] }));

vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const actual = await importOriginal();
    return {
        ...actual,
        usePage: () => ({ props: { t_ui: {} } }),
        router: { post: vi.fn(), reload: vi.fn(), visit: vi.fn() },
        Link: { template: '<a><slot /></a>' },
        useForm: (data) => {
            const form = actual.useForm(data);
            form.post = vi.fn((url) => inertia.posts.push({ url, data: form.data() }));
            return form;
        },
    };
});
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));

const T = {
    upstream_badge: 'Atualização disponível do modelo padrão',
    upstream_apply: 'Aplicar atualização',
    upstream_keep: 'Manter o meu',
};

function mountForm() {
    return mount(Form, {
        props: {
            mode: 'edit',
            urls: { update: '/rs/1', index: '/rs' },
            t: T,
            reportSetting: {
                id: 'rs1',
                title: 'ATESTADO DE COMPARECIMENTO',
                contents: [
                    {
                        id: 'c1',
                        type: 'certificate',
                        label: 'Comparecimento',
                        content: '<p>Meu texto</p>',
                        active: true,
                        upstream: {
                            label: 'Comparecimento',
                            apply_url: '/apply/c1',
                            keep_url: '/keep/c1',
                            show_url: '/show/c1',
                        },
                    },
                    {
                        id: 'c2',
                        type: 'certificate',
                        label: 'Outro',
                        content: '<p>X</p>',
                        active: true,
                        upstream: null,
                    },
                ],
            },
        },
        global: { stubs: { teleport: true, SearchSelect: true } },
    });
}

beforeEach(() => {
    inertia.posts.length = 0;
    router.post.mockClear();
    globalThis.fetch = vi.fn(() =>
        Promise.resolve({
            ok: true,
            json: () =>
                Promise.resolve({ local: { content: '<p>Meu texto</p>' }, global: { content: '<p>Padrão novo</p>' } }),
        }),
    );
});

describe('Modelos — atualização do modelo padrão', () => {
    it('selo só no conteúdo com atualização; "Ver modelo padrão" mostra os dois textos', async () => {
        const w = mountForm();
        expect(w.find('[data-testid="upstream-c1"]').text()).toContain('Atualização disponível do modelo padrão');
        expect(w.find('[data-testid="upstream-c2"]').exists()).toBe(false);

        await w.find('[data-testid="upstream-view"]').trigger('click');
        await flushPromises();
        expect(w.find('[data-testid="upstream-compare"]').text()).toContain('<p>Padrão novo</p>');
        expect(globalThis.fetch).toHaveBeenCalledWith('/show/c1', expect.anything());

        // Esc fecha a comparação (o CenteredModal não trata teclado) e os botões voltam a funcionar.
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await flushPromises();
        expect(w.find('[data-testid="upstream-compare"]').exists()).toBe(false);
        w.unmount();
    });

    it('aplicar pede confirmação; manter vai direto', async () => {
        const w = mountForm();
        const original = window.confirm;
        window.confirm = vi.fn().mockReturnValueOnce(false).mockReturnValueOnce(true);

        await w.find('[data-testid="upstream-apply"]').trigger('click');
        expect(router.post).not.toHaveBeenCalled();
        await w.find('[data-testid="upstream-apply"]').trigger('click');
        expect(router.post).toHaveBeenCalledWith('/apply/c1', {}, expect.objectContaining({ preserveScroll: true }));

        await w.find('[data-testid="upstream-keep"]').trigger('click');
        expect(router.post).toHaveBeenLastCalledWith('/keep/c1', {}, expect.objectContaining({ preserveScroll: true }));
        expect(window.confirm).toHaveBeenCalledTimes(2);
        window.confirm = original;
    });

    it('com alteração não salva no formulário, não aplica nem mantém (perderia o que foi digitado)', async () => {
        const w = mountForm();
        await w.find('textarea').setValue('<p>Digitando…</p>');
        await w.find('[data-testid="upstream-keep"]').trigger('click');
        expect(router.post).not.toHaveBeenCalled();
        expect(w.text()).toContain('Salve ou descarte');
    });

    it('depois de aplicar, o editor mostra o texto do padrão e Salvar não desfaz a atualização', async () => {
        const w = mountForm();
        const original = window.confirm;
        window.confirm = vi.fn(() => true);
        // Servidor: aplica e devolve a página com o texto novo (sem aviso).
        router.post.mockImplementationOnce(async (url, data, options) => {
            const fresh = JSON.parse(JSON.stringify(w.props('reportSetting')));
            fresh.contents[0] = { ...fresh.contents[0], content: '<p>Padrão novo</p>', upstream: null };
            await w.setProps({ reportSetting: fresh });
            options.onSuccess?.();
        });

        await w.find('[data-testid="upstream-apply"]').trigger('click');
        await flushPromises();
        window.confirm = original;

        expect(w.find('[data-testid="upstream-c1"]').exists()).toBe(false);
        expect(w.find('textarea.font-monospace').element.value).toBe('<p>Padrão novo</p>');

        // Sem alteração pendente (não bloqueia "Manter o meu" de outro conteúdo) e
        // o Salvar seguinte envia o texto aplicado.
        await w.find('form').trigger('submit');
        expect(inertia.posts[0].data.contents[0].content).toBe('<p>Padrão novo</p>');
    });

    it('editou a MESMA linha enquanto o Aplicar estava pendente: a edição do médico vence, não perde (15ª revisão Codex, P2)', async () => {
        const w = mountForm();
        const original = window.confirm;
        window.confirm = vi.fn(() => true);

        router.post.mockImplementationOnce(async (url, data, options) => {
            // Enquanto a requisição está "no ar", o médico mexeu NESTA MESMA
            // linha — a sincronização não pode pisar em cima disso.
            await w.find('textarea.font-monospace').setValue('<p>Editando durante a espera…</p>');

            const fresh = JSON.parse(JSON.stringify(w.props('reportSetting')));
            fresh.contents[0] = { ...fresh.contents[0], content: '<p>Padrão novo</p>', upstream: null };
            await w.setProps({ reportSetting: fresh });
            options.onSuccess?.();
        });

        await w.find('[data-testid="upstream-apply"]').trigger('click');
        await flushPromises();
        window.confirm = original;

        expect(w.find('textarea.font-monospace').element.value).toBe('<p>Editando durante a espera…</p>');

        await w.find('form').trigger('submit');
        expect(inertia.posts[0].data.contents[0].content).toBe('<p>Editando durante a espera…</p>');
    });

    it('editou OUTRO conteúdo enquanto o Aplicar de um estava pendente: aquele outro continua "alterado" (15ª revisão Codex, P2)', async () => {
        const w = mount(Form, {
            props: {
                mode: 'edit',
                urls: { update: '/rs/1', index: '/rs' },
                t: T,
                reportSetting: {
                    id: 'rs1',
                    title: 'ATESTADO DE COMPARECIMENTO',
                    contents: [
                        {
                            id: 'c1',
                            type: 'certificate',
                            label: 'Comparecimento',
                            content: '<p>Meu texto</p>',
                            active: true,
                            upstream: {
                                label: 'Comparecimento',
                                apply_url: '/apply/c1',
                                keep_url: '/keep/c1',
                                show_url: '/show/c1',
                            },
                        },
                        {
                            id: 'c2',
                            type: 'certificate',
                            label: 'Outro',
                            content: '<p>X</p>',
                            active: true,
                            upstream: null,
                        },
                        {
                            id: 'c3',
                            type: 'certificate',
                            label: 'Terceiro',
                            content: '<p>Y</p>',
                            active: true,
                            upstream: {
                                label: 'Terceiro',
                                apply_url: '/apply/c3',
                                keep_url: '/keep/c3',
                                show_url: '/show/c3',
                            },
                        },
                    ],
                },
            },
            global: { stubs: { teleport: true, SearchSelect: true } },
        });
        const original = window.confirm;
        window.confirm = vi.fn(() => true);

        router.post.mockImplementationOnce(async (url, data, options) => {
            // Editou o OUTRO conteúdo (c2) enquanto o Aplicar do c1 estava pendente.
            await w.findAll('textarea.font-monospace')[1].setValue('<p>Outra edição durante a espera…</p>');

            const fresh = JSON.parse(JSON.stringify(w.props('reportSetting')));
            fresh.contents[0] = { ...fresh.contents[0], content: '<p>Padrão novo</p>', upstream: null };
            await w.setProps({ reportSetting: fresh });
            options.onSuccess?.();
        });

        await w.findAll('[data-testid="upstream-apply"]')[0].trigger('click'); // c1
        await flushPromises();

        // c2 ficou "alterado" (nunca sincronizado) — form.isDirty continua
        // true, e o Aplicar de OUTRO conteúdo (c3, único que resta com o
        // selo depois que o de c1 some) é bloqueado pelo mesmo guard que já
        // bloqueia uma edição feita na hora.
        await w.findAll('[data-testid="upstream-apply"]')[0].trigger('click'); // c3
        window.confirm = original;

        expect(router.post).toHaveBeenCalledTimes(1);
        expect(w.text()).toContain('Salve ou descarte');
    });

    it('salvar envia o id de cada conteúdo (edita no lugar, sem perder o vínculo/slug)', async () => {
        const w = mountForm();
        await w.find('form').trigger('submit');
        expect(inertia.posts[0].data.contents.map((c) => c.id)).toEqual(['c1', 'c2']);
    });
});
