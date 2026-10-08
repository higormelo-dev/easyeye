import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import AgendaCounters from '@/Pages/Panel/Schedules/AgendaCounters.vue';
import ScheduleCard from '@/Pages/Panel/Schedules/ScheduleCard.vue';

/**
 * Fase 1 — topo da Agenda (contadores que também são filtros) e a linha da
 * agenda: selo de encaixe, sessão da série, sala e o WhatsApp (abre a conversa
 * ou fica desabilitado com o motivo).
 */
const t = {
    counter_total: 'Agendados',
    counter_confirmed: 'Confirmados',
    counter_waiting: 'Na espera',
    counter_fit_in: 'Encaixes',
    counter_blocked: 'Bloqueados',
    counter_waiting_hint: 'Chegaram e esperam',
    fit_in_badge: 'Encaixe',
    session_label: 'Sessão :session',
    whatsapp_open: 'Abrir conversa no WhatsApp',
    whatsapp_opted_out_hint: 'Respondeu SAIR',
    whatsapp_no_number: 'Sem celular',
    print_patient_sheet: 'Imprimir ficha',
};

describe('AgendaCounters', () => {
    const counters = { total: 12, confirmed: 6, waiting: 2, fit_in: 1, blocked: 0 };

    it('mostra cada contador com o número e o rótulo; "Agendados" fica marcado sem filtro', () => {
        const wrapper = mount(AgendaCounters, { props: { counters, active: null, t } });

        const chip = (key) => wrapper.find(`[data-counter="${key}"]`);
        expect(chip('total').text()).toContain('12');
        expect(chip('total').text()).toContain('Agendados');
        expect(chip('total').attributes('aria-pressed')).toBe('true');
        expect(chip('waiting').attributes('title')).toBe('Chegaram e esperam');
        expect(chip('blocked').classes()).toContain('is-zero');
        expect(wrapper.findAll('[data-counter]')).toHaveLength(11);
    });

    it('clicar num contador filtra; clicar de novo (ou em "Agendados") limpa', async () => {
        const wrapper = mount(AgendaCounters, { props: { counters, active: null, t } });

        await wrapper.find('[data-counter="waiting"]').trigger('click');
        expect(wrapper.emitted('select').at(-1)).toEqual(['waiting']);

        await wrapper.setProps({ active: 'waiting' });
        expect(wrapper.find('[data-counter="waiting"]').attributes('aria-pressed')).toBe('true');
        expect(wrapper.find('[data-counter="total"]').attributes('aria-pressed')).toBe('false');

        await wrapper.find('[data-counter="waiting"]').trigger('click');
        expect(wrapper.emitted('select').at(-1)).toEqual([null]);

        await wrapper.find('[data-counter="total"]').trigger('click');
        expect(wrapper.emitted('select').at(-1)).toEqual([null]);
    });
});

describe('ScheduleCard — encaixe, sessão, sala e WhatsApp', () => {
    const base = {
        id: 's1',
        time: '09:30',
        name: 'MARIA',
        doctor_color: '#0d6efd',
        badge: 'bg-info',
        icon: 'fa-check',
        label: 'Confirmado',
        situation: 2,
        is_terminal: false,
        patient_id: 'p1',
    };

    const mountCard = (item, props = {}) =>
        mount(ScheduleCard, {
            props: { item: { ...base, ...item }, isStaff: true, t, ...props },
            global: { stubs: { ActionDropdown: { template: '<div><slot /></div>' } } },
        });

    it('selos de encaixe, sessão "3/10" e sala, com texto (não só cor)', () => {
        const wrapper = mountCard({
            is_fit_in: true,
            session_number: 3,
            session_total: 10,
            resource_names: ['SALA 2'],
        });

        expect(wrapper.find('[data-test="fit-in-badge"]').text()).toContain('Encaixe');
        expect(wrapper.find('[data-test="session-badge"]').text()).toContain('3/10');
        expect(wrapper.find('[data-test="session-badge"]').attributes('title')).toBe('Sessão 3/10');
        expect(wrapper.text()).toContain('SALA 2');
    });

    it('alergia do paciente (Fase 2): texto no card; sem a informação (perfil sem acesso), nada', () => {
        const has = mountCard({
            allergy: { state: 'has', items: [{ substance: 'Dipirona', severe: true }], severe: true },
        });
        expect(has.find('[data-testid="allergy-badge-has"]').text()).toBe('ALERGIA: Dipirona (grave)');

        expect(
            mountCard({ allergy: { state: 'unknown', items: [] } })
                .find('[data-testid="allergy-badge-unknown"]')
                .text(),
        ).toBe('Sem registro de alergias');
        expect(mountCard({ allergy: null }).find('.cs-allergy-badge').exists()).toBe(false);
    });

    it('sem encaixe/série: nenhum selo', () => {
        const wrapper = mountCard({});

        expect(wrapper.find('[data-test="fit-in-badge"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="session-badge"]').exists()).toBe(false);
    });

    it('WhatsApp ok abre a conversa em outra aba', () => {
        const wrapper = mountCard({ whatsapp_state: 'ok', whatsapp_url: 'https://wa.me/5561999998888' });
        const link = wrapper.find('[data-whatsapp="ok"]');

        expect(link.element.tagName).toBe('A');
        expect(link.attributes('href')).toBe('https://wa.me/5561999998888');
        expect(link.attributes('target')).toBe('_blank');
        expect(link.attributes('rel')).toContain('noopener');
        expect(link.attributes('title')).toBe('Abrir conversa no WhatsApp');
    });

    it('WhatsApp de quem respondeu SAIR ou sem celular: botão desabilitado com o motivo', () => {
        const optedOut = mountCard({ whatsapp_state: 'opted_out', whatsapp_url: null }).find(
            '[data-whatsapp="opted_out"]',
        );
        const noNumber = mountCard({ whatsapp_state: 'no_number', whatsapp_url: null }).find(
            '[data-whatsapp="no_number"]',
        );

        expect(optedOut.element.tagName).toBe('BUTTON');
        expect(optedOut.attributes('disabled')).toBeDefined();
        expect(optedOut.attributes('title')).toBe('Respondeu SAIR');
        expect(noNumber.attributes('disabled')).toBeDefined();
        expect(noNumber.attributes('title')).toBe('Sem celular');
    });

    it('sem o campo (perfil sem acesso a Pacientes): nenhum ícone de WhatsApp', () => {
        expect(mountCard({}).find('[data-whatsapp]').exists()).toBe(false);
    });

    it('ficha do paciente no menu da linha só com acesso a Pacientes', () => {
        expect(mountCard({}, { canSeePatients: true }).text()).toContain('Imprimir ficha');
        expect(mountCard({}, { canSeePatients: false }).text()).not.toContain('Imprimir ficha');
    });
});
