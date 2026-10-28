import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import DoctorCards from '@/Pages/Panel/Doctors/DoctorCards.vue';

/**
 * Cards de médicos no padrão de PatientCards: status, especialidade, telefone
 * e as mesmas ações da tabela (antes não havia "Visualizar" e o card mostrava
 * especialidade/cor vazias porque o endpoint não devolvia esses campos).
 */

vi.mock('@/Components/Panel/ActionDropdown.vue', () => ({
    default: { template: '<div class="dd"><slot /></div>' },
}));
vi.mock('@/Components/Panel/ActionIconButton.vue', () => ({
    default: {
        props: ['title', 'icon', 'href'],
        emits: ['click'],
        template: '<button type="button" :title="title" @click="$emit(\'click\')" />',
    },
}));
vi.mock('@/Components/Panel/ActionIconGroup.vue', () => ({ default: { template: '<div><slot /></div>' } }));

const t = {
    status_active: 'Ativo',
    status_inactive: 'Inativo',
    specialty: 'Especialidade',
    action_view: 'Visualizar',
    action_work_schedule: 'Horários de atendimento',
    action_edit: 'Editar',
    load_error: 'Falha ao carregar.',
    retry: 'Tentar novamente',
    empty_list: 'Nenhum médico encontrado.',
};

let wrapper;
let successHandler;

function doctor(overrides = {}) {
    return {
        id: 'd1',
        code: 'MED-1',
        full_name: 'DRA ANA',
        record: '12345',
        record_specialty: 'RETINA',
        cellphone: '(61) 99999-8888',
        whatsapp: true,
        color: '#ff0000',
        active: false,
        photo_url: '/img.png',
        work_schedule_url: '/ws',
        mode: 'full',
        ...overrides,
    };
}

function respond(data, meta = { current_page: 1, last_page: 1, total: data.length }) {
    globalThis.fetch = vi.fn(() =>
        Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ data, meta }) }),
    );
}

beforeEach(() => {
    successHandler = null;
    router.on = vi.fn((event, cb) => {
        if (event === 'success') successHandler = cb;
        return () => {};
    });
});
afterEach(() => wrapper?.unmount());

async function mountCards(search = '') {
    wrapper = mount(DoctorCards, { props: { cardsUrl: '/doctors/cards', search, t } });
    await flushPromises();

    return wrapper;
}

describe('DoctorCards', () => {
    it('mostra status, especialidade e telefone vindos do endpoint', async () => {
        respond([doctor()]);
        const w = await mountCards('ana');

        expect(globalThis.fetch.mock.calls[0][0]).toContain('search=ana');
        expect(w.text()).toContain('Inativo');
        expect(w.text()).toContain('RETINA');
        expect(w.text()).toContain('(61) 99999-8888');
    });

    it('tem "Visualizar" e emite o id (antes o card não tinha essa ação)', async () => {
        respond([doctor()]);
        const w = await mountCards();

        await w.find('button[title="Visualizar"]').trigger('click');

        expect(w.emitted('view')[0]).toEqual(['d1']);
        expect(w.find('button[title="Horários de atendimento"]').exists()).toBe(true);
        expect(w.text()).toContain('Editar');
    });

    it('view_only não oferece editar nem horários', async () => {
        respond([doctor({ mode: 'view_only' })]);
        const w = await mountCards();

        expect(w.find('button[title="Visualizar"]').exists()).toBe(true);
        expect(w.find('button[title="Horários de atendimento"]').exists()).toBe(false);
        expect(w.text()).not.toContain('Editar');
    });

    it('erro no endpoint mostra alerta com tentar de novo', async () => {
        globalThis.fetch = vi.fn(() => Promise.resolve({ ok: false, status: 500, json: () => Promise.resolve({}) }));
        const w = await mountCards();

        expect(w.find('[role="alert"]').text()).toContain('Falha ao carregar.');

        respond([doctor()]);
        await w.find('[role="alert"] button').trigger('click');
        await flushPromises();

        expect(w.findAll('.card')).toHaveLength(1);
    });

    it('busca nova volta para a página 1 mesmo com o prop já atualizado antes do evento (ordem real do Inertia 3)', async () => {
        respond([doctor()], { current_page: 3, last_page: 5, total: 60 });
        const w = await mountCards('');

        respond([doctor()]);
        await w.setProps({ search: 'brad' });
        successHandler({ detail: { page: { props: { filters: { search: 'brad' } } } } });
        await flushPromises();

        const url = globalThis.fetch.mock.calls[0][0];
        expect(url).toContain('search=brad');
        expect(url).toContain('page=1');
    });

    it('mesma busca (após editar/excluir) mantém a página atual', async () => {
        respond([doctor()], { current_page: 3, last_page: 5, total: 60 });
        await mountCards('uni');

        respond([doctor()], { current_page: 3, last_page: 5, total: 60 });
        successHandler({ detail: { page: { props: { filters: { search: 'uni' } } } } });
        await flushPromises();

        expect(globalThis.fetch.mock.calls[0][0]).toContain('page=3');
    });

    it('resposta antiga que chega depois não sobrescreve a mais nova', async () => {
        respond([doctor()]);
        const w = await mountCards('');

        let resolveSlow;
        globalThis.fetch = vi
            .fn()
            .mockImplementationOnce(
                () =>
                    new Promise((r) => {
                        resolveSlow = r;
                    }),
            )
            .mockImplementationOnce(() =>
                Promise.resolve({
                    ok: true,
                    status: 200,
                    json: () =>
                        Promise.resolve({
                            data: [doctor({ id: 'new', full_name: 'NOVO' })],
                            meta: { current_page: 1, last_page: 1, total: 1 },
                        }),
                }),
            );

        successHandler({ detail: { page: { props: { filters: { search: 'a' } } } } });
        successHandler({ detail: { page: { props: { filters: { search: 'ab' } } } } });
        await flushPromises();

        resolveSlow({
            ok: true,
            status: 200,
            json: () =>
                Promise.resolve({
                    data: [doctor({ id: 'old', full_name: 'ANTIGO' })],
                    meta: { current_page: 1, last_page: 1, total: 1 },
                }),
        });
        await flushPromises();

        expect(w.text()).toContain('NOVO');
        expect(w.text()).not.toContain('ANTIGO');
    });

    it('botão de tentar de novo tem nome acessível', async () => {
        globalThis.fetch = vi.fn(() => Promise.resolve({ ok: false, status: 500, json: () => Promise.resolve({}) }));
        const w = await mountCards();

        expect(w.find('[role="alert"] button').attributes('aria-label')).toBe('Tentar novamente');
    });
});
