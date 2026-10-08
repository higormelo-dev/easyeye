import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import MiniCalendar from '@/Components/Panel/MiniCalendar.vue';
import SlotPicker from '@/Components/Panel/SlotPicker.vue';

/**
 * Fase 1 — calendário lateral com vagas (marcador com FORMA por estado +
 * "N livres de M" no aria-label/tooltip + legenda) e o SlotPicker em modo
 * encaixe (horário ocupado escolhível, horário fora da grade não é apagado,
 * encaixes do dia por turno).
 */
const t = {
    avail_available: 'Com vagas',
    avail_few: 'Poucas vagas',
    avail_full: 'Sem vagas',
    avail_off: 'Sem expediente',
    avail_blocked: 'Bloqueado',
    avail_free_of: ':free livres de :capacity',
    avail_no_schedule: 'Sem escala de atendimento cadastrada. Configure a escala para visualizar as vagas.',
};

describe('MiniCalendar — vagas do mês', () => {
    const availability = {
        '2030-01-07': { state: 'available', free: 10, capacity: 16 },
        '2030-01-08': { state: 'few', free: 2, capacity: 16 },
        '2030-01-09': { state: 'full', free: 0, capacity: 16 },
        '2030-01-10': { state: 'off', free: 0, capacity: 0 },
        '2030-01-11': { state: 'blocked', free: 0, capacity: 0 },
        '2030-01-12': { state: 'past', free: 0, capacity: 0 },
    };

    const mountCal = (props = {}) =>
        mount(MiniCalendar, { props: { modelValue: '2030-01-07', locale: 'pt-BR', availability, t, ...props } });

    it('marca cada dia pelo estado e descreve "N livres de M" para leitor de tela e tooltip', () => {
        const wrapper = mountCal();
        const day = (date) => wrapper.find(`[data-date="${date}"]`);

        expect(day('2030-01-07').attributes('data-avail')).toBe('available');
        expect(day('2030-01-07').find('.mini-cal-mark').exists()).toBe(true);
        expect(day('2030-01-08').attributes('aria-label')).toBe('8 de janeiro: 2 livres de 16 (Poucas vagas)');
        expect(day('2030-01-08').attributes('title')).toBe('8 de janeiro: 2 livres de 16 (Poucas vagas)');
        expect(day('2030-01-09').classes()).toContain('avail-full');
        expect(day('2030-01-10').attributes('aria-label')).toBe('10 de janeiro: Sem expediente');
        expect(day('2030-01-11').attributes('data-avail')).toBe('blocked');
        // Passado e sem dado: sem marcador.
        expect(day('2030-01-12').find('.mini-cal-mark').exists()).toBe(false);
        expect(day('2030-01-20').find('.mini-cal-mark').exists()).toBe(false);
    });

    it('legenda com os mesmos marcadores; sem vagas carregadas, sem legenda', () => {
        expect(mountCal().find('[data-testid="availability-legend"]').text()).toContain('Poucas vagas');
        expect(mountCal({ availability: null }).find('[data-testid="availability-legend"]').exists()).toBe(false);
    });

    it('explica a falta de escala sem apresentar uma legenda de vagas que não podem ser calculadas', async () => {
        const wrapper = mountCal({
            availability: { '2030-01-07': { state: 'none', free: 0, capacity: 0 } },
        });

        expect(wrapper.find('[data-testid="availability-legend"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="availability-no-schedule"]').text()).toBe(t.avail_no_schedule);
        expect(wrapper.find('[data-date="2030-01-07"]').attributes('data-avail')).toBeUndefined();

        await wrapper.setProps({ availability });

        expect(wrapper.find('[data-testid="availability-no-schedule"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="availability-legend"]').exists()).toBe(true);
        expect(wrapper.find('[data-date="2030-01-07"]').attributes('data-avail')).toBe('available');
    });

    it('avisa o mês exibido (ao abrir e ao navegar) e mantém a escolha do dia', async () => {
        const wrapper = mountCal();
        expect(wrapper.emitted('month-change')[0]).toEqual(['2030-01']);

        await wrapper.find('.mini-cal-nav[aria-label="Próximo mês"]').trigger('click');
        expect(wrapper.emitted('month-change').at(-1)).toEqual(['2030-02']);

        await wrapper.find('[data-date="2030-02-14"]').trigger('click');
        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual(['2030-02-14']);
    });
});

describe('SlotPicker — modo encaixe', () => {
    const response = {
        has_schedule: true,
        interval: 15,
        slots: [
            { time: '09:00', datetime: '2030-01-07T09:00', available: false },
            { time: '09:15', datetime: '2030-01-07T09:15', available: true },
        ],
        fit_in: {
            morning: { limit: 2, used: 2 },
            afternoon: { limit: 2, used: 0 },
            evening: { limit: 0, used: 0 },
        },
    };

    beforeEach(() => {
        globalThis.fetch = vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve(response) }));
    });

    const mountPicker = (props = {}) =>
        mount(SlotPicker, {
            props: {
                modelValue: '',
                doctorId: 'd1',
                t: {
                    form_slot_occupied: 'Ocupado',
                    shift_morning: 'Manhã',
                    shift_afternoon: 'Tarde',
                    shift_evening: 'Noite',
                    fit_in_usage: ':used de :limit',
                    fit_in_usage_none: 'não aceita',
                },
                ...props,
            },
        });

    it('normal: horário ocupado fica bloqueado', async () => {
        const wrapper = mountPicker();
        await wrapper.vm.reset('2030-01-07');
        await flushPromises();

        const occupied = wrapper.findAll('button').find((b) => b.text() === '09:00');
        expect(occupied.attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-test="fit-in-usage"]').exists()).toBe(false);
    });

    it('encaixe: horário ocupado pode ser escolhido e os encaixes do dia aparecem por turno', async () => {
        const wrapper = mountPicker({ allowOccupied: true });
        await wrapper.vm.reset('2030-01-07');
        await flushPromises();

        const occupied = wrapper.findAll('button').find((b) => b.text() === '09:00');
        expect(occupied.attributes('disabled')).toBeUndefined();
        await occupied.trigger('click');
        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual(['2030-01-07T09:00']);

        const usage = wrapper.find('[data-test="fit-in-usage"]').text();
        expect(usage).toContain('Manhã: 2 de 2');
        expect(usage).toContain('Tarde: 0 de 2');
        expect(usage).toContain('Noite: não aceita');
    });

    it('encaixe fora da grade (09:07, digitado) não é apagado pela atualização dos horários', async () => {
        // Monta já no horário digitado; a busca dos horários (montagem e a
        // atualização seguinte) não o encontra na grade.
        const fitIn = mountPicker({ allowOccupied: true, modelValue: '2030-01-07T09:07' });
        await flushPromises();
        await fitIn.vm.reset('2030-01-07');
        await flushPromises();

        expect(globalThis.fetch).toHaveBeenCalledTimes(2);
        expect(fitIn.emitted('update:modelValue')).toBeUndefined();

        // Sem encaixe, o horário que não existe na grade é descartado (regra de sempre).
        const normal = mountPicker({ modelValue: '2030-01-07T09:07' });
        await flushPromises();
        expect(normal.emitted('update:modelValue').at(-1)).toEqual(['']);
    });
});
