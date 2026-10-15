import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';

vi.mock('@inertiajs/vue3', async () => (await import('./support/inertiaMock.js')).buildInertiaMock());
vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: { props: ['title', 'breadcrumbs'], template: '<div class="layout"><h1>{{ title }}</h1><slot /></div>' },
}));
vi.mock('@/composables/useUnsavedChangesGuard.js', () => ({
    useUnsavedChangesGuard: () => ({ bypass: (fn) => fn() }),
}));

import { forms, router, page, resetInertiaMock } from './support/inertiaMock.js';
import Index from '@/Pages/Panel/Manager/RecallProtocols/Index.vue';
import Show from '@/Pages/Panel/Manager/RecallProtocols/Show.vue';
import Version from '@/Pages/Panel/Manager/RecallProtocols/Version.vue';
import { baseContent, exampleRows, managerT, options, ruleT } from './support/fixtures.js';

/** Ziggy: params posicionais em array viram segmentos separados por "/" (o mock global só entende objeto). */
const route = vi.fn((name, params) => {
    if (params === undefined) return `/_routes/${name}`;

    return `/_routes/${name}/${Array.isArray(params) ? params.join('/') : params}`;
});

const global = {
    stubs: { Teleport: true, Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } },
    mocks: { route },
};

beforeEach(() => {
    resetInertiaMock();
    globalThis.route = route;
    globalThis.fetch = vi.fn(() =>
        Promise.resolve({
            ok: true,
            status: 200,
            json: () => Promise.resolve({ valid: true, hash: '1:' + 'a'.repeat(64) }),
        }),
    );
});

const summary = (overrides = {}) => ({
    id: 'v-1',
    version: 1,
    state: 'draft',
    state_label: 'Rascunho',
    short_hash: 'abcdef012345',
    content_hash: '1:' + 'abcdef012345'.padEnd(64, '0'),
    frozen_at: null,
    validated_at: null,
    released_at: null,
    suspended_at: null,
    retired_at: null,
    review_due_at: null,
    created_at: '2026-10-13T12:00:00Z',
    ...overrides,
});

describe('Index', () => {
    const protocols = [
        {
            id: 'p-1',
            key: 'glaucoma',
            title: 'Glaucoma',
            summary: 'Consulta e campimetria.',
            status: 'active',
            versions_count: 2,
            released: summary({
                version: 1,
                state: 'released',
                state_label: 'Liberada',
                released_at: '2026-10-01T10:00:00Z',
            }),
            open: summary({ id: 'v-2', version: 2 }),
            latest: summary({ id: 'v-2', version: 2 }),
        },
        {
            id: 'p-2',
            key: 'dmri',
            title: 'DMRI',
            summary: '',
            status: 'active',
            versions_count: 1,
            released: null,
            open: summary({ id: 'v-3' }),
            latest: summary({ id: 'v-3' }),
        },
    ];

    it('um cartão por protocolo, com a versão liberada e a em andamento', () => {
        const wrapper = mount(Index, { props: { protocols, t: managerT }, global });

        const glaucoma = wrapper.get('[data-testid="protocol-glaucoma"]');
        expect(glaucoma.text()).toContain('Glaucoma');
        expect(glaucoma.get('[data-testid="released"]').text()).toContain('v1');
        expect(glaucoma.get('[data-testid="released"]').text()).toContain('desde 01/10/2026');
        expect(glaucoma.get('[data-testid="open"]').text()).toContain('v2');
        expect(glaucoma.get('[data-testid="open"]').text()).toContain('Rascunho');
        expect(glaucoma.text()).toContain('2 versões');
        expect(glaucoma.find('a').attributes('href')).toBe('/_routes/manager.recall-protocols.show/p-1');

        const dmri = wrapper.get('[data-testid="protocol-dmri"]');
        expect(dmri.get('[data-testid="no-released"]').text()).toContain('Nenhuma versão liberada');
        expect(dmri.text()).toContain('1 versão');
    });

    it('lista vazia avisa e o aviso de validação está sempre à vista', () => {
        const wrapper = mount(Index, { props: { protocols: [], t: managerT }, global });

        expect(wrapper.get('[data-testid="protocols-empty"]').text()).toContain('Nenhum protocolo cadastrado');
        expect(wrapper.get('[role="note"]').text()).toContain('validada');
    });
});

describe('Show', () => {
    const versions = [
        { ...summary({ id: 'v-2', version: 2 }), parent_version: 1 },
        {
            ...summary({
                id: 'v-1',
                version: 1,
                state: 'declined',
                state_label: 'Recusada pelo revisor',
                frozen_at: '2026-10-05T10:00:00Z',
            }),
            parent_version: null,
        },
    ];
    const events = [
        {
            id: 'e-1',
            event: 'revision_started',
            label: 'Nova versão aberta',
            version: 2,
            from_state: null,
            to_state: 'draft',
            from_label: null,
            to_label: 'Rascunho',
            actor: 'ANA',
            reason: null,
            created_at: '2026-10-13T12:00:00Z',
        },
    ];
    const base = {
        protocol: { id: 'p-1', key: 'glaucoma', title: 'Glaucoma', summary: 'Resumo.' },
        versions,
        events,
        t: managerT,
    };

    it('lista as versões com estado, hash curto e o botão certo (editar × abrir)', () => {
        const wrapper = mount(Show, { props: { ...base, canStartRevision: false }, global });

        const v2 = wrapper.get('[data-testid="version-2"]');
        const v1 = wrapper.get('[data-testid="version-1"]');

        expect(v2.text()).toContain('a partir da v1');
        expect(v2.text()).toContain('Rascunho');
        expect(v2.text()).toContain('abcdef012345');
        expect(v2.get('a').text()).toBe('Editar');
        expect(v1.text()).toContain('Recusada pelo revisor');
        expect(v1.text()).toContain('05/10/2026');
        expect(v1.get('a').text()).toBe('Abrir');
        expect(v1.find('a').attributes('href')).toBe('/_routes/manager.recall-protocols.versions.show/p-1/v-1');
    });

    it('"Nova versão" fica desabilitada com versão em andamento e explica por quê', () => {
        const wrapper = mount(Show, { props: { ...base, canStartRevision: false }, global });
        const button = wrapper.get('[data-testid="new-revision"]');

        expect(button.attributes('disabled')).toBeDefined();
        expect(button.element.parentElement.getAttribute('title')).toBe('Já existe uma versão em andamento.');
    });

    it('"Nova versão" abre o rascunho pelo servidor', async () => {
        const wrapper = mount(Show, { props: { ...base, canStartRevision: true }, global });

        await wrapper.get('[data-testid="new-revision"]').trigger('click');

        expect(router.post).toHaveBeenCalledWith(
            '/_routes/manager.recall-protocols.versions.store/p-1',
            {},
            { preserveScroll: true },
        );
    });

    it('histórico com quem, quando e a mudança de estado', () => {
        const wrapper = mount(Show, { props: { ...base, canStartRevision: true }, global });
        const text = wrapper.get('[data-testid="events-table"]').text();

        expect(text).toContain('Nova versão aberta');
        expect(text).toContain('— → Rascunho');
        expect(text).toContain('ANA');
        expect(text).toContain('v2');
    });

    it('sem histórico mostra o aviso', () => {
        const wrapper = mount(Show, { props: { ...base, events: [], canStartRevision: true }, global });

        expect(wrapper.text()).toContain('Nada registrado ainda.');
        expect(wrapper.find('[data-testid="events-table"]').exists()).toBe(false);
    });
});

describe('Version', () => {
    function props(overrides = {}) {
        return {
            protocol: { id: 'p-1', key: 'glaucoma', title: 'Glaucoma' },
            version: {
                ...summary(),
                editable: true,
                lock_version: 3,
                integrity_ok: true,
                content_error: false,
                parent: null,
            },
            content: baseContent(),
            canonical: '{"a":1}',
            diff: [],
            examples: exampleRows(),
            exampleDate: '2026-01-15',
            events: [],
            options,
            t: managerT,
            ruleT,
            ...overrides,
        };
    }

    // Desmonta ao fim de cada teste: a prévia usa um timer de 500 ms que, num componente deixado montado, disparava
    // no teste seguinte (fetch contado duas vezes, só sob carga).
    const mounted = [];
    const mountVersion = (overrides) => {
        const wrapper = mount(Version, { props: props(overrides), global, attachTo: document.body });
        mounted.push(wrapper);

        return wrapper;
    };

    afterEach(() => {
        vi.useRealTimers();
        mounted.splice(0).forEach((wrapper) => wrapper.unmount());
    });

    it('rascunho: abre no editor, com o resumo da regra ao lado, "Tudo salvo" e salvar desabilitado', () => {
        const wrapper = mountVersion();

        expect(wrapper.find('[data-testid="recall-editor"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="recall-rule-card"]').exists()).toBe(true);
        expect(wrapper.get('[data-testid="dirty-status"]').text()).toBe('Tudo salvo');
        expect(wrapper.get('[data-testid="save-draft"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-testid="state-badge"]').text()).toBe('Rascunho');
        expect(wrapper.get('[data-testid="short-hash"]').text()).toBe('abcdef012345');
        expect(wrapper.find('[data-testid="frozen-notice"]').exists()).toBe(false);
    });

    it('editar marca "Alterações não salvas", habilita salvar e trava o congelamento até salvar', async () => {
        const wrapper = mountVersion();

        await wrapper.get('[data-testid="task-0-key"]').setValue('consulta_geral');
        await nextTick();

        expect(wrapper.get('[data-testid="dirty-status"]').text()).toBe('Alterações não salvas');
        expect(wrapper.get('[data-testid="save-draft"]').attributes('disabled')).toBeUndefined();
        expect(wrapper.get('[data-testid="freeze"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-testid="freeze"]').element.parentElement.getAttribute('title')).toContain(
            'salve o rascunho antes de congelar',
        );
    });

    it('salvar envia o conteúdo e o lock_version que a tela leu, preservando a rolagem', async () => {
        const wrapper = mountVersion();

        await wrapper.get('[data-testid="task-0-key"]').setValue('consulta_geral');
        await nextTick();
        await wrapper.get('[data-testid="save-draft"]').trigger('click');

        const form = forms[0];

        expect(form.put).toHaveBeenCalledTimes(1);
        expect(form.lastRequest.url).toBe('/_routes/manager.recall-protocols.versions.update/p-1/v-1');
        expect(form.lastRequest.data.lock_version).toBe(3);
        expect(form.lastRequest.data.content.tasks[0].key).toBe('consulta_geral');
        expect(form.lastRequest.options.preserveScroll).toBe(true);
    });

    it('depois de salvar (servidor devolve outro lock_version) o formulário volta a "Tudo salvo"', async () => {
        const wrapper = mountVersion();
        await wrapper.get('[data-testid="task-0-key"]').setValue('consulta_geral');
        await nextTick();
        expect(wrapper.get('[data-testid="dirty-status"]').text()).toBe('Alterações não salvas');

        const saved = baseContent();
        saved.tasks[0].key = 'consulta_geral';
        await wrapper.setProps({ content: saved, version: { ...props().version, lock_version: 4 } });
        await flushPromises();

        expect(wrapper.get('[data-testid="dirty-status"]').text()).toBe('Tudo salvo');
        expect(wrapper.get('[data-testid="task-0-key"]').element.value).toBe('consulta_geral');
    });

    it('erros por campo vindos do servidor aparecem no editor e o aviso de campos a corrigir', async () => {
        const wrapper = mountVersion();
        page.props.errors = { 'content.tasks.0.key': 'Chave inválida' };
        await nextTick();

        expect(wrapper.get('[data-testid="preview-invalid"]').text()).toContain('Há campos a corrigir');
        expect(wrapper.text()).toContain('Chave inválida');
    });

    it('lock_version obsoleto mostra o aviso para recarregar', async () => {
        const wrapper = mountVersion();
        forms[0].setError('lock_version', 'Este rascunho foi alterado por outra pessoa.');
        await nextTick();

        expect(wrapper.get('[data-testid="stale"]').text()).toContain('alterado por outra pessoa');
    });

    it('prévia: depois de editar, valida no servidor e mostra o hash que seria gravado', async () => {
        vi.useFakeTimers();
        const wrapper = mountVersion();

        await wrapper.get('[data-testid="task-0-key"]').setValue('consulta_geral');
        await nextTick();
        await vi.advanceTimersByTimeAsync(600);
        vi.useRealTimers();
        await flushPromises();
        await nextTick();

        expect(globalThis.fetch).toHaveBeenCalledTimes(1);
        const [url, init] = globalThis.fetch.mock.calls[0];
        expect(url).toBe('/_routes/manager.recall-protocols.preview/p-1');
        expect(init.method).toBe('POST');
        expect(init.headers['X-CSRF-TOKEN']).toBe('test-csrf-token');
        expect(JSON.parse(init.body).content.tasks[0].key).toBe('consulta_geral');
        expect(wrapper.get('[data-testid="preview-valid"]').text()).toContain('aaaaaaaaaaaa');
    });

    it('prévia: erro 422 do servidor vira erro de campo antes mesmo de salvar', async () => {
        globalThis.fetch = vi.fn(() =>
            Promise.resolve({
                ok: false,
                status: 422,
                json: () => Promise.resolve({ valid: false, errors: { 'content.tasks.0.key': 'Chave inválida' } }),
            }),
        );
        vi.useFakeTimers();
        const wrapper = mountVersion();

        await wrapper.get('[data-testid="task-0-key"]').setValue('X');
        await nextTick();
        await vi.advanceTimersByTimeAsync(600);
        vi.useRealTimers();
        await flushPromises();
        await nextTick();

        expect(wrapper.text()).toContain('Chave inválida');
        expect(wrapper.find('[data-testid="preview-valid"]').exists()).toBe(false);
    });

    it('congelar: pede confirmação e só então posta', async () => {
        const wrapper = mountVersion();

        await wrapper.get('[data-testid="freeze"]').trigger('click');
        expect(router.post).not.toHaveBeenCalled();

        await wrapper.get('[data-testid="freeze-confirm"]').trigger('click');

        expect(router.post).toHaveBeenCalledTimes(1);
        expect(router.post.mock.calls[0][0]).toBe('/_routes/manager.recall-protocols.versions.freeze/p-1/v-1');
        expect(router.post.mock.calls[0][2].preserveScroll).toBe(true);
    });

    it('versão congelada: só leitura, com o aviso, sem editor e sem botões de editar/congelar', () => {
        const wrapper = mountVersion({
            version: { ...props().version, editable: false, state: 'in_review', state_label: 'Em revisão' },
        });

        expect(wrapper.get('[data-testid="frozen-notice"]').text()).toContain('congelada');
        expect(wrapper.find('[data-testid="recall-editor"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="recall-rule-card"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="save-draft"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="freeze"]').exists()).toBe(false);
    });

    it('conteúdo gravado inválido: aviso e nada de regra nem editor', () => {
        const wrapper = mountVersion({
            content: null,
            version: { ...props().version, content_error: true, integrity_ok: false },
        });

        expect(wrapper.get('[data-testid="content-invalid"]').text()).toContain('não passa mais no esquema');
        expect(wrapper.find('[data-testid="recall-editor"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="recall-rule-card"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="save-draft"]').exists()).toBe(false);
    });

    it('abas: clique e setas trocam o painel visível; só a aba ativa entra na ordem do Tab', async () => {
        const wrapper = mountVersion();
        const panel = (name) => wrapper.get(`#version-panel-${name}`);

        expect(wrapper.get('[data-testid="tab-rule"]').attributes('aria-selected')).toBe('true');
        expect(wrapper.get('[data-testid="tab-changes"]').attributes('tabindex')).toBe('-1');
        expect(panel('rule').attributes('style') ?? '').not.toContain('display: none');
        expect(panel('changes').attributes('style')).toContain('display: none');

        await wrapper.get('[data-testid="tab-changes"]').trigger('click');
        expect(wrapper.get('[data-testid="tab-changes"]').attributes('aria-selected')).toBe('true');
        expect(panel('changes').attributes('style') ?? '').not.toContain('display: none');

        await wrapper.get('[data-testid="tab-changes"]').trigger('keydown', { key: 'ArrowRight' });
        expect(wrapper.get('[data-testid="tab-examples"]').attributes('aria-selected')).toBe('true');

        await wrapper.get('[data-testid="tab-examples"]').trigger('keydown', { key: 'ArrowLeft' });
        await wrapper.get('[data-testid="tab-changes"]').trigger('keydown', { key: 'ArrowLeft' });
        expect(wrapper.get('[data-testid="tab-rule"]').attributes('aria-selected')).toBe('true');
    });

    it('alterações: sem pai avisa "primeira versão"; com pai lista as diferenças', () => {
        const first = mountVersion();
        expect(first.get('[data-testid="diff-first"]').exists()).toBe(true);

        const derived = mountVersion({
            version: {
                ...props().version,
                parent: { id: 'v-0', version: 1, state: 'declined', state_label: 'Recusada pelo revisor' },
            },
            diff: [{ section: 'cid.strong', op: 'added', value: 'H405' }],
        });

        expect(derived.get('[data-testid="diff-cid.strong-added"]').text()).toContain('H40.5');
        expect(derived.text()).toContain('Derivada da versão 1');
        expect(derived.get('[data-testid="change-note"]').attributes('aria-required')).toBe('true');
    });

    it('exemplos: trocar a data recarrega só os exemplos', async () => {
        const wrapper = mountVersion();

        await wrapper.get('[data-testid="example-date"]').setValue('2026-03-01');
        await wrapper.get('[data-testid="example-refresh"]').trigger('click');

        expect(router.get).toHaveBeenCalledWith(
            '/_routes/manager.recall-protocols.versions.show/p-1/v-1',
            { example_date: '2026-03-01' },
            expect.objectContaining({ only: ['examples', 'exampleDate'], preserveState: true, replace: true }),
        );
    });

    it('integridade: íntegra × adulterada, hash completo, texto canônico e marcos', async () => {
        const ok = mountVersion({
            version: {
                ...props().version,
                editable: false,
                frozen_at: '2026-10-05T10:00:00Z',
                review_due_at: '2028-10-05',
            },
        });

        expect(ok.get('[data-testid="integrity-status"]').text()).toContain('Íntegra');
        expect(ok.get('[data-testid="integrity-status"]').classes()).toContain('alert-success');
        expect(ok.get('[data-testid="canonical"]').text()).toBe('{"a":1}');
        expect(ok.get('[data-testid="full-hash"]').text()).toMatch(/^1:/);
        expect(ok.text()).toContain('Revalidar até: 05/10/2028');
        expect(ok.text()).toContain('Validada em: ainda não');

        const broken = mountVersion({ version: { ...props().version, integrity_ok: false } });

        expect(broken.get('[data-testid="integrity-status"]').text()).toContain('NÃO confere');
        expect(broken.get('[data-testid="integrity-status"]').classes()).toContain('alert-danger');
    });
});
