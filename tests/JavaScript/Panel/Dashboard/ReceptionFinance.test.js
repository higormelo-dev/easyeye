import { describe, it, expect } from 'vitest';
import { mount as baseMount } from '@vue/test-utils';
import WaitingRoom from '@/Pages/Panel/Dashboard/WaitingRoom.vue';
import ConfirmationsPanel from '@/Pages/Panel/Dashboard/ConfirmationsPanel.vue';
import WaitList from '@/Pages/Panel/Dashboard/WaitList.vue';
import BirthdaysToday from '@/Pages/Panel/Dashboard/BirthdaysToday.vue';
import DoctorsToday from '@/Pages/Panel/Dashboard/DoctorsToday.vue';
import FinanceToday from '@/Pages/Panel/Dashboard/FinanceToday.vue';
import CovenantBilling from '@/Pages/Panel/Dashboard/CovenantBilling.vue';
import { T } from './fixtures.js';

/**
 * Dashboard v2 — recepção (sala de espera, confirmações, lista de espera,
 * aniversariantes), gestão (atendimentos por médico) e financeiro (caixa de
 * hoje, a receber, glosas, faturado × recebido). Estados vazios viram uma
 * linha discreta; telefone vira link de discagem; tudo traduzido.
 */
const mount = (component, options = {}) =>
    baseMount(component, { ...options, global: { mocks: { route: globalThis.route } } });

describe('WaitingRoom', () => {
    const person = (id, minutes, state = 'ready') => ({
        id,
        name: `Paciente ${id}`,
        doctor: 'Dra. Ana',
        arrived_time: '09:05',
        waiting_minutes: minutes,
        state,
        label: 'Waiting',
        badge: 'bg-warning text-dark',
    });

    it('por ordem de chegada, com o tempo de espera colorido (30 min âmbar, 60 vermelho)', () => {
        const room = {
            count: 4,
            in_care: 1,
            longest: 65,
            items: [person('a', 65, 'preparing'), person('b', 35), person('c', 5)],
        };
        const w = mount(WaitingRoom, { props: { room, t: T } });

        expect(w.findAll('.db-list__title').map((n) => n.text())).toEqual(['Paciente a', 'Paciente b', 'Paciente c']);
        expect(w.findAll('.db-wait').map((n) => n.classes().find((c) => c.startsWith('db-wait--')))).toEqual([
            'db-wait--danger',
            'db-wait--warning',
            'db-wait--ok',
        ]);
        expect(w.text()).toContain('arrived at 09:05');
        expect(w.text()).toContain('1 in consultation');
        expect(w.find('[role="note"]').text()).toBe('+ 1 in the full list');
        expect(w.attributes('data-tour')).toBe('dashboard-waiting-room');
    });

    it('vazia: uma linha discreta', () => {
        const w = mount(WaitingRoom, { props: { room: { count: 0, in_care: 0, items: [] }, t: T } });

        expect(w.find('.db-empty--inline').text()).toBe('Nobody is waiting right now.');
        expect(w.find('.db-list').exists()).toBe(false);
    });
});

describe('Confirmations — hoje e amanhã', () => {
    const reception = {
        truncated: false,
        days: {
            today: {
                date: '2026-10-06',
                total: 14,
                confirmed: 9,
                unconfirmed: 5,
                whatsapp_confirmed: 3,
                whatsapp: { awaiting: 2, queued: 0, failed: 1, none: 2 },
                shifts: [
                    { key: 'morning', total: 8, confirmed: 6 },
                    { key: 'afternoon', total: 6, confirmed: 3 },
                ],
                to_call: [
                    {
                        id: 's1',
                        time: '14:00',
                        name: 'Bruno Batista',
                        doctor: 'Dra. Ana',
                        whatsapp: 'failed',
                        phone: '(11) 98765-0002',
                        phone_href: 'tel:+5511987650002',
                    },
                    { id: 's2', time: '15:00', name: 'Sem Telefone', doctor: 'Dra. Ana', whatsapp: null },
                ],
                to_call_total: 4,
                url: '/agenda?date=2026-10-06',
            },
            tomorrow: {
                date: '2026-10-07',
                total: 0,
                confirmed: 0,
                unconfirmed: 0,
                whatsapp_confirmed: 0,
                whatsapp: { awaiting: 0, queued: 0, failed: 0, none: 0 },
                shifts: [],
                to_call: [],
                to_call_total: 0,
                url: '/agenda?date=2026-10-07',
            },
        },
    };

    it('confirmadas × sem confirmação, WhatsApp só com o que tem, turnos e quem ligar (telefone discável)', () => {
        const w = mount(ConfirmationsPanel, { props: { reception, canOpenSchedule: true, t: T } });
        const today = w.find('[data-day="today"]');

        expect(today.find('[data-confirm="confirmed"]').text()).toBe('9');
        expect(today.text()).toContain('of 14 confirmed');
        expect(today.text()).toContain('64%');
        expect(today.find('[data-confirm="unconfirmed"]').text()).toBe('5 not confirmed');
        expect(today.text()).toContain('3 via WhatsApp');
        expect(today.findAll('[data-wa]').map((c) => c.attributes('data-wa'))).toEqual(['awaiting', 'failed', 'none']);
        expect(today.findAll('[data-shift]').map((s) => s.text().replace(/\s+/g, ' '))).toEqual([
            'Morning 8 (6 conf.)',
            'Afternoon 6 (3 conf.)',
        ]);

        const call = today.find('a[href="tel:+5511987650002"]');
        expect(call.attributes('aria-label')).toBe('Call Bruno Batista — (11) 98765-0002');
        expect(today.text()).toContain('WhatsApp failed');
        expect(today.text()).toContain('No phone');
        expect(today.find('[role="note"]').text()).toBe('+ 2 in the full list');
        expect(today.find('a.db-link').attributes('href')).toBe('/agenda?date=2026-10-06');
    });

    it('dia sem consultas: linha discreta; sem acesso à Agenda: sem link', () => {
        const w = mount(ConfirmationsPanel, { props: { reception, canOpenSchedule: false, t: T } });

        expect(w.find('[data-day="tomorrow"] .db-empty--inline').text()).toBe('No appointments booked.');
        expect(w.findAll('a.db-link')).toHaveLength(0);
        expect(w.attributes('data-tour')).toBe('dashboard-confirmations');
    });
});

describe('WaitList e Birthdays', () => {
    it('lista de espera: médico, período desejado e há quanto tempo; vazia = linha discreta', () => {
        const waitlist = {
            count: 6,
            url: '/agenda',
            items: [
                {
                    id: 'w1',
                    name: 'Mariana',
                    doctor: 'Dra. Ana',
                    from: '09/10/2026',
                    until: '16/10/2026',
                    days: 4,
                    phone: '(11) 9',
                    phone_href: 'tel:+5511988887777',
                },
                { id: 'w2', name: 'Ester', doctor: 'Dr. Bruno', from: null, until: null, days: 0 },
            ],
        };
        const w = mount(WaitList, { props: { waitlist, canOpenSchedule: true, t: T } });

        expect(w.findAll('.db-list__sub').map((n) => n.text())).toEqual([
            'Dra. Ana · from 09/10/2026 to 16/10/2026 · 4 days ago',
            'Dr. Bruno · added today',
        ]);
        expect(w.find('a[href="tel:+5511988887777"]').exists()).toBe(true);
        expect(w.find('[role="note"]').text()).toBe('+ 4 in the full list');

        const empty = mount(WaitList, { props: { waitlist: { count: 0, items: [] }, t: T } });
        expect(empty.find('.db-empty--inline').text()).toBe('The waiting list is empty.');
    });

    it('aniversariantes: idade, ligar e cadastro só com acesso a Pacientes; nenhum = linha discreta', () => {
        const birthdays = {
            count: 1,
            items: [
                {
                    id: 'p1',
                    name: 'Sônia',
                    age: 65,
                    url: '/patients?open=p1',
                    phone: '(11) 9',
                    phone_href: 'tel:+5511991234567',
                },
            ],
        };

        const w = mount(BirthdaysToday, { props: { birthdays, canOpenPatients: true, t: T } });
        expect(w.text()).toContain('Turns 65');
        expect(w.find('a[href="/patients?open=p1"]').exists()).toBe(true);

        const noAccess = mount(BirthdaysToday, { props: { birthdays, canOpenPatients: false, t: T } });
        expect(noAccess.find('a[href="/patients?open=p1"]').exists()).toBe(false);

        expect(
            mount(BirthdaysToday, { props: { birthdays: { count: 0, items: [] }, t: T } })
                .find('.db-empty--inline')
                .text(),
        ).toBe('No patient has a birthday today.');
    });
});

describe('DoctorsToday', () => {
    it('atendidos sobre o previsto por médico, quem está na clínica e faltas; nenhum paciente', () => {
        const doctors = {
            others: 0,
            items: [
                { id: 'd1', name: 'Dra. Ana', total: 10, attended: 4, missed: 2, waiting: 1, in_care: 1, expected: 8 },
            ],
        };
        const w = mount(DoctorsToday, { props: { doctors, t: T } });
        const cells = w.findAll('tbody td').map((td) => td.text());

        expect(w.findAll('thead th').map((th) => th.text())).toEqual(['Doctor', 'Attended', 'In clinic', 'No-shows']);
        expect(cells[0]).toContain('4 / 8');
        expect(cells[0]).toContain('4 of 8 attended');
        expect(cells.slice(1)).toEqual(['1', '2']);
        expect(w.find('.db-mini-bar span').attributes('style')).toContain('width: 50%');

        expect(
            mount(DoctorsToday, { props: { doctors: { items: [], others: 0 }, t: T } })
                .find('.db-empty--inline')
                .text(),
        ).toBe('No appointments today.');
    });
});

describe('FinanceToday — caixa de hoje, a receber, glosas', () => {
    const cash = {
        received: 1260,
        paid: 180,
        realized_balance: 1080,
        receivable: 350,
        payable: 120,
        projected_balance: 1310,
        entries_count: 4,
        url: '/cash',
    };
    const receivables = {
        total: 2910,
        overdue: 920,
        cash: {
            upcoming: 1550,
            upcoming_count: 3,
            overdue: 480,
            overdue_count: 1,
            url: '/cash?pending',
            overdue_url: '/cash?overdue',
        },
        claims: { open: 880, open_count: 4, overdue: 440, overdue_count: 2, url: '/billing?submitted' },
    };
    const glosas = {
        open: 295.5,
        open_count: 3,
        appealed: 210,
        appealed_count: 1,
        overdue: 140,
        overdue_count: 1,
        due_soon: 95.5,
        due_soon_count: 1,
        due_soon_days: 5,
        url: '/glosas',
        overdue_url: '/glosas?due=overdue',
        due_soon_url: '/glosas?due=soon',
    };

    it('valores no formato do idioma, saldo com sinal, vencidos em destaque e links com o mesmo recorte', () => {
        const w = mount(FinanceToday, { props: { cash, receivables, glosas, t: T } });

        expect(w.find('[data-cash="received"]').text()).toContain('1.260,00');
        expect(w.find('[data-cash="balance"]').text()).toMatch(/^\+R\$/);
        expect(w.find('[data-cash="projected"]').text()).toContain('1.310,00');
        expect(w.find('[data-receivable="total"]').text()).toContain('2.910,00');
        expect(w.findAll('.db-rows--alert')).toHaveLength(3); // particulares vencidos, guias vencidas, prazo de glosa vencido
        expect(w.find('a[href="/cash?overdue"]').text()).toBe('Private payments overdue (1)');
        expect(w.find('a[href="/glosas?due=soon"]').text()).toBe('Deadline within 5 days (1)');
        expect(w.find('[data-glosa="open"]').text()).toContain('505,50');
        ['dashboard-finance-today', 'dashboard-receivables', 'dashboard-glosas'].forEach((anchor) =>
            expect(w.find(`[data-tour="${anchor}"]`).exists()).toBe(true),
        );
    });

    it('sem glosa pendente: linha discreta; recarregando: esqueleto nos blocos de gestão', () => {
        const none = mount(FinanceToday, {
            props: { cash, receivables, glosas: { ...glosas, open_count: 0, appealed_count: 0 }, t: T },
        });
        expect(none.find('[data-tour="dashboard-glosas"] .db-empty--inline').text()).toBe('No pending claim denials.');

        const loading = mount(FinanceToday, { props: { cash, receivables, glosas, loading: true, t: T } });
        expect(loading.findAll('.db-chart-skeleton')).toHaveLength(2);
        expect(loading.find('[data-cash="received"]').exists()).toBe(true); // caixa de hoje vem do polling
    });
});

describe('CovenantBilling', () => {
    it('faturado × recebido por convênio com a barra proporcional e resumo acessível', () => {
        const rows = [
            { covenant_id: 'c1', label: 'UNIMED', value: 2000, paid: 1500, denied: 100 },
            { covenant_id: 'c2', label: 'BRADESCO', value: 1000, paid: 1000, denied: 0 },
        ];
        const w = mount(CovenantBilling, { props: { rows, totals: { billed: 3000, paid: 2500 }, t: T } });

        const items = w.findAll('.db-covenants__item');
        expect(items).toHaveLength(2);
        expect(items[0].find('.db-covenants__bar').attributes('style')).toContain('width: 100%');
        expect(items[1].find('.db-covenants__bar').attributes('style')).toContain('width: 50%');
        expect(items[0].find('.db-covenants__paid').attributes('style')).toContain('width: 75%');
        expect(items[0].find('[role="img"]').attributes('aria-label')).toMatch(/^UNIMED: billed/);
        expect(w.text()).toContain('Collection: 83,3%');

        expect(
            mount(CovenantBilling, { props: { rows: [], t: T } })
                .find('.db-empty--inline')
                .text(),
        ).toBe('No claims billed this month.');
    });
});
