import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async () => (await import('./inertiaMock.js')).inertiaMock());

const ScheduleFormModal = (await import('@/Components/Surgery/ScheduleFormModal.vue')).default;

const modal = {
    props: ['open'],
    template: '<div v-if="open"><slot name="header" /><slot /><slot name="footer" /></div>',
};

const t = {
    schedule: {
        form: {
            title_schedule: 'Agendar cirurgia',
            title_reschedule: 'Reagendar cirurgia',
            date: 'Data',
            time: 'Hora',
            duration: 'Duração',
            minutes: 'min',
            facility: 'Local',
            room: 'Sala',
            choose: 'Escolha…',
            no_room: 'Sem salas',
            no_rooms_hint: 'Sem salas cadastradas',
            no_facilities: 'Nenhum local',
            configure: 'Cadastrar',
            checking: 'Conferindo…',
            free: 'Janela livre',
            conflicts_title: 'Conflito:',
            cancel: 'Cancelar',
            confirm_schedule: 'Agendar',
            confirm_reschedule: 'Reagendar',
        },
        check_failed: 'Falha na conferência',
        save_failed: 'Falha ao salvar',
    },
};

const facilities = [
    {
        id: 'f1',
        name: 'Hospital A',
        rooms: [
            { id: 'r1', name: 'Sala 1' },
            { id: 'r2', name: 'Sala 2' },
        ],
    },
    { id: 'f2', name: 'Hospital B', rooms: [] },
];

const target = {
    id: 'c1',
    title: 'Facoemulsificação',
    patient: 'Maria',
    revision: 4,
    start: null,
    duration: 60,
    facility_id: 'f1',
    room_id: null,
};

function respond(status, body) {
    return Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) });
}

function mountModal(extra = {}) {
    return mount(ScheduleFormModal, {
        props: {
            open: true,
            target,
            facilities,
            defaultDate: '2026-10-19',
            urls: { submit: '/s', check: '/c', facilities: '/f' },
            t,
            ...extra,
        },
        global: { stubs: { CenteredModal: modal } },
    });
}

describe('ScheduleFormModal', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        globalThis.fetch = vi.fn(() => respond(200, { ok: true, conflicts: [] }));
    });

    afterEach(() => vi.useRealTimers());

    it('só habilita o botão com data, hora, duração, local e (havendo salas) sala', async () => {
        const wrapper = mountModal();
        const submit = () => wrapper.find('[data-testid="schedule-submit"]');

        expect(submit().attributes('disabled')).toBeDefined(); // local escolhido, mas falta a sala

        await wrapper.find('#sched-room').setValue('r1');
        await vi.advanceTimersByTimeAsync(500);
        await flushPromises();

        expect(submit().attributes('disabled')).toBeUndefined();
    });

    it('confere a janela depois de um tempo e avisa que está livre', async () => {
        const wrapper = mountModal();
        await wrapper.find('#sched-room').setValue('r1');
        await vi.advanceTimersByTimeAsync(500);
        await flushPromises();

        const [url, init] = globalThis.fetch.mock.calls.at(-1);
        expect(url).toBe('/c');
        expect(JSON.parse(init.body)).toMatchObject({
            starts_at: '2026-10-19T08:00',
            duration_minutes: 60,
            surgery_facility_id: 'f1',
            surgery_room_id: 'r1',
        });
        expect(wrapper.find('[data-testid="schedule-free"]').exists()).toBe(true);
    });

    it('lista cada conflito devolvido pela conferência', async () => {
        globalThis.fetch = vi.fn(() =>
            respond(200, {
                ok: false,
                conflicts: [{ text: 'A sala Sala 1 já está ocupada' }, { text: 'Há consulta às 09:30' }],
            }),
        );
        const wrapper = mountModal();
        await wrapper.find('#sched-room').setValue('r1');
        await vi.advanceTimersByTimeAsync(500);
        await flushPromises();

        const items = wrapper.findAll('[data-testid="schedule-conflict"]');
        expect(items.map((i) => i.text())).toEqual(['A sala Sala 1 já está ocupada', 'Há consulta às 09:30']);
        expect(wrapper.find('[data-testid="schedule-free"]').exists()).toBe(false);
    });

    it('envia POST com a revisão vista e emite saved', async () => {
        const wrapper = mountModal();
        await wrapper.find('#sched-room').setValue('r2');
        await vi.advanceTimersByTimeAsync(500);
        await flushPromises();

        globalThis.fetch = vi.fn(() => respond(200, { message: 'Cirurgia agendada.' }));
        await wrapper.find('[data-testid="schedule-submit"]').trigger('click');
        await flushPromises();

        const [url, init] = globalThis.fetch.mock.calls[0];
        expect(url).toBe('/s');
        expect(init.method).toBe('POST');
        expect(JSON.parse(init.body).expected_revision).toBe(4);
        expect(wrapper.emitted('saved')[0]).toEqual(['Cirurgia agendada.']);
    });

    it('reagendar envia PUT', async () => {
        const wrapper = mountModal({ mode: 'reschedule' });
        await wrapper.find('#sched-room').setValue('r1');
        await vi.advanceTimersByTimeAsync(500);
        await flushPromises();

        globalThis.fetch = vi.fn(() => respond(200, { message: 'ok' }));
        await wrapper.find('[data-testid="schedule-submit"]').trigger('click');
        await flushPromises();

        expect(globalThis.fetch.mock.calls[0][1].method).toBe('PUT');
    });

    it('409 do envio mostra os conflitos no mesmo lugar e não emite saved', async () => {
        const wrapper = mountModal();
        await wrapper.find('#sched-room').setValue('r1');
        await vi.advanceTimersByTimeAsync(500);
        await flushPromises();

        globalThis.fetch = vi.fn(() =>
            respond(409, {
                message: 'Sala ocupada',
                context: { conflicts: [{ text: 'Sala ocupada pelo caso CIR-9' }] },
            }),
        );
        await wrapper.find('[data-testid="schedule-submit"]').trigger('click');
        await flushPromises();

        expect(wrapper.emitted('saved')).toBeUndefined();
        expect(wrapper.find('[data-testid="schedule-conflicts"]').text()).toContain('Sala ocupada pelo caso CIR-9');
    });

    it('local sem salas não exige sala e explica a regra', async () => {
        const wrapper = mountModal({ target: { ...target, facility_id: 'f2' } });
        await vi.advanceTimersByTimeAsync(500);
        await flushPromises();

        expect(wrapper.find('#sched-room').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('Sem salas cadastradas');
        expect(wrapper.find('[data-testid="schedule-submit"]').attributes('disabled')).toBeUndefined();
    });

    it('descarta a resposta velha da conferência (a última edição vale)', async () => {
        const resolvers = [];
        globalThis.fetch = vi.fn(() => new Promise((resolve) => resolvers.push(resolve)));

        const wrapper = mountModal();
        await wrapper.find('#sched-room').setValue('r1');
        await vi.advanceTimersByTimeAsync(500);
        await wrapper.find('#sched-room').setValue('r2');
        await vi.advanceTimersByTimeAsync(500);

        // A 2ª resposta chega antes da 1ª; a 1ª (velha, com conflito) não pode sobrescrever.
        resolvers[1]({ ok: true, status: 200, json: () => Promise.resolve({ ok: true, conflicts: [] }) });
        await flushPromises();
        resolvers[0]({
            ok: true,
            status: 200,
            json: () => Promise.resolve({ ok: false, conflicts: [{ text: 'velho' }] }),
        });
        await flushPromises();

        expect(wrapper.find('[data-testid="schedule-conflicts"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="schedule-free"]').exists()).toBe(true);
    });
});
