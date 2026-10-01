import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
import Home from '@/Pages/Site/Home.vue';
import { initSiteAnimations } from '@/site-animations';

vi.mock('@/Layouts/SiteLayout.vue', () => ({
    default: { props: ['t', 'routes', 'appName', 'hasHero'], template: '<div class="layout-stub"><slot /></div>' },
}));
vi.mock('@/Components/Site/ContactForm.vue', () => ({
    default: { props: ['t', 'action'], template: '<form class="contact-form-stub" />' },
}));
vi.mock('@/site-animations', () => ({ initSiteAnimations: vi.fn(() => () => {}) }));
// <Head> não renderiza: com a página no document, o happy-dom baixaria as
// fontes e a imagem do hero dos <link> (rede real dentro do teste).
vi.mock('@inertiajs/vue3', () => ({ Head: { render: () => null } }));

/**
 * Landing (Site/Home):
 *  - FAQ e abas da demonstração acessíveis por teclado e leitor de tela;
 *  - demonstração só com as abas que têm print, agenda marcada como fictícia;
 *  - catálogo completo integrado ao comparador e links que preservam o plano;
 *  - "Disponível no …" vem dos planos; o teste usa o prazo efetivo do cadastro;
 *  - optotipos "Em breve" só no card do Premium;
 *  - CTA fixo no celular sem competir com outro CTA já visível.
 */
const section = (extra = {}) => ({ label: 'Rótulo', title: 'Título', subtitle: 'Subtítulo', items: [], ...extra });

function buildT() {
    return {
        meta: { title: 'EasyEye', description: 'd', og_title: 'o', og_description: 'o' },
        hero: {
            badge: 'b',
            title: 't',
            title_em: 'e',
            subtitle: 's',
            cta_primary: 'Começar grátis',
            cta_account: 'Criar conta',
            cta_secondary: 'Ver o sistema',
            cta_note: ':days dias grátis · sem cartão de crédito',
            trust: '',
            trust_initials: [],
            visual_alt: 'Prontuário oftalmológico do EasyEye',
            card_top_lbl: 'Imagens por olho',
            card_top_val: 'OCT',
            card_bot_lbl: 'CFM e LGPD',
            card_bot_val: 'Assinado',
        },
        metrics: [],
        problems: section({
            items: [
                { title: 'Histórico disperso', text: 'Consulte a evolução clínica em um lugar.', icon: 'ti-history' },
            ],
        }),
        demo: section({
            title: 'Veja o EasyEye por dentro',
            fictitious: 'Dados fictícios.',
            enlarge: 'Ampliar imagem',
            tabs: [
                { key: 'prontuario', label: 'Prontuário', icon: 'ti-report-medical', caption: 'c1' },
                { key: 'imagens', label: 'Imagens', icon: 'ti-photo', caption: 'c2' },
                { key: 'agenda', label: 'Agenda', icon: 'ti-calendar', caption: 'c3', fictitious: true },
                { key: 'laudos', label: 'Laudos', icon: 'ti-file-text', caption: 'c4' },
            ],
        }),
        audiences: section({
            available_in: 'Disponível no :plans',
            more: 'Ver todos os recursos de :audience',
            groups: [
                {
                    key: 'consultorio',
                    icon: 'ti-stethoscope',
                    title: 'Consultório',
                    audience: 'Para o oftalmologista',
                    items: [
                        { text: 'Prontuário oftalmológico' },
                        { text: 'Integração com aparelhos', feature: 'has_api_integrator' },
                        { text: 'IA na redação de laudos', feature: 'has_ai_report_drafting' },
                        { text: 'Histórico de exames' },
                    ],
                },
                {
                    key: 'faturamento',
                    icon: 'ti-receipt',
                    title: 'Faturamento',
                    audience: 'Para o faturamento',
                    items: [{ text: 'Guias TISS' }],
                    flow_label: 'Fluxo TISS',
                    flow: ['Guia', 'Lote XML', 'Retorno e glosa'],
                },
            ],
        }),
        how: section({
            steps: [
                { title: 'Passo 1', text: 'a' },
                { title: 'Passo 2', text: 'b' },
            ],
            screenshot_alt: 'alt',
        }),
        differentiators: section({
            items: [
                {
                    title: 'Evolução documentada',
                    text: 'Acompanhe cada alteração com rastreabilidade.',
                    icon: 'ti-history',
                },
            ],
            proof_title: 'Na prática',
            proof: [{ icon: 'ti-history', label: 'Trilha de auditoria' }],
        }),
        testimonials: section({
            rating: ':stars de 5 estrelas',
        }),
        pricing: {
            label: 'Planos',
            title: 'Planos',
            subtitle: 's',
            trial_suffix: ':days dias',
            empty_title: 'e',
            empty_subtitle: 'e',
            contact_cta: 'Falar com especialista',
            featured_badge: 'Mais popular',
            on_request: 'Sob consulta',
            trial_text: ':days dias grátis para testar',
            get_started: 'Começar grátis',
            included_all_label: 'Em todos os planos',
            included_all: 'Agenda e prontuário.',
            choose_plan: 'Escolher :plan',
            details_label: 'Ver todos os recursos',
            summary_label: 'Comparação de planos',
            comparison_labels: { max_doctors: 'Médicos', ai_monthly_credits: 'Créditos de IA' },
            included: 'Incluído',
            not_included: 'Não incluído',
            not_specified: 'Não informado',
            integrator_label: 'Integrador de exames',
            integrator_description:
                'Envie os exames dos aparelhos para o EasyEye e mantenha-os organizados para consulta.',
            integrator_title: 'Integrador de exames',
            integrator_badge: 'Integrador incluído',
            integrator_plan: 'Incluído no :plan',
            integrator_flow: ['Aparelhos', 'Integrador', 'Exames'],
            groups: { capacity: 'Capacidade', ai: 'Inteligência artificial', resources: 'Recursos' },
            upcoming_label: 'Em breve no Premium',
            upcoming: [{ icon: 'ti-eye', title: 'Programa completo de optotipos', badge: 'Em breve' }],
        },
        pricing_credit_note: {
            title: 'IA no seu plano: o que consome créditos',
            intro: 'Os recursos de IA usam o mesmo saldo da clínica.',
            actions_title: 'Análises e rascunhos',
            actions_body: 'Consomem créditos quando incluídos no plano.',
            chat_title: 'Dúvidas e textos no assistente',
            chat_body: 'Cada pergunta ou pedido de texto também usa esse saldo.',
            usage_title: 'Consumo variável',
            usage_body: 'Uma solicitação pode consumir mais de um crédito.',
            renewal_title: 'Franquia do plano',
            renewal_body: 'Renova por ciclo e não acumula.',
            topup: 'Recargas acumulam e não expiram.',
            trial_note: 'A franquia do plano não é liberada durante o período de teste.',
            medical_note: 'O médico deve revisar o conteúdo gerado.',
        },
        faq: {
            label: 'FAQ',
            title: 'Perguntas',
            items: [
                { q: 'Funciona offline?', a: 'Não.' },
                { q: 'Tem TISS?', a: 'Sim.' },
            ],
        },
        contact: {
            label: 'Contato',
            headline_pre: 'Quer falar com o',
            headline_post: 'Estamos aqui',
            subtitle: 's',
            form: {},
            sales: { title: 'Vendas', desc: 'd', channel: '+55 61 98467-6485' },
            support: { title: 'Suporte', desc: 'd' },
            trial: {
                title: 'Teste',
                title_no_trial: 'Criar conta',
                desc: ':days dias sem cartão',
                desc_no_trial: 'Sem cartão de crédito.',
                cta: 'Criar conta',
            },
            aside: { quote_text: '', quote_author: '' },
            trust_ssl: 'SSL',
            trust_lgpd: 'LGPD',
            trust_cfm: 'CFM',
            trust_nps: '',
        },
        cta: {
            title: 'Pronto?',
            subtitle_trial: ':days dias gratuitos, sem cartão.',
            subtitle: 'Sem cartão de crédito.',
            primary: 'Começar grátis',
            primary_no_trial: 'Criar conta',
            secondary: 'Conversar pelo WhatsApp',
            note: 'n',
        },
        nav: {},
        footer: {},
    };
}

const feature = (id, key, display_label, extra = {}) => ({
    id,
    key,
    display_label,
    enabled: true,
    is_none: false,
    ...extra,
});

// Catálogo heterogêneo: planos pagos e um plano sob consulta.
function buildPlans() {
    return [
        {
            id: 'p1',
            slug: 'basico',
            name: 'Básico',
            description: '',
            price: 1299.5,
            price_period_label: '/mês',
            is_free: false,
            is_featured: false,
            trial_days: 14,
            register_url: '/register?plan=p1',
            features: [
                feature('b1', 'max_doctors', 'Até 1 médico'),
                feature('b2', 'ai_monthly_credits', 'Sem créditos de IA', { is_none: true }),
                feature('b3', 'has_api_integrator', 'Integração com equipamentos', { enabled: false }),
            ],
        },
        {
            id: 'p2',
            slug: 'pro',
            name: 'Pro',
            description: '',
            price: 899.9,
            price_period_label: '/mês',
            is_free: false,
            is_featured: true,
            trial_days: 7,
            register_url: '/register?plan=p2',
            features: [
                feature('r1', 'max_doctors', 'Até 3 médicos'),
                feature('r2', 'ai_monthly_credits', '100 créditos de IA por mês'),
                feature('r3', 'has_api_integrator', 'Integração com equipamentos'),
            ],
        },
        {
            id: 'p3',
            slug: 'premium',
            name: 'Premium',
            description: '',
            price: 1799.9,
            price_period_label: '/mês',
            is_free: false,
            is_featured: false,
            trial_days: null,
            register_url: '/register?plan=p3',
            features: [
                feature('m1', 'max_doctors', 'Até 3 médicos'),
                feature('m2', 'ai_monthly_credits', '500 créditos de IA por mês'),
                feature('m3', 'has_api_integrator', 'Integração com equipamentos'),
            ],
        },
        {
            id: 'p4',
            slug: 'enterprise',
            name: 'Enterprise',
            description: '',
            price: 0,
            price_period_label: '',
            is_free: true,
            is_featured: false,
            trial_days: null,
            features: [feature('e1', 'max_doctors', 'Médicos ilimitados')],
        },
    ];
}

let wrapper;

function stubIntersectionObserver() {
    const observers = [];
    class FakeIntersectionObserver {
        constructor(callback) {
            this.callback = callback;
            this.targets = [];
            observers.push(this);
        }

        observe(target) {
            this.targets.push(target);
        }
        disconnect() {}
        report(isVisible) {
            this.callback(this.targets.map((target) => ({ target, isIntersecting: isVisible(target) })));
        }
    }
    vi.stubGlobal('IntersectionObserver', FakeIntersectionObserver);
    return observers;
}

function stubMatchMedia(reduceMotion) {
    window.matchMedia = vi.fn((query) =>
        Object.assign(new EventTarget(), {
            matches: reduceMotion && query.includes('prefers-reduced-motion'),
            media: query,
        }),
    );
}

async function mountHome({
    reduceMotion = false,
    locale = 'pt-BR',
    plans = buildPlans(),
    trialDays = 7,
    demoImages,
    heroImage = 1,
    observers,
    t = buildT(),
} = {}) {
    if (!observers) stubIntersectionObserver();
    stubMatchMedia(reduceMotion);
    wrapper = mount(Home, {
        attachTo: document.body,
        props: {
            t,
            plans,
            trialDays,
            routes: { siteHome: '/', register: '/register', go: '/go', contactStore: '/contato' },
            contact: { sales: 'contato@easyeye.app', support: 'suporte@easyeye.app' },
            heroImage,
            demoImages: demoImages ?? { prontuario: 1, imagens: 1, agenda: 1 },
            seo: { canonicalUrl: '/', currentLocale: locale, alternateLocales: [], ogImage: '', jsonLd: [] },
        },
    });
    await flushPromises();

    return wrapper;
}

const tabs = () => wrapper.findAll('[role="tab"]');
const activeTabIndex = () => tabs().findIndex((tab) => tab.attributes('aria-selected') === 'true');
const card = (index) => wrapper.findAll('.pricing-card')[index];

afterEach(() => {
    wrapper?.unmount();
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('Home — FAQ', () => {
    it('pergunta é botão com aria-expanded ligado à resposta', async () => {
        await mountHome();

        const button = wrapper.get('#faq-q-0');
        expect(button.element.tagName).toBe('BUTTON');
        expect(button.attributes('aria-expanded')).toBe('false');
        expect(button.attributes('aria-controls')).toBe('faq-a-0');

        const answer = wrapper.get('#faq-a-0');
        expect(answer.classes()).not.toContain('is-open');

        await button.trigger('click');
        expect(button.attributes('aria-expanded')).toBe('true');
        expect(answer.attributes('aria-labelledby')).toBe('faq-q-0');
        expect(answer.classes()).toContain('is-open');
        expect(answer.text()).toBe('Não.');

        await button.trigger('click');
        expect(answer.classes()).not.toContain('is-open');
    });
});

describe('Home — demonstração', () => {
    function expectTabState(selectedIndex) {
        tabs().forEach((tab, index) => {
            const selected = index === selectedIndex;
            const panel = wrapper.get(`#${tab.attributes('aria-controls')}`);
            expect(tab.attributes('aria-selected')).toBe(String(selected));
            expect(tab.attributes('tabindex')).toBe(selected ? '0' : '-1');
            expect(panel.attributes('role')).toBe('tabpanel');
            expect(panel.attributes('aria-labelledby')).toBe(tab.attributes('id'));
            expect(panel.attributes('tabindex')).toBe(selected ? '0' : '-1');
            expect(panel.attributes('inert')).toBe(selected ? undefined : '');
            expect(panel.classes().includes('is-active')).toBe(selected);
        });
    }

    it('só mostra as abas que têm print; a agenda avisa que os dados são fictícios', async () => {
        await mountHome();

        expect(tabs().map((tab) => tab.text())).toEqual(['Prontuário', 'Imagens', 'Agenda']);
        expect(
            wrapper
                .get('#demonstracao')
                .findAll('button')
                .map((button) => button.text()),
        ).toEqual(['Prontuário', 'Imagens', 'Agenda']);
        expect(wrapper.get('#demo-panel-agenda .demo-fictitious').text()).toBe('Dados fictícios.');
        expect(wrapper.find('#demo-panel-prontuario .demo-fictitious').exists()).toBe(false);

        const shot = wrapper.get('#demo-panel-prontuario img');
        expect(shot.attributes('src')).toBe('/site/images/demo-prontuario.webp?v=1');
        expect(shot.attributes('alt')).toBe('c1');
        expect([shot.attributes('width'), shot.attributes('height'), shot.attributes('loading')]).toEqual([
            '1600',
            '731',
            'lazy',
        ]);
    });

    it('abas ligadas aos painéis; só a ativa entra no Tab (tabindex 0)', async () => {
        await mountHome();

        expectTabState(0);
    });

    it('setas, Home e End trocam a aba e levam o foco junto', async () => {
        await mountHome();
        const press = async (key) => {
            await tabs()[activeTabIndex()].trigger('keydown', { key });
            await nextTick();
            expectTabState(activeTabIndex());
        };

        await press('ArrowRight');
        expect(activeTabIndex()).toBe(1);
        expect(document.activeElement).toBe(tabs()[1].element);

        await press('End');
        expect(activeTabIndex()).toBe(2);

        await press('ArrowRight');
        expect(activeTabIndex()).toBe(0);

        await press('ArrowLeft');
        expect(activeTabIndex()).toBe(2);

        await press('Home');
        expect(activeTabIndex()).toBe(0);
        expect(document.activeElement).toBe(tabs()[0].element);
    });

    it.each([false, true])('a escolha manual permanece estável com reduzir movimento = %s', async (reduceMotion) => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval'] });
        await mountHome({ reduceMotion });
        await vi.advanceTimersByTimeAsync(30_000);
        expect(activeTabIndex()).toBe(0);

        await tabs()[2].trigger('click');
        expectTabState(2);

        const demo = wrapper.get('#demonstracao');
        await demo.trigger('mouseenter');
        tabs()[2].element.focus();
        await vi.advanceTimersByTimeAsync(30_000);
        expect(activeTabIndex()).toBe(2);
        await demo.trigger('mouseleave');
        wrapper.get('.hero-ctas a').element.focus();

        const hidden = vi.spyOn(document, 'hidden', 'get').mockReturnValue(true);
        try {
            for (const isHidden of [true, false]) {
                hidden.mockReturnValue(isHidden);
                document.dispatchEvent(new Event('visibilitychange'));
                await vi.advanceTimersByTimeAsync(30_000);
                expect(activeTabIndex()).toBe(2);
                expect(wrapper.get('#demo-panel-agenda').classes()).toContain('is-active');
            }
        } finally {
            hidden.mockRestore();
        }
    });

    it('não inicia a animação se a página desmontar antes do import assíncrono', async () => {
        await mountHome();
        const props = wrapper.props();
        wrapper.unmount();
        initSiteAnimations.mockClear();

        wrapper = mount(Home, { attachTo: document.body, props });
        wrapper.unmount();
        wrapper = null;
        await flushPromises();

        expect(initSiteAnimations).not.toHaveBeenCalled();
    });
});

describe('Home — planos', () => {
    it('explica o saldo compartilhado com o chat e permite acessar as regras pelos planos', async () => {
        await mountHome();

        const note = wrapper.get('#creditos-ia');
        expect(note.attributes('aria-labelledby')).toBe('pricing-credit-title');
        expect(note.get('#pricing-credit-title').text()).toBe(buildT().pricing_credit_note.title);
        expect(note.findAll('dt').map((item) => item.text())).toEqual([
            'Análises e rascunhos',
            'Dúvidas e textos no assistente',
            'Consumo variável',
            'Franquia do plano',
        ]);
        expect(note.text()).toContain(buildT().pricing_credit_note.chat_body);
        expect(note.text()).toContain(buildT().pricing_credit_note.usage_body);
        expect(note.text()).toContain(buildT().pricing_credit_note.topup);
        expect(note.text()).toContain(buildT().pricing_credit_note.trial_note);
        expect(wrapper.findAll('.pricing-comparison [href="#creditos-ia"]')).toHaveLength(buildPlans().length);
    });

    it('integra o catálogo e apresenta os recursos comerciais nos detalhes de cada plano', async () => {
        await mountHome();

        expect(wrapper.findAll('.pricing-name').map((name) => name.text())).toEqual(
            buildPlans().map((plan) => plan.name),
        );
        const expectedRows = [
            ['Até 1 médico', 'Sem créditos de IA'],
            ['Até 3 médicos', '100 créditos de IA por mês', 'Integrador de exames'],
            ['Até 3 médicos', '500 créditos de IA por mês', 'Integrador de exames'],
            ['Médicos ilimitados'],
        ];
        expectedRows.forEach((labels, index) => {
            const details = card(index).get('.pricing-details');
            expect(details.element.tagName).toBe('DETAILS');
            expect(details.get('summary').text()).toBe('Ver todos os recursos');
            expect(details.findAll('.pricing-features li').map((row) => row.text())).toEqual(labels);
        });
    });

    it('links dos planos pagos preservam sua escolha e sob consulta abre o comercial', async () => {
        await mountHome();

        expect(wrapper.findAll('.pricing-cta a').map((link) => link.attributes('href'))).toEqual([
            '/register?plan=p1',
            '/register?plan=p2',
            '/register?plan=p3',
            'https://wa.me/5561984676485',
        ]);
    });

    it('apresenta planos depois dos recursos e antes da implantação', async () => {
        await mountHome();

        const sections = wrapper.findAll('section[id]').map((section) => section.attributes('id'));
        expect(sections.indexOf('precos')).toBeGreaterThan(sections.indexOf('funcionalidades'));
        expect(sections.indexOf('precos')).toBeLessThan(sections.indexOf('como-funciona'));
    });

    it('optotipos aparece como "Em breve" só no card do Premium', async () => {
        await mountHome();

        const upcoming = wrapper.findAll('[data-test="pricing-upcoming"]');
        expect(upcoming).toHaveLength(1);
        expect(card(2).get('[data-test="pricing-upcoming"]').text()).toContain('Programa completo de optotipos');
        expect(card(2).get('.pricing-upcoming-badge').text()).toBe('Em breve');
    });

    it('prazo global do cadastro vale no hero, em todos os planos pagos e no CTA final', async () => {
        await mountHome();

        expect(wrapper.get('[data-test="hero-cta-note"]').text()).toBe('7 dias grátis · sem cartão de crédito');
        expect(card(0).get('.pricing-trial').text()).toBe('7 dias grátis para testar');
        expect(card(1).get('.pricing-trial').text()).toBe('7 dias grátis para testar');
        expect(card(2).get('.pricing-trial').text()).toBe('7 dias grátis para testar');
        expect(card(3).find('.pricing-trial').exists()).toBe(false);
        expect(wrapper.get('.cta-final-sub').text()).toBe('7 dias gratuitos, sem cartão.');
    });

    it('sem prazo efetivo os CTAs levam ao comercial sem prometer cadastro gratuito', async () => {
        await mountHome({ trialDays: 0 });

        expect(wrapper.find('[data-test="hero-cta-note"]').exists()).toBe(false);
        expect(wrapper.find('.pricing-trial').exists()).toBe(false);
        expect(wrapper.get('.cta-final-sub').text()).toBe('Sem cartão de crédito.');
        expect(wrapper.text()).toContain('Sem cartão de crédito.');
        expect(wrapper.text()).not.toMatch(/\d+ dias/);
        for (const selector of ['.hero-ctas a', '.cta-final-btns a', '[data-test="mobile-cta"] a', '.pricing-cta a']) {
            const link = wrapper.get(selector);
            expect(link.text()).toContain('Falar com especialista');
            expect(link.attributes('href')).toBe('https://wa.me/5561984676485');
        }
        expect(wrapper.text()).not.toContain('Começar grátis');
    });

    it('preço segue o idioma do visitante', async () => {
        await mountHome({ locale: 'en' });
        expect(wrapper.get('.price-value').text()).toBe('1,299.50');

        wrapper.unmount();
        await mountHome({ locale: 'pt-BR' });
        expect(wrapper.get('.price-value').text()).toBe('1.299,50');
    });
});

describe('Home — funcionalidades por público', () => {
    const consultorio = () => wrapper.get('[data-test="audience-consultorio"]');

    it('recurso de alguns planos diz em quais; recurso de todos não leva selo', async () => {
        await mountHome();

        const items = consultorio().findAll('li');
        expect(items[0].find('[data-test="audience-plan"]').exists()).toBe(false);
        expect(items[1].get('[data-test="audience-plan"]').text()).toBe('Disponível no Pro e Premium');
    });

    it('recurso que nenhum plano inclui não aparece', async () => {
        await mountHome();

        expect(consultorio().text()).not.toContain('IA na redação de laudos');
    });

    it('sem planos cadastrados nada é afirmado: itens sem selo', async () => {
        await mountHome({ plans: [] });

        expect(consultorio().text()).toContain('IA na redação de laudos');
        expect(wrapper.find('[data-test="audience-plan"]').exists()).toBe(false);
    });

    it('fluxo TISS: sem movimento as etapas já aparecem concluídas', async () => {
        await mountHome({ reduceMotion: true });

        const flow = wrapper.get('[data-test="tiss-flow"]');
        expect(flow.classes()).toContain('is-static');
        expect(flow.findAll('li').map((li) => li.attributes('style'))).toEqual([
            '--step: 0;',
            '--step: 1;',
            '--step: 2;',
        ]);
    });

    it('fluxo TISS respeita ativar reduzir movimento durante a sessão sem mudar a aba escolhida', async () => {
        const observers = stubIntersectionObserver();
        await mountHome({ observers });
        await tabs()[1].trigger('click');
        const flow = wrapper.get('[data-test="tiss-flow"]');
        expect(flow.classes()).toContain('is-armed');
        const observer = observers.find((item) => item.targets.includes(flow.element));
        const disconnect = vi.spyOn(observer, 'disconnect');
        const query = window.matchMedia.mock.results.find((result) =>
            result.value.media.includes('prefers-reduced-motion'),
        ).value;

        query.dispatchEvent(Object.assign(new Event('change'), { matches: true }));
        await nextTick();
        expect(flow.classes()).toContain('is-static');
        expect(disconnect).toHaveBeenCalledOnce();
        expect(activeTabIndex()).toBe(1);

        query.dispatchEvent(Object.assign(new Event('change'), { matches: false }));
        await nextTick();
        expect(flow.classes()).toContain('is-static');
        expect(activeTabIndex()).toBe(1);
    });

    it('fluxo TISS mantém suas etapas em uma faixa identificada fora dos três cards', async () => {
        await mountHome();

        const flow = wrapper.get('[data-test="tiss-flow"]');
        const steps = flow.get('.audience-flow-steps');
        expect(wrapper.find('.audience-card .audience-flow').exists()).toBe(false);
        expect(flow.element.parentElement).toBe(wrapper.get('.audiences-grid').element.parentElement);
        expect(flow.attributes('aria-labelledby')).toBe('audience-flow-faturamento');
        expect(flow.get('#audience-flow-faturamento').text()).toBe('Fluxo TISS');
        expect(steps.element.tagName).toBe('OL');
        expect(steps.findAll('li').map((li) => li.text())).toEqual(['Guia', 'Lote XML', 'Retorno e glosa']);
    });

    it('conteúdo complementar continua disponível em detalhes nativos', async () => {
        await mountHome();

        const problem = wrapper.get('.problem-card');
        expect(problem.element.tagName).toBe('DETAILS');
        expect(problem.get('summary').text()).toBe('Histórico disperso');
        expect(problem.get('p').text()).toBe('Consulte a evolução clínica em um lugar.');

        const audience = consultorio().get('.audience-details');
        expect(audience.element.tagName).toBe('DETAILS');
        expect(audience.get('summary').text()).toBe('Ver todos os recursos de consultório');
        expect(audience.findAll('li').map((item) => item.text())).toEqual(['Histórico de exames']);

        const differentiator = wrapper.get('.diff-card');
        expect(differentiator.element.tagName).toBe('DETAILS');
        expect(differentiator.get('summary').text()).toBe('Evolução documentada');
        expect(differentiator.get('p').text()).toBe('Acompanhe cada alteração com rastreabilidade.');
    });
});

describe('Home — leitor de tela, contatos e hero', () => {
    it('sem prova social publicada, não deixa números, citações ou seções vazias', async () => {
        await mountHome();

        for (const selector of [
            '.hero-trust',
            '.metrics',
            '#dados-indicadores',
            '#depoimentos',
            '.contact-aside-quote',
        ]) {
            expect(wrapper.find(selector).exists()).toBe(false);
        }
        expect(wrapper.get('.contact-trust').text()).toBe('SSL LGPD CFM');
        expect(wrapper.get('.contact-trust').classes()).toContain('contact-trust--compact');
        expect(wrapper.find('.contact-trust .ti-star').exists()).toBe(false);
        expect(wrapper.find('.contact-form-stub').exists()).toBe(true);
        expect(wrapper.find('#problemas').exists()).toBe(true);
        expect(wrapper.find('#faq').exists()).toBe(true);
    });

    it('estrutura aceita prova social publicada no futuro e mantém a nota acessível', async () => {
        // Dados exclusivos do teste: a publicação é controlada pelo servidor.
        const t = buildT();
        t.hero.trust = '7 clínicas usam o EasyEye';
        t.hero.trust_initials = ['AC'];
        t.metrics = [{ value: '7', amount: 7, decimals: 0, prefix: '', suffix: '', label: 'Clínicas ativas' }];
        t.metrics_context = 'Fonte e período da medição de teste.';
        t.metrics_context_label = 'Contexto dos indicadores';
        t.testimonials.items = [
            { name: 'Pessoa de teste', text: 'Depoimento de teste.', stars: 4, initials: 'AC', role: 'Diretora' },
        ];
        t.contact.aside = { quote_text: 'Citação de teste.', quote_author: 'Pessoa de teste' };
        t.contact.trust_nps = 'Indicador de satisfação de teste';
        await mountHome({ t });

        expect(wrapper.get('.hero-trust').text()).toContain('7 clínicas usam o EasyEye');
        expect(wrapper.get('.hero-trust').text()).not.toContain('500');
        expect(wrapper.get('.metric-value').text()).toBe('7');
        expect(wrapper.get('#dados-indicadores').text()).toContain(t.metrics_context);
        expect(wrapper.get('.contact-aside-quote').text()).toContain('Citação de teste.');
        expect(wrapper.get('.contact-trust').text()).toContain(t.contact.trust_nps);
        expect(wrapper.get('.contact-trust').classes()).not.toContain('contact-trust--compact');
        const stars = wrapper.get('.testimonial-stars');
        expect(stars.attributes('role')).toBe('img');
        expect(stars.attributes('aria-label')).toBe('4 de 5 estrelas');
        expect(stars.findAll('svg')).toHaveLength(5);
        expect(stars.findAll('svg.is-empty')).toHaveLength(1);
    });

    it('citação sem autoria e contexto sem indicadores não aparecem sozinhos', async () => {
        const t = buildT();
        t.contact.aside.quote_text = 'Citação ainda incompleta.';
        t.metrics_context = 'Contexto sem indicadores.';
        await mountHome({ t });

        expect(wrapper.find('.contact-aside-quote').exists()).toBe(false);
        expect(wrapper.find('#dados-indicadores').exists()).toBe(false);
    });

    it('comercial abre WhatsApp e suporte usa o e-mail configurado', async () => {
        await mountHome();

        const mailtos = wrapper.findAll('a[href^="mailto:"]').map((link) => link.attributes('href'));
        expect(mailtos).toEqual(['mailto:suporte@easyeye.app']);
        expect(wrapper.get('.cta-final-btns a:last-child').attributes('href')).toBe('https://wa.me/5561984676485');
        expect(wrapper.get('#contato a[href="https://wa.me/5561984676485"]').exists()).toBe(true);
    });

    it('hero mostra o prontuário real com dimensões (sem salto de layout)', async () => {
        await mountHome();

        const shot = wrapper.get('.hero-shot');
        expect(shot.attributes('src')).toBe('/site/images/hero-prontuario.webp?v=1');
        expect(shot.attributes('alt')).toBe('Prontuário oftalmológico do EasyEye');
        expect([shot.attributes('width'), shot.attributes('height'), shot.attributes('fetchpriority')]).toEqual([
            '1061',
            '857',
            'high',
        ]);

        const calibration = wrapper.get('.hero-calibration');
        expect(calibration.attributes('aria-hidden')).toBe('true');
        expect(
            calibration.findAll('svg').map((mark) => [mark.attributes('data-corner'), mark.attributes('focusable')]),
        ).toEqual([
            ['tl', 'false'],
            ['tr', 'false'],
            ['br', 'false'],
            ['bl', 'false'],
        ]);
        expect(calibration.find('img').exists()).toBe(false);
        expect(wrapper.get('.hero-enlarge').attributes('href')).toBe(shot.attributes('src'));
    });

    it('sem o arquivo do print, o hero fica só com o texto (nunca imagem quebrada)', async () => {
        await mountHome({ heroImage: false });

        expect(wrapper.find('.hero-visual').exists()).toBe(false);
        expect(wrapper.find('.hero-calibration').exists()).toBe(false);
        expect(wrapper.get('.hero-inner').classes()).toContain('hero-inner--solo');
        expect(wrapper.get('.hero-title').exists()).toBe(true);
    });
});

describe('Home — CTA fixo no celular', () => {
    it('aparece depois do hero e some no contato; escondido fica fora do Tab', async () => {
        const observers = stubIntersectionObserver();
        await mountHome({ observers });
        const bar = () => wrapper.get('[data-test="mobile-cta"]');

        expect(bar().attributes('aria-hidden')).toBe('true');
        expect(bar().get('a').attributes('tabindex')).toBe('-1');

        const [observer] = observers;
        expect(
            observer.targets.filter((el) => el.matches('.hero, #contato, .cta-final')).map((el) => el.className),
        ).toEqual(['hero', 'contact', 'cta-final']);
        expect(observer.targets.filter((el) => el.matches('.pricing-cta a'))).toHaveLength(4);

        observer.report(() => false); // rolou além do hero, antes do contato
        await nextTick();
        expect(bar().classes()).toContain('is-visible');
        expect(bar().attributes('aria-hidden')).toBe('false');
        expect(bar().get('a').attributes('tabindex')).toBe('0');
        expect(bar().get('a').attributes('href')).toBe('/register');

        observer.report((el) => el.id === 'contato'); // chegou ao contato
        await nextTick();
        expect(bar().classes()).not.toContain('is-visible');
    });

    it('esconde enquanto qualquer CTA de plano está visível e volta ao sair da tela', async () => {
        const observers = stubIntersectionObserver();
        await mountHome({ observers });
        const observer = observers.find((item) => item.targets.some((target) => target.matches('.pricing-cta a')));
        const bar = () => wrapper.get('[data-test="mobile-cta"]');
        observer.report(() => false);
        await nextTick();
        expect(bar().attributes('aria-hidden')).toBe('false');

        observer.report((target) => target.matches('.pricing-cta a'));
        await nextTick();
        expect(bar().attributes('aria-hidden')).toBe('true');
        expect(bar().get('a').attributes('tabindex')).toBe('-1');

        observer.report(() => false);
        await nextTick();
        expect(bar().attributes('aria-hidden')).toBe('false');
    });
});
