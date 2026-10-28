import { afterEach, describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import SubscriptionExpired from '@/Pages/Panel/SubscriptionExpired.vue';

/**
 * Tela de acesso bloqueado (sem período de graça): o título e o texto dizem
 * por que bloqueou — teste grátis terminou, pagamento pendente ou assinatura
 * encerrada.
 */
const t = {
    title: 'Assinatura expirada',
    heading: 'Sua assinatura está inativa',
    heading_trial: 'Seu período de teste terminou',
    heading_payment: 'Aguardando o pagamento',
    blocked_entity: 'A empresa :name está com o acesso bloqueado.',
    blocked_generic: 'O acesso ao sistema está bloqueado.',
    blocked_trial: 'O teste grátis de :name acabou.',
    blocked_payment: 'O acesso de :name volta assim que o pagamento for confirmado.',
    last_plan: 'Último plano',
    ended_on: 'encerrada em :date',
    choose_plan: 'Escolha um plano',
    no_plans: 'Nenhum plano',
    contact_support: 'Dúvidas?',
    logout: 'Sair',
    heading_limited: 'Acesso limitado por pagamento em atraso',
    blocked_limited: 'O pagamento da assinatura de :name está em atraso.',
    limited_blocked_title: 'Bloqueados até o pagamento',
    limited_blocked_ai: 'Inteligência artificial',
    limited_blocked_financial: 'Módulo financeiro',
    limited_allowed: 'Agenda, pacientes e prontuário seguem liberados.',
    limited_deadline: 'Sem o pagamento, o acesso será suspenso em :date.',
    back_to_panel: 'Voltar ao painel',
    payment_title: 'Cobrança em aberto',
    payment_due: ':amount — vencimento em :date',
    payment_hint: 'O acesso é liberado assim que o pagamento for confirmado.',
    pay_now: 'Pagar agora',
    opens_new_tab: '(abre em nova aba)',
    ask_admin: 'Para regularizar o pagamento, procure o administrador da clínica.',
    due_on: 'venceu em :date',
    contact_link: 'Fale com a nossa equipe',
    no_link: 'O link de pagamento desta cobrança não está disponível aqui. Para pagar, fale com a nossa equipe.',
};

let wrapper;

afterEach(() => wrapper?.unmount());

function render(lastSubscription, entity = { id: 'e1', name: 'Clínica Visão' }, extra = {}) {
    wrapper = mount(SubscriptionExpired, {
        props: {
            entity,
            lastSubscription,
            plans: [],
            t,
            urls: { logout: '/logout', contact: '/#contato', dashboard: '/panel/dashboard' },
            ...extra,
        },
        global: {
            stubs: {
                AppLayout: { template: '<div><slot /></div>' },
                Link: { template: '<a><slot /></a>' },
            },
        },
    });

    return wrapper;
}

const heading = () => wrapper.get('[data-test="expired-heading"]').text();
const message = () => wrapper.get('[data-test="expired-message"]').text();

describe('SubscriptionExpired', () => {
    it('trial vencido: o teste grátis terminou', () => {
        render({ plan_name: 'Pro', status: 'expired', status_label: 'Expirado', was_trial: true });

        expect(heading()).toBe('Seu período de teste terminou');
        expect(message()).toBe('O teste grátis de Clínica Visão acabou.');
    });

    it('aguardando o 1º pagamento: o acesso volta quando confirmar', () => {
        render({ plan_name: 'Pro', status: 'past_due', status_label: 'Em atraso', was_trial: false });

        expect(heading()).toBe('Aguardando o pagamento');
        expect(message()).toBe('O acesso de Clínica Visão volta assim que o pagamento for confirmado.');
    });

    it('assinatura encerrada ou sem assinatura: mensagem geral', () => {
        render({ plan_name: 'Pro', status: 'cancelled', status_label: 'Cancelado', was_trial: false });
        expect(heading()).toBe('Sua assinatura está inativa');
        expect(message()).toBe('A empresa Clínica Visão está com o acesso bloqueado.');

        wrapper.unmount();
        render(null, null);
        expect(heading()).toBe('Sua assinatura está inativa');
        expect(message()).toBe('O acesso ao sistema está bloqueado.');
    });

    it('cobrança em aberto: "Pagar agora" com valor e vencimento no idioma, em nova aba', () => {
        render({ plan_name: 'Pro', status: 'past_due', status_label: 'Em atraso', was_trial: false }, undefined, {
            payment: { url: 'https://www.asaas.com/i/first_001', amount: 299.9, due_date: '2026-10-08' },
        });

        const card = wrapper.get('[data-test="expired-payment"]');
        const pay = wrapper.get('[data-test="expired-pay-now"]');

        expect(card.text()).toContain('Cobrança em aberto');
        expect(card.text().replace(/\u00a0/g, ' ')).toContain('R$ 299,90 — vencimento em 08/10/2026');
        expect(pay.attributes('href')).toBe('https://www.asaas.com/i/first_001');
        expect(pay.attributes('target')).toBe('_blank');
        expect(pay.attributes('rel')).toContain('noopener');
    });

    it('sem cobrança em aberto não mostra "Pagar agora"', () => {
        render({ plan_name: 'Pro', status: 'expired', status_label: 'Expirado', was_trial: false });

        expect(wrapper.find('[data-test="expired-payment"]').exists()).toBe(false);
    });

    it('perfil sem acesso à cobrança: sem "Pagar agora", com a orientação de procurar o administrador', () => {
        render({ plan_name: 'Pro', status: 'past_due', status_label: 'Em atraso', was_trial: false }, undefined, {
            mode: 'limited',
            limited: { days_overdue: 4, blocked_date: '2026-10-13' },
            canPay: false,
            payment: null,
        });

        expect(wrapper.find('[data-test="expired-pay-now"]').exists()).toBe(false);
        expect(wrapper.get('[data-test="expired-ask-admin"]').text()).toBe(
            'Para regularizar o pagamento, procure o administrador da clínica.',
        );

        // Admin, financeiro ou dono: "Pagar agora", sem a orientação.
        wrapper.unmount();
        render({ plan_name: 'Pro', status: 'past_due', status_label: 'Em atraso', was_trial: false }, undefined, {
            mode: 'limited',
            canPay: true,
            payment: { url: 'https://www.asaas.com/i/pay_regua_001', amount: 299.9, due_date: '2026-10-06' },
        });

        expect(wrapper.find('[data-test="expired-pay-now"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="expired-ask-admin"]').exists()).toBe(false);

        // Teste grátis encerrado não é pendência de pagamento.
        wrapper.unmount();
        render({ plan_name: 'Pro', status: 'expired', status_label: 'Expirado', was_trial: true });
        expect(wrapper.find('[data-test="expired-ask-admin"]').exists()).toBe(false);
    });

    it('modo limitado: explica o que está bloqueado, o que segue e volta ao painel (sem planos)', () => {
        render({ plan_name: 'Pro', status: 'past_due', status_label: 'Em atraso', was_trial: false }, undefined, {
            mode: 'limited',
            limited: { days_overdue: 4, blocked_date: '2026-10-13' },
            payment: { url: 'https://www.asaas.com/i/pay_regua_001', amount: 299.9, due_date: '2026-10-06' },
            plans: [{ id: 'p1', name: 'Pro', prices: [], features: [] }],
        });

        const limited = wrapper.get('[data-test="expired-limited"]');

        expect(heading()).toBe('Acesso limitado por pagamento em atraso');
        expect(message()).toBe('O pagamento da assinatura de Clínica Visão está em atraso.');
        expect(limited.text()).toContain('Inteligência artificial');
        expect(limited.text()).toContain('Módulo financeiro');
        expect(limited.text()).toContain('Agenda, pacientes e prontuário seguem liberados.');
        expect(limited.text()).toContain('Sem o pagamento, o acesso será suspenso em 13/10/2026.');
        expect(wrapper.get('[data-test="expired-back"]').text()).toContain('Voltar ao painel');
        expect(wrapper.find('[data-test="expired-pay-now"]').exists()).toBe(true);
        expect(wrapper.text()).not.toContain('Escolha um plano');
    });

    // FT-5: aguardando o pagamento não está "encerrada" — mostra o vencimento.
    it('última assinatura: "venceu em" quando aguarda o pagamento e "encerrada em" quando acabou', () => {
        render({
            plan_name: 'Pro',
            status: 'past_due',
            status_label: 'Aguardando 1º pagamento',
            was_trial: false,
            ends_at: null,
            due_at: '2026-10-07',
        });

        const last = wrapper.get('[data-test="expired-last-subscription"]');
        expect(last.text()).toContain('venceu em 07/10/2026');
        expect(last.text()).not.toContain('encerrada');
        expect(last.text()).toContain('Aguardando 1º pagamento');

        wrapper.unmount();
        render({
            plan_name: 'Pro',
            status: 'cancelled',
            status_label: 'Cancelado',
            was_trial: false,
            ends_at: '2026-10-04T10:00:00-03:00',
            due_at: null,
        });
        expect(wrapper.get('[data-test="expired-last-subscription"]').text()).toContain('encerrada em 04/10/2026');
    });

    // FT-1: amarelo do tema sobre quase branco (1,8:1) → cor de ênfase.
    it('contraste: blocos de aviso usam a cor de ênfase do Bootstrap', () => {
        render({ plan_name: 'Pro', status: 'past_due', status_label: 'Em atraso', was_trial: false }, undefined, {
            mode: 'limited',
            limited: { days_overdue: 4, blocked_date: '2026-10-13' },
            canPay: false,
        });

        expect(wrapper.get('[data-test="expired-limited"]').classes()).toContain('text-warning-emphasis');
        expect(wrapper.get('[data-test="expired-ask-admin"]').classes()).toContain('text-info-emphasis');

        wrapper.unmount();
        render({ plan_name: 'Pro', status: 'expired', status_label: 'Expirado', was_trial: false });
        expect(wrapper.get('[data-test="expired-last-subscription"]').classes()).toContain('text-warning-emphasis');
    });

    // FT-6: sem o link da cobrança, diz por onde ela chega e dá o contato; o
    // rodapé sempre tem o contato ("Dúvidas?" não fica sem destino).
    it('sem link de pagamento: orientação + contato; rodapé sempre com o contato', () => {
        render({ plan_name: 'Pro', status: 'past_due', status_label: 'Em atraso', was_trial: false }, undefined, {
            mode: 'limited',
            limited: { days_overdue: 4, blocked_date: '2026-10-13' },
            canPay: true,
            payment: null,
        });

        const noLink = wrapper.get('[data-test="expired-no-link"]');
        expect(noLink.text()).toContain(
            'O link de pagamento desta cobrança não está disponível aqui. Para pagar, fale com a nossa equipe.',
        );
        expect(wrapper.get('[data-test="expired-no-link-contact"]').attributes('href')).toBe('/#contato');

        const footer = wrapper.get('[data-test="expired-contact"]');
        expect(footer.text()).toBe('Dúvidas? Fale com a nossa equipe');
        expect(footer.get('a').attributes('href')).toBe('/#contato');

        // Com o link: "Pagar agora", sem a orientação; o rodapé continua.
        wrapper.unmount();
        render({ plan_name: 'Pro', status: 'past_due', status_label: 'Em atraso', was_trial: false }, undefined, {
            canPay: true,
            payment: { url: 'https://www.asaas.com/i/first_001', amount: 299.9, due_date: '2026-10-08' },
        });
        expect(wrapper.find('[data-test="expired-no-link"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="expired-contact"] a').exists()).toBe(true);

        // Encerrada (sem cobrança a pagar): sem a orientação de boleto/Pix.
        wrapper.unmount();
        render({ plan_name: 'Pro', status: 'cancelled', status_label: 'Cancelado', was_trial: false }, undefined, {
            canPay: true,
        });
        expect(wrapper.find('[data-test="expired-no-link"]').exists()).toBe(false);
    });
});

describe('SubscriptionExpired — checkout dentro do sistema', () => {
    it('"Pagar agora" abre a fatura em Minha assinatura, sem o site do gateway', () => {
        render({ plan_name: 'Pro', status: 'past_due', status_label: 'Em atraso', was_trial: false }, undefined, {
            canPay: true,
            payment: {
                url: 'https://www.asaas.com/i/first_001',
                amount: 299.9,
                due_date: '2026-10-08',
                invoice_id: 'inv-7',
            },
            urls: {
                logout: '/logout',
                contact: '/#contato',
                dashboard: '/panel/dashboard',
                checkout: '/panel/my-subscription',
            },
        });

        const pay = wrapper.get('[data-test="expired-pay-checkout"]');
        expect(wrapper.getComponent('[data-test="expired-pay-checkout"]').props('href')).toBe(
            '/panel/my-subscription?invoice=inv-7',
        );
        expect(pay.attributes('target')).toBeUndefined();
        expect(wrapper.find('[data-test="expired-pay-now"]').exists()).toBe(false);
    });

    it('planos: "Contratar" leva à contratação já pagando (plano e ciclo)', () => {
        const plans = [
            {
                id: 'plan-pro',
                name: 'Pro',
                default_cycle: 'yearly',
                prices: [{ cycle: 'yearly', price: 2990, months: 12, period_label: '/ano' }],
                features: [],
            },
        ];
        render({ plan_name: 'Pro', status: 'expired', was_trial: true }, undefined, {
            canPay: true,
            plans,
            urls: {
                logout: '/logout',
                contact: '/#contato',
                dashboard: '/panel/dashboard',
                checkout: '/panel/my-subscription',
            },
        });

        expect(wrapper.getComponent('[data-test="expired-contract"]').props('href')).toBe(
            '/panel/my-subscription?plan=plan-pro&cycle=yearly',
        );
    });
});
