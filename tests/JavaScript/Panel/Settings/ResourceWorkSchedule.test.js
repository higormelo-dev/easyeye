import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';

/**
 * Tela "Escala e bloqueios" da sala/equipamento (Configurações › Recursos):
 * grade semanal com faixas de horário e bloqueios (período + motivo). Mesmo
 * contrato das rotas resources.work-schedule.sync / resources.blocks.*.
 */
vi.mock('@/Layouts/AppLayout.vue', () => ({
    default: { props: ['title', 'breadcrumbs'], template: '<div><slot /></div>' },
}));

const WorkSchedule = (await import('@/Pages/Panel/Settings/Resources/WorkSchedule.vue')).default;

const t = {
    title: 'Escala e bloqueios',
    back: 'Voltar para Recursos',
    active_days_zero: 'Nenhum dia ativo',
    active_days_one: '1 dia ativo',
    active_days_other: ':count dias ativos',
    day_toggle: 'Disponível em :day',
    add_range: 'Faixa',
    add_range_aria: 'Adicionar faixa de horário em :day',
    range_start: 'Início da faixa em :day',
    range_end: 'Fim da faixa em :day',
    remove_range: 'Remover faixa de horário',
    save: 'Salvar escala',
    saved: 'Escala salva com sucesso.',
    save_error: 'Não foi possível salvar a escala.',
    block_add: 'Adicionar bloqueio',
    block_cancel: 'Cancelar',
    block_save: 'Salvar bloqueio',
    block_confirm: 'Remover este bloqueio?',
    block_remove_aria: 'Remover bloqueio de :start a :end',
    no_blocks: 'Nenhum bloqueio futuro.',
};

const names = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
const days = () =>
    names.map((name, day) => ({
        day,
        name,
        active: day === 1,
        ranges: [{ starts_at: '08:00', ends_at: '12:00' }],
    }));

function mountPage(extra = {}) {
    return mount(WorkSchedule, {
        props: {
            resource: { id: 'r1', code: 'REC-0001', name: 'SALA 1', type: 'room', type_label: 'Sala', active: true },
            days: days(),
            blocks: [
                {
                    id: 'b1',
                    starts_at: '10/10/2026 08:00',
                    ends_at: '10/10/2026 12:00',
                    reason: 'Manutenção',
                    type: 'meeting',
                    type_label: 'Manutenção',
                },
            ],
            blockTypes: [
                { value: 'absence', label: 'Indisponível' },
                { value: 'meeting', label: 'Manutenção' },
            ],
            urls: { sync: '/sync', store_block: '/blocks', destroy_block: '/blocks', back: '/back' },
            t,
            ...extra,
        },
    });
}

const json = (status, body) => Promise.resolve({ ok: status < 300, status, json: () => Promise.resolve(body) });

beforeEach(() => {
    vi.clearAllMocks();
    window.showSuccessToast = vi.fn();
    window.showErrorToast = vi.fn();
});

describe('Escala e bloqueios do recurso', () => {
    it('mostra o recurso, a contagem de dias ativos e campos acessíveis por dia', () => {
        const wrapper = mountPage();

        expect(wrapper.text()).toContain('SALA 1');
        expect(wrapper.text()).toContain('1 dia ativo');
        expect(wrapper.find('#rs-day-1').attributes('aria-label')).toBe('Disponível em Segunda');
        expect(wrapper.find('[aria-label="Início da faixa em Segunda"]').exists()).toBe(true);
        expect(wrapper.find('[aria-label="Fim da faixa em Segunda"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/back"]').exists()).toBe(true);
    });

    it('liga um dia, adiciona faixa e salva a grade inteira (PUT)', async () => {
        globalThis.fetch = vi.fn(() => json(200, { message: 'Disponibilidade salva com sucesso.' }));
        const wrapper = mountPage();

        await wrapper.find('#rs-day-2').setValue(true);
        expect(wrapper.text()).toContain('2 dias ativos');

        await wrapper.find('[data-testid="day-2"] [data-testid="add-range"]').trigger('click');
        await wrapper.find('[data-testid="save-schedule"]').trigger('click');
        await flushPromises();

        const [url, init] = globalThis.fetch.mock.calls[0];
        expect(url).toBe('/sync');
        expect(init.method).toBe('PUT');
        const body = JSON.parse(init.body);
        expect(body.days).toHaveLength(7);
        expect(body.days[2].active).toBe(true);
        expect(body.days[2].ranges).toHaveLength(2);
        expect(window.showSuccessToast).toHaveBeenCalledWith('Disponibilidade salva com sucesso.');
    });

    it('422 do servidor (faixa inválida) aparece como alerta', async () => {
        globalThis.fetch = vi.fn(() =>
            json(422, { message: 'Segunda: o fim da faixa (08:00) precisa ser depois do início (12:00).' }),
        );
        const wrapper = mountPage();

        await wrapper.find('[data-testid="save-schedule"]').trigger('click');
        await flushPromises();

        const alert = wrapper.find('[data-testid="save-error"]');
        expect(alert.attributes('role')).toBe('alert');
        expect(alert.text()).toContain('precisa ser depois do início');
    });

    it('cria bloqueio com tipo, período e motivo; erros de validação aparecem no campo', async () => {
        globalThis.fetch = vi
            .fn()
            .mockImplementationOnce(() => json(422, { errors: { ends_at: ['O fim deve ser depois do início.'] } }))
            .mockImplementationOnce(() =>
                json(201, {
                    message: 'Bloqueio criado com sucesso.',
                    data: {
                        id: 'b2',
                        starts_at: '12/10/2026 08:00',
                        ends_at: '12/10/2026 18:00',
                        reason: 'Feriado local',
                        type_label: 'Indisponível',
                    },
                }),
            );
        const wrapper = mountPage();

        await wrapper.find('[data-testid="toggle-block-form"]').trigger('click');
        await wrapper.find('#rs-block-type').setValue('meeting');
        await wrapper.find('#rs-block-starts').setValue('2026-10-12T08:00');
        await wrapper.find('#rs-block-ends').setValue('2026-10-12T07:00');
        await wrapper.find('#rs-block-reason').setValue('Feriado local');
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(JSON.parse(globalThis.fetch.mock.calls[0][1].body)).toEqual({
            type: 'meeting',
            starts_at: '2026-10-12T08:00',
            ends_at: '2026-10-12T07:00',
            reason: 'Feriado local',
        });
        expect(wrapper.text()).toContain('O fim deve ser depois do início.');

        await wrapper.find('#rs-block-ends').setValue('2026-10-12T18:00');
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(wrapper.findAll('[data-testid="blocks-list"] li')).toHaveLength(2);
        expect(wrapper.find('form').exists()).toBe(false);
    });

    it('remove bloqueio só depois de confirmar', async () => {
        globalThis.fetch = vi.fn(() => json(200, { message: 'Bloqueio removido.' }));
        const wrapper = mountPage();
        const remove = wrapper.find('[aria-label="Remover bloqueio de 10/10/2026 08:00 a 10/10/2026 12:00"]');

        window.confirm = vi.fn(() => false);
        await remove.trigger('click');
        expect(globalThis.fetch).not.toHaveBeenCalled();

        window.confirm = vi.fn(() => true);
        await remove.trigger('click');
        await flushPromises();

        expect(globalThis.fetch.mock.calls[0][0]).toBe('/blocks/b1');
        expect(globalThis.fetch.mock.calls[0][1].method).toBe('DELETE');
        expect(wrapper.text()).toContain('Nenhum bloqueio futuro.');
    });
});
