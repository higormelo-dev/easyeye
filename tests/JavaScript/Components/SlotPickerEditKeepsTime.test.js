import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import SlotPicker from '@/Components/Panel/SlotPicker.vue';

/**
 * "Editar" agendamento de outro dia: o formulário entrega o horário salvo e o
 * SlotPicker navega para aquela data — sem limpar o horário (antes limpava e
 * o PUT ia com date_time vazio → 422, obrigando a escolher o horário de novo
 * só para mudar uma observação ou manter o convênio). Trocar a data na mão
 * continua limpando.
 */
const slotsFor = (date) => ({
    has_schedule: true,
    interval: 15,
    slots: ['08:00', '08:15', '08:30'].map((time) => ({ time, datetime: `${date}T${time}`, available: true })),
});

beforeEach(() => {
    globalThis.fetch = vi.fn((url) => {
        const date = new URL(url, 'http://x').searchParams.get('date');
        return Promise.resolve({ ok: true, json: () => Promise.resolve(slotsFor(date)) });
    });
});

function mountPicker(modelValue = '') {
    return mount(SlotPicker, {
        props: { modelValue, doctorId: 'd1', scheduleId: 's1', t: {} },
    });
}

describe('SlotPicker — modo edição', () => {
    it('horário salvo de outro dia é mantido ao sincronizar a data', async () => {
        const wrapper = mountPicker('');
        await flushPromises();

        await wrapper.setProps({ modelValue: '2030-01-15T08:15' });
        wrapper.vm.setDate('2030-01-15');
        await flushPromises();

        expect(wrapper.find('input[type="date"]').element.value).toBe('2030-01-15');
        expect((wrapper.emitted('update:modelValue') ?? []).flat()).not.toContain('');
    });

    it('trocar a data na mão continua limpando o horário', async () => {
        const wrapper = mountPicker('2030-01-15T08:15');
        await flushPromises();

        await wrapper.find('input[type="date"]').setValue('2030-01-16');
        await flushPromises();

        expect((wrapper.emitted('update:modelValue') ?? []).flat()).toContain('');
    });

    it('já monta na data do horário salvo (1ª busca é a do dia do agendamento)', async () => {
        const wrapper = mountPicker('2030-01-15T08:30');
        await flushPromises();

        expect(new URL(globalThis.fetch.mock.calls[0][0], 'http://x').searchParams.get('date')).toBe('2030-01-15');
        expect(wrapper.find('input[type="date"]').element.value).toBe('2030-01-15');
        expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    });

    it('resposta atrasada de outra data não apaga o horário do agendamento em edição', async () => {
        let releaseToday;
        globalThis.fetch = vi.fn((url) => {
            const date = new URL(url, 'http://x').searchParams.get('date');
            const response = { ok: true, json: () => Promise.resolve(slotsFor(date)) };
            if (date === '2030-01-15') return Promise.resolve(response);
            return new Promise((resolve) => (releaseToday = () => resolve(response)));
        });

        const wrapper = mountPicker('');
        await wrapper.setProps({ modelValue: '2030-01-15T08:15' });
        await flushPromises();
        releaseToday();
        await flushPromises();

        expect((wrapper.emitted('update:modelValue') ?? []).flat()).not.toContain('');
    });
});
