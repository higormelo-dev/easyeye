import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async () => (await import('./inertiaMock.js')).inertiaMock());

const { __router } = await import('@inertiajs/vue3');
const ChecklistTab = (await import('@/Components/Surgery/Tabs/ChecklistTab.vue')).default;

const t = {
    schedule: {
        checklist: {
            answers: { yes: 'Sim', no: 'Não', na: 'Não se aplica' },
            ui: {
                case_not_open: 'Checklist indisponível',
                time_out_needed: 'Feche a Pausa',
                state: { not_started: 'Não iniciada', open: 'Em andamento', closed: 'Fechada' },
                progress: ':done de :total itens',
                safety_title: 'Segurança',
                allergies: 'Alergias',
                allergies_denied: 'Nega alergias',
                allergies_unknown: 'Alergias não informadas',
                risks: 'Riscos',
                eye_and_anesthesia: 'Olho: :eye · Anestesia: :anesthesia',
                anesthesia_unset: 'não definida',
                plan_title: 'Plano',
                no_iol_plan: 'Sem LIO',
                override_title: 'Exceção do cirurgião',
                critical: 'crítico',
                suggested_na: 'sugestão: não se aplica',
                note: 'Nota',
                note_required: 'Nota obrigatória',
                closed_by: 'Fechada por :user em :at.',
                hash: 'Integridade',
                catalog: 'catálogo',
                apply_suggestions: 'Aplicar sugestões',
                save: 'Salvar respostas',
                close: 'Fechar fase',
            },
        },
    },
};

const item = (key, over = {}) => ({
    key,
    text: `Texto ${key}`,
    hint: null,
    critical: false,
    allow_na: false,
    suggested_na: false,
    answer: null,
    note: null,
    ...over,
});

const phase = (name, items, over = {}) => ({
    phase: name,
    label: `Fase ${name}`,
    when: 'quando',
    state: 'not_started',
    items,
    answered: 0,
    total: items.length,
    closed_at: null,
    closed_by: null,
    hash: null,
    catalog: '2026.1',
    override: null,
    ...over,
});

const section = (over = {}) => ({
    catalog_version: '2026.1',
    accepts_work: true,
    can_answer: true,
    time_out_satisfied: false,
    phases: [
        phase('sign_in', [
            item('eye_marked', { critical: true }),
            item('fasting', { allow_na: true, suggested_na: true }),
        ]),
        phase('time_out', [item('team_introduced')]),
        phase('sign_out', [item('recovery_plan', { critical: true })]),
    ],
    safety: {
        allergies: { state: 'has', items: [{ substance: 'Dipirona' }] },
        medications: [],
        risk_alerts: [{ alert: 'Íris flácida', medications: ['Tansulosina'] }],
    },
    iol_plans: [{ eye: 'OD', model: 'LIO X', power: '21.50' }],
    eye: 'OD',
    anesthesia: 'Tópica',
    urls: { save: '/s/__PHASE__', close: '/c/__PHASE__' },
    ...over,
});

const surgicalCase = { id: 'c1', status: { value: 'scheduled' } };

const mountTab = (sec = section()) =>
    mount(ChecklistTab, { props: { section: sec, surgicalCase, can: {}, urls: {}, options: {}, t } });

describe('ChecklistTab', () => {
    beforeEach(() => {
        Object.values(__router).forEach((fn) => fn.mockClear());
    });

    it('mostra as 3 fases e o retrato de segurança na Entrada e o plano na Pausa', () => {
        const wrapper = mountTab();

        expect(wrapper.findAll('article')).toHaveLength(3);
        expect(wrapper.find('[data-testid="safety-panel"]').text()).toContain('Dipirona');
        expect(wrapper.find('[data-testid="safety-panel"]').text()).toContain('Íris flácida (Tansulosina)');
        expect(wrapper.find('[data-testid="iol-panel"]').text()).toContain('LIO X (21.50 D)');
        expect(wrapper.find('[data-testid="phase-sign_in"]').text()).toContain('0 de 2 itens');
    });

    it('responde e salva só o que foi respondido (PUT) com nota', async () => {
        const wrapper = mountTab();

        await wrapper.find('#sign_in-eye_marked-no').setValue();
        await wrapper.find('#sign_in-eye_marked-note').setValue('Refeita a marcação');
        await wrapper.find('[data-testid="save-sign_in"]').trigger('click');

        expect(__router.put).toHaveBeenCalledWith(
            '/s/sign_in',
            { answers: [{ key: 'eye_marked', answer: 'no', note: 'Refeita a marcação' }] },
            expect.any(Object),
        );
    });

    it('"não se aplica" só existe onde o catálogo permite', () => {
        const wrapper = mountTab();

        expect(wrapper.find('#sign_in-fasting-na').exists()).toBe(true);
        expect(wrapper.find('#sign_in-eye_marked-na').exists()).toBe(false);
    });

    it('sugestão de "não se aplica" é só um selo até a pessoa aplicar', async () => {
        const wrapper = mountTab();

        expect(wrapper.find('[data-testid="item-fasting"]').text()).toContain('sugestão: não se aplica');
        expect(wrapper.find('#sign_in-fasting-na').element.checked).toBe(false);

        await wrapper
            .findAll('button')
            .find((b) => b.text() === 'Aplicar sugestões')
            .trigger('click');

        expect(wrapper.find('#sign_in-fasting-na').element.checked).toBe(true);
    });

    it('item crítico "não" sem nota marca o campo como inválido e pede a nota', async () => {
        const wrapper = mountTab();

        await wrapper.find('#sign_in-eye_marked-no').setValue();

        expect(wrapper.find('#sign_in-eye_marked-note').classes()).toContain('is-invalid');
        expect(wrapper.find('[data-testid="item-eye_marked"]').text()).toContain('Nota obrigatória');
    });

    it('fechar manda todas as respostas num POST único', async () => {
        const wrapper = mountTab();

        await wrapper.find('#time_out-team_introduced-yes').setValue();
        await wrapper.find('[data-testid="close-time_out"]').trigger('click');

        expect(__router.post).toHaveBeenCalledWith(
            '/c/time_out',
            { answers: [{ key: 'team_introduced', answer: 'yes', note: null }] },
            expect.any(Object),
        );
    });

    it('erros de validação do servidor aparecem na fase', async () => {
        const wrapper = mountTab();
        await wrapper.find('[data-testid="close-sign_in"]').trigger('click');

        __router.post.mock.calls[0][2].onError({ checklist: ['Item sem resposta: Texto eye_marked'] });
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="phase-sign_in"] [data-testid="phase-errors"]').text()).toContain(
            'Item sem resposta',
        );
    });

    it('fase fechada é leitura: sem botões, campos travados, com hash e quem fechou', () => {
        const closed = section({
            phases: [
                phase('sign_in', [item('eye_marked', { answer: 'yes', critical: true })], {
                    state: 'closed',
                    closed_by: 'Ana',
                    closed_at: '2026-10-19T09:00:00-03:00',
                    hash: 'abcdef0123456789xx',
                }),
                phase('time_out', [item('team_introduced')]),
                phase('sign_out', [item('recovery_plan')]),
            ],
        });
        const wrapper = mountTab(closed);

        expect(wrapper.find('[data-testid="save-sign_in"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="close-sign_in"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="item-eye_marked"] fieldset').attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-testid="closed-info"]').text()).toContain('Ana');
        expect(wrapper.find('[data-testid="closed-info"]').text()).toContain('abcdef012345');
        expect(wrapper.find('[data-testid="close-time_out"]').exists()).toBe(true);
    });

    it('sem permissão de responder não há botões e os campos ficam travados', () => {
        const wrapper = mountTab(section({ can_answer: false }));

        expect(wrapper.find('[data-testid="save-sign_in"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="item-eye_marked"] fieldset').attributes('disabled')).toBeDefined();
    });

    it('mostra a exceção do cirurgião na Pausa', () => {
        const wrapper = mountTab(
            section({
                phases: [
                    phase('sign_in', [item('a')]),
                    phase('time_out', [item('b')], {
                        state: 'open',
                        override: {
                            reason: 'Urgência: dor intensa',
                            doctor: 'Dr. Cirurgião',
                            at: '2026-10-19T09:00:00-03:00',
                        },
                    }),
                    phase('sign_out', [item('c')]),
                ],
                time_out_satisfied: true,
            }),
        );

        expect(wrapper.find('[data-testid="override-notice"]').text()).toContain('Urgência: dor intensa');
        expect(wrapper.find('[data-testid="override-notice"]').text()).toContain('Dr. Cirurgião');
    });

    it('avisa que a Pausa precisa estar fechada enquanto a cirurgia está agendada', () => {
        expect(mountTab().text()).toContain('Feche a Pausa');
        expect(mountTab(section({ time_out_satisfied: true })).text()).not.toContain('Feche a Pausa');
    });
});
