import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import SubscriptionBanner from '@/Components/Panel/SubscriptionBanner.vue';

/**
 * Aviso da situação da assinatura no topo do painel: pagamento pendente da
 * contratação, atraso com acesso total, acesso limitado e trial terminando.
 * Os de pagamento não fecham e têm "Pagar agora" quando há link; o do trial
 * fecha e fica fechado para a mesma data.
 */
const t = {
    pay_now: 'Pagar agora',
    choose_plan: 'Falar com o comercial',
    dismiss: 'Fechar aviso',
    opens_new_tab: '(abre em nova aba)',
    first_payment_title: 'Pagamento pendente',
    first_payment_body: 'A 1ª cobrança da assinatura vence em :date.',
    overdue_title: 'Pagamento em atraso',
    overdue_body:
        'Em atraso :days. Em :limited_date, IA e financeiro bloqueiam; em :blocked_date, o acesso é suspenso.',
    limited_title: 'Acesso limitado',
    limited_body: 'Em atraso :days: IA e financeiro bloqueados. Em :blocked_date, o acesso é suspenso.',
    days_overdue: 'há :days dia|há :days dias',
    days_overdue_today: 'desde hoje',
    trial_title: 'Teste grátis terminando',
    trial_body: 'Seu teste termina em :date (:days).',
    trial_days_left: 'falta :days dia|faltam :days dias',
    trial_today: 'termina hoje',
    ask_admin: 'Peça ao administrador da clínica para regularizar o pagamento.',
    contact: 'Falar com a nossa equipe',
    no_link: 'O link desta cobrança não está disponível aqui; para pagar, fale com a nossa equipe.',
};

let wrapper;

beforeEach(() => {
    window.localStorage.clear();
    window.sessionStorage.clear();
});
afterEach(() => wrapper?.unmount());

function render(banner) {
    wrapper = mount(SubscriptionBanner, { props: { banner: { t, ...banner } } });

    return wrapper;
}

const body = () => wrapper.get('[data-test="subscription-banner-body"]').text();

describe('SubscriptionBanner', () => {
    it('pagamento pendente da contratação: data no idioma, "Pagar agora" em nova aba e sem fechar', () => {
        render({
            kind: 'first_payment_pending',
            dismissible: false,
            due_date: '2026-10-13',
            payment_url: 'https://www.asaas.com/i/first_002',
        });

        const banner = wrapper.get('[data-test="subscription-banner"]');
        const pay = wrapper.get('[data-test="subscription-banner-pay"]');

        expect(banner.attributes('role')).toBe('status');
        expect(banner.text()).toContain('Pagamento pendente');
        expect(body()).toBe('A 1ª cobrança da assinatura vence em 13/10/2026.');
        expect(pay.attributes('href')).toBe('https://www.asaas.com/i/first_002');
        expect(pay.attributes('target')).toBe('_blank');
        expect(pay.attributes('rel')).toContain('noopener');
        expect(pay.text()).toContain('(abre em nova aba)');
        expect(wrapper.find('[data-test="subscription-banner-dismiss"]').exists()).toBe(false);
    });

    it('em atraso com acesso total: alerta com os dias e as datas de bloqueio parcial e total', () => {
        render({
            kind: 'overdue',
            dismissible: false,
            days_overdue: 2,
            limited_date: '2026-10-11',
            blocked_date: '2026-10-15',
            payment_url: 'https://www.asaas.com/i/pay_regua_001',
        });

        const banner = wrapper.get('[data-test="subscription-banner"]');

        expect(banner.attributes('role')).toBe('alert');
        expect(banner.classes()).toContain('alert-warning');
        expect(body()).toBe(
            'Em atraso há 2 dias. Em 11/10/2026, IA e financeiro bloqueiam; em 15/10/2026, o acesso é suspenso.',
        );
        expect(wrapper.find('[data-test="subscription-banner-pay"]').exists()).toBe(true);
    });

    it('acesso limitado: alerta vermelho; sem link, sem botão de pagar', () => {
        render({
            kind: 'limited',
            dismissible: false,
            days_overdue: 1,
            blocked_date: '2026-10-13',
            payment_url: null,
        });

        const banner = wrapper.get('[data-test="subscription-banner"]');

        expect(banner.attributes('role')).toBe('alert');
        expect(banner.classes()).toContain('alert-danger');
        expect(body()).toBe('Em atraso há 1 dia: IA e financeiro bloqueados. Em 13/10/2026, o acesso é suspenso.');
        expect(wrapper.find('[data-test="subscription-banner-pay"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="subscription-banner-dismiss"]').exists()).toBe(false);
    });

    it('trial terminando: pode fechar, e fechado continua fechado para a mesma data', async () => {
        const banner = {
            kind: 'trial_ending',
            dismissible: true,
            due_date: '2026-10-12',
            days_left: 2,
            contact_url: '/#contato',
        };

        render(banner);

        expect(body()).toBe('Seu teste termina em 12/10/2026 (faltam 2 dias).');
        expect(wrapper.get('[data-test="subscription-banner-contact"]').attributes('href')).toBe('/#contato');

        await wrapper.get('[data-test="subscription-banner-dismiss"]').trigger('click');
        expect(wrapper.find('[data-test="subscription-banner"]').exists()).toBe(false);

        wrapper.unmount();
        render(banner);
        expect(wrapper.find('[data-test="subscription-banner"]').exists()).toBe(false);

        // Outra data (novo trial): o aviso volta.
        wrapper.unmount();
        render({ ...banner, due_date: '2026-11-20', days_left: 0 });
        expect(body()).toBe('Seu teste termina em 20/11/2026 (termina hoje).');
    });

    it('quem não é admin, financeiro nem dono: aviso sem "Pagar agora", com a orientação de procurar o administrador', () => {
        render({
            kind: 'overdue',
            dismissible: false,
            days_overdue: 2,
            limited_date: '2026-10-11',
            blocked_date: '2026-10-15',
            can_pay: false,
            payment_url: null,
            amount: null,
        });

        expect(wrapper.find('[data-test="subscription-banner-pay"]').exists()).toBe(false);
        expect(wrapper.get('[data-test="subscription-banner-ask-admin"]').text()).toBe(
            'Peça ao administrador da clínica para regularizar o pagamento.',
        );

        // Contato de cobrança (can_pay) não recebe a orientação.
        wrapper.unmount();
        render({
            kind: 'overdue',
            dismissible: false,
            days_overdue: 2,
            can_pay: true,
            payment_url: 'https://x.test/i/1',
        });
        expect(wrapper.find('[data-test="subscription-banner-ask-admin"]').exists()).toBe(false);

        // Trial terminando não é aviso de pagamento.
        wrapper.unmount();
        render({ kind: 'trial_ending', dismissible: true, due_date: '2026-10-12', days_left: 2, can_pay: false });
        expect(wrapper.find('[data-test="subscription-banner-ask-admin"]').exists()).toBe(false);
    });

    it('aviso de pagamento nunca some, mesmo com um fechamento antigo salvo', () => {
        window.localStorage.setItem('ee-subscription-banner-dismissed', 'limited:');

        render({ kind: 'limited', dismissible: false, days_overdue: 4, blocked_date: '2026-10-13' });

        expect(wrapper.find('[data-test="subscription-banner"]').exists()).toBe(true);
    });

    // FT-1: o amarelo do tema sobre quase branco dava 1,8:1 e "Pagar agora"
    // branco sobre amarelo, 1,9:1 — texto na cor de ênfase, botão primário.
    it.each([
        ['overdue', 'warning'],
        ['first_payment_pending', 'warning'],
        ['limited', 'danger'],
        ['trial_ending', 'info'],
    ])('contraste: %s usa o texto de ênfase (text-%s-emphasis), não a cor do tom', (kind, tone) => {
        render({
            kind,
            dismissible: kind === 'trial_ending',
            due_date: '2026-10-13',
            days_overdue: 1,
            payment_url: kind === 'trial_ending' ? null : 'https://www.asaas.com/i/pay_1',
        });

        const banner = wrapper.get('[data-test="subscription-banner"]');

        expect(banner.classes()).toContain(`text-${tone}-emphasis`);
        expect(banner.attributes('style')).toContain(`var(--${tone})`);

        if (kind !== 'trial_ending') {
            const pay = wrapper.get('[data-test="subscription-banner-pay"]').classes();
            expect(pay).toContain('btn-primary');
            expect(pay).not.toContain(`btn-${tone}`);
        }
    });

    // FT-6: sem o link da cobrança, o aviso dá o caminho (contato) e diz por onde ela chega.
    it('sem link de pagamento: "Falar com a nossa equipe" e por onde a cobrança chega', () => {
        render({
            kind: 'limited',
            dismissible: false,
            days_overdue: 3,
            blocked_date: '2026-10-13',
            can_pay: true,
            payment_url: null,
            contact_url: '/#contato',
        });

        const contact = wrapper.get('[data-test="subscription-banner-contact"]');

        expect(contact.attributes('href')).toBe('/#contato');
        expect(contact.text()).toBe('Falar com a nossa equipe');
        expect(wrapper.get('[data-test="subscription-banner-no-link"]').text()).toBe(
            'O link desta cobrança não está disponível aqui; para pagar, fale com a nossa equipe.',
        );
        expect(wrapper.find('[data-test="subscription-banner-pay"]').exists()).toBe(false);

        // Com o link: "Pagar agora", sem a orientação de contato.
        wrapper.unmount();
        render({ kind: 'overdue', dismissible: false, can_pay: true, payment_url: 'https://x.test/i/1' });
        expect(wrapper.find('[data-test="subscription-banner-no-link"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="subscription-banner-contact"]').exists()).toBe(false);
    });

    // FT-9: o layout não é persistente — cada navegação monta o aviso de novo.
    it('leitor de tela: anuncia o atraso uma vez por sessão; nas páginas seguintes é só uma região', () => {
        const overdue = { kind: 'overdue', dismissible: false, due_date: '2026-10-08', days_overdue: 1 };

        render(overdue);
        let banner = wrapper.get('[data-test="subscription-banner"]');
        expect(banner.attributes('role')).toBe('alert');
        expect(banner.attributes('aria-live')).toBe('assertive');

        // Próxima página (novo mount): não interrompe de novo.
        wrapper.unmount();
        render(overdue);
        banner = wrapper.get('[data-test="subscription-banner"]');
        expect(banner.attributes('role')).toBe('region');
        expect(banner.attributes('aria-live')).toBeUndefined();
        expect(banner.attributes('aria-label')).toBe('Pagamento em atraso');

        // Mudou a situação (acesso limitado): anuncia de novo, uma vez.
        wrapper.unmount();
        render({ ...overdue, kind: 'limited', days_overdue: 3 });
        expect(wrapper.get('[data-test="subscription-banner"]').attributes('role')).toBe('alert');
    });
});

describe('SubscriptionBanner — checkout dentro do sistema', () => {
    function renderWithLink(banner) {
        wrapper = mount(SubscriptionBanner, {
            props: { banner: { t: { ...t, subscribe_now: 'Contratar agora' }, ...banner } },
        });

        return wrapper;
    }

    it('"Pagar agora" abre a fatura em Minha assinatura (sem nova aba), não o site do gateway', () => {
        renderWithLink({
            kind: 'overdue',
            dismissible: false,
            can_pay: true,
            payment_url: 'https://www.asaas.com/i/pay_1',
            invoice_id: 'inv-1',
            checkout_url: '/panel/my-subscription',
        });

        const pay = wrapper.get('[data-test="subscription-banner-checkout"]');
        expect(wrapper.getComponent('[data-test="subscription-banner-checkout"]').props('href')).toBe(
            '/panel/my-subscription?invoice=inv-1',
        );
        expect(pay.attributes('target')).toBeUndefined();
        expect(pay.text()).toContain('Pagar agora');
        expect(wrapper.find('[data-test="subscription-banner-pay"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="subscription-banner-no-link"]').exists()).toBe(false);
    });

    it('trial terminando, visto por quem paga: "Contratar agora" no próprio sistema', () => {
        renderWithLink({
            kind: 'trial_ending',
            dismissible: true,
            due_date: '2026-10-08',
            days_left: 2,
            contact_url: '/#contato',
            checkout_url: '/panel/my-subscription',
        });

        const cta = wrapper.get('[data-test="subscription-banner-checkout"]');
        expect(wrapper.getComponent('[data-test="subscription-banner-checkout"]').props('href')).toBe(
            '/panel/my-subscription',
        );
        expect(cta.text()).toContain('Contratar agora');
        expect(wrapper.find('[data-test="subscription-banner-contact"]').exists()).toBe(false);
    });

    it('quem não paga nunca recebe o checkout', () => {
        renderWithLink({ kind: 'overdue', dismissible: false, can_pay: false, payment_url: null, checkout_url: null });

        expect(wrapper.find('[data-test="subscription-banner-checkout"]').exists()).toBe(false);
    });
});
