import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
import Home from '@/Pages/Site/Home.vue';

vi.mock('@/Layouts/SiteLayout.vue', () => ({
    default: { props: ['t', 'routes', 'appName', 'hasHero'], template: '<div class="layout-stub"><slot /></div>' },
}));
vi.mock('@/Components/Site/ContactForm.vue', () => ({ default: { props: ['t', 'action'], template: '<form class="contact-form-stub" />' } }));
vi.mock('@/site-animations', () => ({ initSiteAnimations: () => () => {} }));
// <Head> não renderiza: com a página no document, o happy-dom baixaria as
// fontes e a imagem do hero dos <link> (rede real dentro do teste).
vi.mock('@inertiajs/vue3', () => ({ Head: { render: () => null } }));

/**
 * Landing (Site/Home):
 *  - FAQ e abas da demonstração acessíveis por teclado e leitor de tela;
 *  - demonstração só com as abas que têm print, agenda marcada como fictícia;
 *  - planos: o primeiro lista o que inclui, os seguintes só o que acrescentam
 *    ("Tudo do X, mais:"), sem linhas de "não incluído";
 *  - "Disponível no …" e prazo de teste vêm dos planos (antes "14 dias" fixo);
 *  - optotipos "Em breve" só no card do Premium;
 *  - CTA fixo no celular entre o hero e o contato.
 */
const section = (extra = {}) => ({ label: 'Rótulo', title: 'Título', subtitle: 'Subtítulo', items: [], ...extra });

function buildT() {
    return {
        meta: { title: 'EasyEye', description: 'd', og_title: 'o', og_description: 'o' },
        hero: {
            badge: 'b', title: 't', title_em: 'e', subtitle: 's', cta_primary: 'Começar grátis', cta_secondary: 'Ver o sistema',
            cta_note: ':days dias grátis · sem cartão de crédito', trust: 'Mais de :count', trust_initials: ['RM', 'AC'],
            visual_alt: 'Painel inicial do EasyEye', card_top_lbl: 'Imagens por olho', card_top_val: 'OCT',
            card_bot_lbl: 'CFM e LGPD', card_bot_val: 'Assinado',
        },
        metrics: [],
        problems: section({ bridge: 'Ponte' }),
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
            groups: [
                {
                    key: 'consultorio', icon: 'ti-stethoscope', title: 'Consultório', audience: 'Para o oftalmologista',
                    items: [
                        { text: 'Prontuário oftalmológico' },
                        { text: 'Integração com aparelhos', feature: 'has_api_integrator' },
                        { text: 'IA na redação de laudos', feature: 'has_ai_report_drafting' },
                    ],
                },
                {
                    key: 'faturamento', icon: 'ti-receipt', title: 'Faturamento', audience: 'Para o faturamento',
                    items: [{ text: 'Guias TISS' }],
                    flow_label: 'Fluxo TISS', flow: ['Guia', 'Lote XML', 'Retorno e glosa'],
                },
            ],
        }),
        how: section({ steps: [{ title: 'Passo 1', text: 'a' }, { title: 'Passo 2', text: 'b' }], screenshot_alt: 'alt' }),
        differentiators: section({ proof_title: 'Na prática', proof: [{ icon: 'ti-history', label: 'Trilha de auditoria' }] }),
        testimonials: section({
            rating: ':stars de 5 estrelas',
            items: [{ name: 'Dra. Ana', text: 'Ótimo', stars: 4, initials: 'AC', role: 'Diretora' }],
        }),
        pricing: {
            label: 'Planos', title: 'Planos', subtitle: 's', trial_suffix: ':days dias', empty_title: 'e', empty_subtitle: 'e',
            contact_cta: 'Falar com especialista', featured_badge: 'Mais popular', on_request: 'Sob consulta', trial_text: ':days dias grátis para testar',
            get_started: 'Começar grátis', included_all_label: 'Em todos os planos', included_all: 'Agenda e prontuário.',
            everything_in: 'Tudo do :plan, mais:', upcoming_label: 'Em breve no Premium',
            upcoming: [{ icon: 'ti-eye', title: 'Programa completo de optotipos', badge: 'Em breve' }],
        },
        pricing_credit_note_html: 'nota',
        faq: { label: 'FAQ', title: 'Perguntas', items: [{ q: 'Funciona offline?', a: 'Não.' }, { q: 'Tem TISS?', a: 'Sim.' }] },
        contact: {
            label: 'Contato', headline_pre: 'Quer falar com o', headline_post: 'Estamos aqui', subtitle: 's', form: {},
            sales: { title: 'Vendas', desc: 'd', channel: '+55 61 98467-6485' },
            support: { title: 'Suporte', desc: 'd' },
            trial: { title: 'Teste', desc: ':days dias sem cartão', desc_no_trial: 'Sem cartão de crédito.', cta: 'Criar conta' },
            aside: { quote_text: 'q', quote_author: 'a' },
            trust_ssl: 'SSL', trust_lgpd: 'LGPD', trust_cfm: 'CFM', trust_nps: '97%',
        },
        cta: {
            title: 'Pronto?', subtitle_trial: ':days dias gratuitos, sem cartão.', subtitle: 'Sem cartão de crédito.',
            primary: 'Criar conta', secondary: 'Falar com um especialista', note: 'n',
        },
        nav: {},
        footer: {},
    };
}

const feature = (id, key, display_label, extra = {}) => ({ id, key, display_label, enabled: true, is_none: false, ...extra });

// Básico → Pro → Premium: cada um tem tudo do anterior. Enterprise não (só o limite de médicos).
function buildPlans() {
    return [
        { id: 'p1', slug: 'basico', name: 'Básico', description: '', price: 1299.5, price_period_label: '/mês', is_free: false, is_featured: false, trial_days: 14,
            features: [
                feature('b1', 'max_doctors', 'Até 1 médico'),
                feature('b2', 'ai_monthly_credits', 'Sem créditos de IA', { is_none: true }),
                feature('b3', 'has_api_integrator', 'Integração com equipamentos', { enabled: false }),
            ] },
        { id: 'p2', slug: 'pro', name: 'Pro', description: '', price: 899.9, price_period_label: '/mês', is_free: false, is_featured: true, trial_days: 7,
            features: [
                feature('r1', 'max_doctors', 'Até 3 médicos'),
                feature('r2', 'ai_monthly_credits', '100 créditos de IA por mês'),
                feature('r3', 'has_api_integrator', 'Integração com equipamentos'),
            ] },
        { id: 'p3', slug: 'premium', name: 'Premium', description: '', price: 1799.9, price_period_label: '/mês', is_free: false, is_featured: false, trial_days: null,
            features: [
                feature('m1', 'max_doctors', 'Até 3 médicos'),
                feature('m2', 'ai_monthly_credits', '500 créditos de IA por mês'),
                feature('m3', 'has_api_integrator', 'Integração com equipamentos'),
            ] },
        { id: 'p4', slug: 'enterprise', name: 'Enterprise', description: '', price: 0, price_period_label: '', is_free: true, is_featured: false, trial_days: null,
            features: [feature('e1', 'max_doctors', 'Médicos ilimitados')] },
    ];
}

let wrapper;

function stubMatchMedia(reduceMotion) {
    window.matchMedia = vi.fn((query) => ({
        matches: reduceMotion && query.includes('prefers-reduced-motion'),
        media: query,
        addEventListener() {},
        removeEventListener() {},
    }));
}

async function mountHome({ reduceMotion = false, locale = 'pt-BR', plans = buildPlans(), demoImages, heroImage = 1 } = {}) {
    stubMatchMedia(reduceMotion);
    wrapper = mount(Home, {
        attachTo: document.body,
        props: {
            t: buildT(),
            plans,
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
const rows = (index) => card(index).findAll('.pricing-features li').map((li) => li.text());

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
    it('só mostra as abas que têm print; a agenda avisa que os dados são fictícios', async () => {
        await mountHome();

        expect(tabs().map((tab) => tab.text())).toEqual(['Prontuário', 'Imagens', 'Agenda']);
        expect(wrapper.get('#demo-panel-agenda .demo-fictitious').text()).toBe('Dados fictícios.');
        expect(wrapper.find('#demo-panel-prontuario .demo-fictitious').exists()).toBe(false);

        const shot = wrapper.get('#demo-panel-prontuario img');
        expect(shot.attributes('src')).toBe('/site/images/demo-prontuario.webp?v=1');
        expect(shot.attributes('alt')).toBe('c1');
        expect([shot.attributes('width'), shot.attributes('height'), shot.attributes('loading')]).toEqual(['1600', '731', 'lazy']);
    });

    it('abas ligadas aos painéis; só a ativa entra no Tab (tabindex 0)', async () => {
        await mountHome();

        tabs().forEach((tab, index) => {
            const panel = wrapper.get(`#${tab.attributes('aria-controls')}`);
            expect(panel.attributes('role')).toBe('tabpanel');
            expect(panel.attributes('aria-labelledby')).toBe(tab.attributes('id'));
            expect(tab.attributes('tabindex')).toBe(index === 0 ? '0' : '-1');
        });
    });

    it('setas, Home e End trocam a aba e levam o foco junto', async () => {
        await mountHome();
        const press = async (key) => {
            await tabs()[activeTabIndex()].trigger('keydown', { key });
            await nextTick();
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

    // A troca vem do fim da animação da aba ativa (o timer visível); pausar a
    // animação (mouse, foco, fora da tela) pausa a troca junto.
    const endTimer = async () => {
        await tabs()[activeTabIndex()].trigger('animationend', { animationName: 'demo-timer' });
        await nextTick();
    };
    const tablist = () => wrapper.get('[role="tablist"]');

    it('fim do timer da aba ativa passa para a próxima, em ciclo', async () => {
        await mountHome();

        expect(tablist().classes()).toContain('is-rotating');
        await endTimer();
        expect(activeTabIndex()).toBe(1);
        await endTimer();
        await endTimer();
        expect(activeTabIndex()).toBe(0);
    });

    it('timer pausa com o mouse ou o foco na seção', async () => {
        await mountHome();
        const demo = wrapper.get('#demonstracao');

        await demo.trigger('mouseenter');
        expect(tablist().classes()).toContain('is-paused');

        await demo.trigger('mouseleave');
        await demo.trigger('focusin');
        expect(tablist().classes()).toContain('is-paused');
    });

    it('escolher uma aba desliga a troca automática', async () => {
        await mountHome();

        await tabs()[2].trigger('click');
        expect(tablist().classes()).not.toContain('is-rotating');
        await endTimer();
        expect(activeTabIndex()).toBe(2);
    });

    it('com "reduzir movimento" no sistema não há timer nem troca automática', async () => {
        await mountHome({ reduceMotion: true });

        expect(tablist().classes()).not.toContain('is-rotating');
        await endTimer();
        expect(activeTabIndex()).toBe(0);
    });
});

describe('Home — planos', () => {
    it('o primeiro plano lista o que inclui; os seguintes só o que acrescentam ao anterior', async () => {
        await mountHome();

        expect(rows(0)).toEqual(['Até 1 médico']);
        expect(card(0).find('[data-test="pricing-inherits"]').exists()).toBe(false);

        expect(card(1).get('[data-test="pricing-inherits"]').text()).toBe('Tudo do Básico, mais:');
        expect(rows(1)).toEqual(['Até 3 médicos', '100 créditos de IA por mês', 'Integração com equipamentos']);

        // Mesmo limite de médicos e mesma integração do Pro: só o que muda aparece.
        expect(card(2).get('[data-test="pricing-inherits"]').text()).toBe('Tudo do Pro, mais:');
        expect(rows(2)).toEqual(['500 créditos de IA por mês']);
    });

    it('ausências não viram linha ("não incluído", "sem créditos")', async () => {
        await mountHome();

        const allRows = [0, 1, 2, 3].flatMap(rows);
        expect(allRows).not.toContain('Sem créditos de IA');
        expect(wrapper.find('.pricing-features .visually-hidden').exists()).toBe(false);
    });

    it('plano que não tem tudo do anterior lista o que tem, sem "Tudo do …"', async () => {
        await mountHome();

        expect(card(3).find('[data-test="pricing-inherits"]').exists()).toBe(false);
        expect(rows(3)).toEqual(['Médicos ilimitados']);
    });

    it('optotipos aparece como "Em breve" só no card do Premium', async () => {
        await mountHome();

        const upcoming = wrapper.findAll('[data-test="pricing-upcoming"]');
        expect(upcoming).toHaveLength(1);
        expect(card(2).get('[data-test="pricing-upcoming"]').text()).toContain('Programa completo de optotipos');
        expect(card(2).get('.pricing-upcoming-badge').text()).toBe('Em breve');
    });

    it('prazo de teste vem dos planos: menor prazo no hero, prazo de cada plano no card', async () => {
        await mountHome();

        expect(wrapper.get('[data-test="hero-cta-note"]').text()).toBe('7 dias grátis · sem cartão de crédito');
        expect(card(0).get('.pricing-trial').text()).toBe('14 dias grátis para testar');
        expect(card(1).get('.pricing-trial').text()).toBe('7 dias grátis para testar');
        expect(card(2).find('.pricing-trial').exists()).toBe(false);
        expect(wrapper.get('.cta-final-sub').text()).toBe('7 dias gratuitos, sem cartão.');
    });

    it('sem plano em teste a página não promete prazo (antes "14 dias" fixo)', async () => {
        await mountHome({ plans: buildPlans().map((plan) => ({ ...plan, trial_days: null })) });

        expect(wrapper.find('[data-test="hero-cta-note"]').exists()).toBe(false);
        expect(wrapper.find('.pricing-trial').exists()).toBe(false);
        expect(wrapper.get('.cta-final-sub').text()).toBe('Sem cartão de crédito.');
        expect(wrapper.text()).toContain('Sem cartão de crédito.');
        expect(wrapper.text()).not.toMatch(/\d+ dias/);
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
        expect(flow.findAll('li').map((li) => li.attributes('style'))).toEqual(['--step: 0;', '--step: 1;', '--step: 2;']);
    });

    it('fluxo TISS é uma lista ordenada de etapas', async () => {
        await mountHome();

        const steps = wrapper.get('[data-test="audience-faturamento"] .audience-flow-steps');
        expect(steps.element.tagName).toBe('OL');
        expect(steps.findAll('li').map((li) => li.text())).toEqual(['Guia', 'Lote XML', 'Retorno e glosa']);
    });
});

describe('Home — leitor de tela, contatos e hero', () => {
    it('nota do depoimento tem nome acessível; estrelas desenhadas, uma vazia', async () => {
        await mountHome();

        const stars = wrapper.get('.testimonial-stars');
        expect(stars.attributes('role')).toBe('img');
        expect(stars.attributes('aria-label')).toBe('4 de 5 estrelas');
        expect(stars.findAll('svg')).toHaveLength(5);
        expect(stars.findAll('svg.is-empty')).toHaveLength(1);
    });

    it('e-mails vêm da prop contact (antes: contato@easyeye.com.br fixo no código)', async () => {
        await mountHome();

        const mailtos = wrapper.findAll('a[href^="mailto:"]').map((link) => link.attributes('href'));
        expect(mailtos).toContain('mailto:contato@easyeye.app');
        expect(mailtos).toContain('mailto:suporte@easyeye.app');
        expect(mailtos.some((href) => href.includes('easyeye.com.br'))).toBe(false);
    });

    it('hero mostra o painel inicial real com dimensões (sem salto de layout)', async () => {
        await mountHome();

        const shot = wrapper.get('.hero-shot');
        expect(shot.attributes('src')).toBe('/site/images/hero-dashboard.webp?v=1');
        expect(shot.attributes('alt')).toBe('Painel inicial do EasyEye');
        expect([shot.attributes('width'), shot.attributes('height'), shot.attributes('fetchpriority')]).toEqual(['1400', '735', 'high']);
    });

    it('sem o arquivo do print, o hero fica só com o texto (nunca imagem quebrada)', async () => {
        await mountHome({ heroImage: false });

        expect(wrapper.find('.hero-visual').exists()).toBe(false);
        expect(wrapper.get('.hero-inner').classes()).toContain('hero-inner--solo');
        expect(wrapper.get('.hero-title').exists()).toBe(true);
    });
});

describe('Home — CTA fixo no celular', () => {
    function stubIntersectionObserver() {
        const observers = [];
        class FakeIntersectionObserver {
            constructor(callback) {
                this.callback = callback;
                this.targets = [];
                observers.push(this);
            }

            observe(target) { this.targets.push(target); }

            disconnect() {}

            report(isVisible) {
                this.callback(this.targets.map((target) => ({ target, isIntersecting: isVisible(target) })));
            }
        }
        vi.stubGlobal('IntersectionObserver', FakeIntersectionObserver);
        window.IntersectionObserver = FakeIntersectionObserver;

        return observers;
    }

    it('aparece depois do hero e some no contato; escondido fica fora do Tab', async () => {
        const observers = stubIntersectionObserver();
        await mountHome();
        const bar = () => wrapper.get('[data-test="mobile-cta"]');

        expect(bar().attributes('aria-hidden')).toBe('true');
        expect(bar().get('a').attributes('tabindex')).toBe('-1');

        const [observer] = observers;
        expect(observer.targets.map((el) => el.className || el.id)).toEqual(['hero', 'contact', 'cta-final']);

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
});
