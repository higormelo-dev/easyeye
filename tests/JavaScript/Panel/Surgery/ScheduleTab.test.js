import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async () => (await import('./inertiaMock.js')).inertiaMock());

const { __router } = await import('@inertiajs/vue3');
const ScheduleTab = (await import('@/Components/Surgery/Tabs/ScheduleTab.vue')).default;

const modal = {
    props: ['open'],
    template: '<div v-if="open"><slot name="header" /><slot /><slot name="footer" /></div>',
};
const ui = {
    expires_before: 'Vale até :valid, antes de :date',
    authorization_expired: 'Autorização vencida',
    authorization_blocks: 'Autorização não permite agendar',
    window: 'Janela',
    start: 'Início',
    end: 'Fim',
    facility: 'Local',
    room: 'Sala',
    no_room: 'Sem sala',
    not_scheduled: 'Ainda não agendada',
    schedule: 'Agendar',
    reschedule: 'Reagendar',
    release: 'Liberar vaga',
    open_agenda: 'Abrir agenda',
    release_title: 'Liberar',
    release_reason: 'Motivo',
    release_confirm: 'Liberar vaga',
    cancel: 'Voltar',
};
const t = { schedule: { ui, form: { title_schedule: 'Agendar cirurgia', title_reschedule: 'Reagendar cirurgia' } } };

const surgicalCase = { id: 'c1', title: 'Catarata', revision: 3, patient: { name: 'Maria' } };
const section = (over = {}) => ({
    window: null,
    authorization: { expired: false, valid_until: null },
    expires_before: false,
    authorization_blocks: false,
    can_schedule: false,
    can_reschedule: false,
    can_release: false,
    duration: 60,
    facility_id: null,
    room_id: null,
    facilities: [{ id: 'f1', name: 'Hospital', rooms: [] }],
    urls: { store: '/s', update: '/u', destroy: '/d', check: '/c', agenda: '/agenda', facilities: '/f' },
    ...over,
});

function mountTab(sec) {
    return mount(ScheduleTab, {
        props: { section: sec, surgicalCase, can: { manage: true }, urls: {}, options: {}, t },
        global: { stubs: { CenteredModal: modal } },
    });
}

describe('ScheduleTab', () => {
    it('caso autorizado e sem janela: só o botão Agendar', () => {
        const wrapper = mountTab(section({ can_schedule: true }));

        expect(wrapper.find('[data-testid="schedule-empty"]').text()).toBe('Ainda não agendada');
        expect(wrapper.find('[data-testid="schedule-open"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="reschedule-open"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="release-open"]').exists()).toBe(false);
    });

    it('caso agendado mostra a janela, local e sala, e oferece reagendar e liberar', () => {
        const wrapper = mountTab(
            section({
                can_reschedule: true,
                can_release: true,
                window: {
                    start: '2026-10-19T09:00:00-03:00',
                    end: '2026-10-19T10:00:00-03:00',
                    facility: 'Hospital',
                    room: 'Sala 1',
                },
            }),
        );

        const box = wrapper.find('[data-testid="schedule-window"]');
        expect(box.text()).toContain('Hospital');
        expect(box.text()).toContain('Sala 1');
        expect(wrapper.find('[data-testid="schedule-open"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="reschedule-open"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="release-open"]').exists()).toBe(true);
    });

    it('sem permissão de agendar, não há botão nenhum', () => {
        const wrapper = mountTab(section());

        expect(wrapper.find('[data-testid="schedule-open"]').exists()).toBe(false);
    });

    it('alerta quando a autorização vence antes da cirurgia e quando já venceu', () => {
        const warn = mountTab(
            section({
                expires_before: true,
                authorization: { expired: false, valid_until: '2026-10-17' },
                window: {
                    start: '2026-10-19T09:00:00-03:00',
                    end: '2026-10-19T10:00:00-03:00',
                    facility: 'H',
                    room: null,
                },
            }),
        );
        expect(warn.find('[data-testid="schedule-expires-before"]').text()).toContain('antes de');

        const expired = mountTab(section({ authorization: { expired: true, valid_until: '2026-10-10' } }));
        expect(expired.find('[data-testid="schedule-auth-expired"]').exists()).toBe(true);
    });

    it('liberar pede o motivo e envia DELETE com a revisão vista', async () => {
        const wrapper = mountTab(
            section({
                can_release: true,
                window: {
                    start: '2026-10-19T09:00:00-03:00',
                    end: '2026-10-19T10:00:00-03:00',
                    facility: 'H',
                    room: null,
                },
            }),
        );

        await wrapper.find('[data-testid="release-open"]').trigger('click');
        await wrapper.find('textarea').setValue('Paciente remarcou');
        await wrapper
            .findAll('button')
            .find((b) => b.text() === 'Liberar vaga' && b.classes().includes('btn-danger'))
            .trigger('click');

        expect(__router.delete).toHaveBeenCalledWith(
            '/d',
            expect.objectContaining({ data: { reason: 'Paciente remarcou', expected_revision: 3 } }),
        );
    });

    it('abre o formulário de agendar', async () => {
        const wrapper = mountTab(section({ can_schedule: true }));

        await wrapper.find('[data-testid="schedule-open"]').trigger('click');

        expect(wrapper.find('[data-testid="schedule-form"]').exists()).toBe(true);
    });
});
