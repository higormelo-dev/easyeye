import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import SiteLayout from '@/Layouts/SiteLayout.vue';

const page = vi.hoisted(() => ({ props: {} }));
vi.mock('@inertiajs/vue3', () => ({ usePage: () => page }));

/**
 * Rodapé do site: antes 10 links fixos para páginas inexistentes (404). Agora
 * cada página só aparece quando o servidor manda a rota (routes.* != null).
 */
const t = {
    nav: {
        features: 'Recursos', demo: 'Demonstração', how: 'Como funciona', pricing: 'Planos', testimonials: 'Depoimentos',
        faq: 'FAQ', contact: 'Contato', login: 'Entrar', get_started: 'Começar grátis', language: 'Idioma',
        menu: 'Menu principal', skip: 'Pular para o conteúdo', create_account: 'Criar conta',
    },
    footer: {
        tagline: 'Gestão clínica.', product: 'Produto', system: 'Acesso e contato', company: 'Empresa',
        login: 'Acessar sistema', register: 'Criar conta', help: 'Central de ajuda', status: 'Status da plataforma',
        api: 'API & Integrações', about: 'Sobre nós', blog: 'Blog', partners: 'Parceiros', contact: 'Contato',
        careers: 'Trabalhe conosco', privacy: 'Privacidade', terms: 'Termos de uso', lgpd: 'LGPD',
        copyright: '© :year :name.',
    },
};

const routesWithoutPages = {
    siteHome: '/', go: '/go', register: '/register',
    help: null, status: null, apiDocs: null, about: null, blog: null, partners: null, careers: null,
    privacy: null, terms: null, lgpd: null,
};

let wrapper;

function mountLayout(routes = {}, { hasHero = true, content = '<p>conteúdo</p>' } = {}) {
    wrapper = mount(SiteLayout, {
        props: { t, routes: { ...routesWithoutPages, ...routes }, hasHero },
        slots: { default: content },
    });

    return wrapper;
}

const footerHrefs = () => wrapper.findAll('footer a').map((link) => link.attributes('href'));

beforeEach(() => { page.props = { locales: [] }; });
afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    vi.restoreAllMocks();
});

describe('SiteLayout — rodapé', () => {
    it.each([true, false])('oculta o link para depoimentos não publicados (hasHero = %s)', async (hasHero) => {
        mountLayout({}, { hasHero });
        await wrapper.setProps({ t: { ...t, nav: { ...t.nav, testimonials: null } } });

        expect(footerHrefs()).not.toContain('/#depoimentos');
        expect(footerHrefs()).toEqual(expect.arrayContaining(['/#funcionalidades', '/#demonstracao', '/#precos', '/#faq']));

        await wrapper.setProps({ t });
        expect(footerHrefs()).toContain('/#depoimentos');
    });

    it('não aponta para páginas que não existem (antes: 10 URLs fixas respondendo 404)', () => {
        mountLayout();

        for (const dead of ['/ajuda', '/status', '/api-docs', '/sobre', '/blog', '/parceiros', '/carreiras', '/privacidade', '/termos', '/lgpd']) {
            expect(footerHrefs()).not.toContain(dead);
        }
        expect(wrapper.find('.footer-legal').exists()).toBe(false);
        expect(footerHrefs()).toEqual(expect.arrayContaining(['/go', '/register', '/#contato']));
        expect(wrapper.get('.footer-inner').classes()).toContain('footer-inner--compact');
        expect(wrapper.findAll('.footer-col')).toHaveLength(2);
        expect(wrapper.findAll('.footer-col')[1].get('a[href="/#contato"]').text()).toBe('Contato');
    });

    it('página que passa a existir aparece sozinha, na ordem de antes', () => {
        mountLayout({ privacy: '/privacidade', terms: '/termos', apiDocs: '/docs/api', about: '/sobre' });

        const legal = wrapper.get('.footer-legal').findAll('a').map((link) => [link.text(), link.attributes('href')]);
        expect(legal).toEqual([['Privacidade', '/privacidade'], ['Termos de uso', '/termos']]);
        expect(wrapper.get('footer a[href="/docs/api"]').text()).toBe('API & Integrações');
        expect(wrapper.get('footer a[href="/sobre"]').text()).toBe('Sobre nós');
        expect(wrapper.get('.footer-inner').classes()).not.toContain('footer-inner--compact');
        expect(wrapper.findAll('.footer-col')).toHaveLength(3);
    });

    it.each(['about', 'blog', 'partners', 'careers'])('exibe Empresa quando a página %s está disponível', (key) => {
        mountLayout({ [key]: '/institucional' });
        const company = wrapper.findAll('.footer-col')[2];
        expect(company.get('h2').text()).toBe('Empresa');
        expect(company.findAll('a')).toHaveLength(1);
        expect(company.get('a').attributes('href')).toBe('/institucional');
    });
});

describe('SiteLayout — navegação', () => {
    it('prioriza os mesmos quatro destinos no desktop e mobile e mantém detalhes no rodapé', () => {
        mountLayout();
        const expected = [
            ['Recursos', '/#funcionalidades'], ['Demonstração', '/#demonstracao'],
            ['Planos', '/#precos'], ['Contato', '/#contato'],
        ];
        for (const selector of ['.nav-links', '#site-mobile-menu ul']) {
            expect(wrapper.get(selector).findAll('a').map(link => [link.text(), link.attributes('href')])).toEqual(expected);
        }
        expect(footerHrefs()).toEqual(expect.arrayContaining(['/#como-funciona', '/#depoimentos', '/#faq']));
    });

    it('usa Criar conta quando a página não informa prazo efetivo (páginas legais)', () => {
        mountLayout();
        expect(wrapper.get('.nav-right .btn-primary').text()).toBe('Criar conta');
        expect(wrapper.get('.mobile-ctas .btn-primary').text()).toBe('Criar conta');
        expect(wrapper.get('.nav-right .btn-primary').attributes('href')).toBe('/register');
    });

    it.each([0, -1])('encaminha ao contato quando o prazo efetivo é %s', async (trialDays) => {
        page.props.trialDays = trialDays;
        mountLayout();
        for (const selector of ['.nav-right .btn-primary', '.mobile-ctas .btn-primary']) {
            expect(wrapper.get(selector).text()).toBe('Contato');
            expect(wrapper.get(selector).attributes('href')).toBe('/#contato');
        }
        expect(footerHrefs()).not.toContain('/register');
        await wrapper.get('.nav-mobile-btn').trigger('click');
        expect(document.documentElement.classList.contains('site-menu-open')).toBe(true);
        await wrapper.get('.mobile-ctas .btn-primary').trigger('click');
        expect(wrapper.get('.nav-mobile-btn').attributes('aria-expanded')).toBe('false');
        expect(document.documentElement.classList.contains('site-menu-open')).toBe(false);
    });

    it('anuncia início gratuito somente quando a página informa prazo efetivo positivo', () => {
        page.props.trialDays = 7;
        mountLayout();
        expect(wrapper.get('.nav-right .btn-primary').text()).toBe('Começar grátis');
        expect(wrapper.get('.mobile-ctas .btn-primary').text()).toBe('Começar grátis');
    });

    it('botão do menu móvel usa o rótulo traduzido (antes: "Menu" fixo)', () => {
        mountLayout();

        const button = wrapper.get('.nav-mobile-btn');
        expect(button.attributes('aria-label')).toBe('Menu principal');
        expect(button.attributes('aria-controls')).toBe('site-mobile-menu');
    });

    it('menu móvel aberto trava a rolagem da página de trás', async () => {
        mountLayout();
        const button = wrapper.get('.nav-mobile-btn');

        await button.trigger('click');
        expect(document.documentElement.classList.contains('site-menu-open')).toBe(true);

        await button.trigger('click');
        expect(document.documentElement.classList.contains('site-menu-open')).toBe(false);
    });

    it('selecionar Demonstração fecha o menu móvel e libera a rolagem', async () => {
        mountLayout();
        const toggle = wrapper.get('.nav-mobile-btn');
        await toggle.trigger('click');
        await wrapper.get('#site-mobile-menu a[href="/#demonstracao"]').trigger('click');
        expect(toggle.attributes('aria-expanded')).toBe('false');
        expect(document.documentElement.classList.contains('site-menu-open')).toBe(false);
    });
});

describe('SiteLayout — seção atual', () => {
    let frames;
    let headerBottom;
    let bounds;
    let nextFrame;

    beforeEach(() => {
        frames = new Map();
        headerBottom = 68;
        bounds = {};
        nextFrame = 1;
        vi.spyOn(window, 'requestAnimationFrame').mockImplementation((callback) => {
            const id = nextFrame++;
            frames.set(id, callback);
            return id;
        });
        vi.spyOn(window, 'cancelAnimationFrame').mockImplementation((id) => frames.delete(id));
    });

    function mountSections(options = {}) {
        mountLayout({}, {
            content: ['hero', 'demonstracao', 'funcionalidades', 'precos', 'faq', 'contato']
                .map(id => `<section id="${id}">${id}</section>`).join(''),
            ...options,
        });
        vi.spyOn(wrapper.get('#navbar').element, 'getBoundingClientRect')
            .mockImplementation(() => ({ top: 0, bottom: headerBottom }));
        wrapper.findAll('main section').forEach((section) => {
            vi.spyOn(section.element, 'getBoundingClientRect')
                .mockImplementation(() => bounds[section.attributes('id')] ?? { top: 1000, bottom: 1400 });
        });
    }

    async function flushFrame() {
        const queued = [...frames.values()];
        frames.clear();
        queued.forEach(callback => callback(0));
        await nextTick();
    }

    function expectCurrent(anchor) {
        for (const selector of ['.nav-links', '#site-mobile-menu ul']) {
            const current = wrapper.get(selector).findAll('[aria-current="location"]');
            expect(current.map(link => link.attributes('href'))).toEqual(anchor ? [`/${anchor}`] : []);
        }
    }

    it('marca o mesmo destino no desktop e no menu móvel desde a primeira leitura', async () => {
        mountSections();
        bounds.funcionalidades = { top: 84, bottom: 400 };
        await flushFrame();
        expectCurrent('#funcionalidades');

        await wrapper.get('.nav-mobile-btn').trigger('click');
        expectCurrent('#funcionalidades');
        expect(wrapper.get('#site-mobile-menu').classes()).toContain('open');
    });

    it('só mantém a seção enquanto ela cobre a linha de leitura, inclusive nos limites', async () => {
        mountSections();
        bounds.demonstracao = { top: 85, bottom: 500 };
        await flushFrame();
        expectCurrent(null);

        bounds.demonstracao.top = 84;
        window.dispatchEvent(new Event('scroll'));
        await flushFrame();
        expectCurrent('#demonstracao');

        bounds.demonstracao = { top: -400, bottom: 84 };
        bounds.funcionalidades = { top: 84, bottom: 500 };
        window.dispatchEvent(new Event('scroll'));
        await flushFrame();
        expectCurrent('#funcionalidades');

        bounds.funcionalidades = { top: -500, bottom: 83 };
        bounds.faq = { top: 0, bottom: 500 };
        window.dispatchEvent(new Event('scroll'));
        await flushFrame();
        expectCurrent(null);
    });

    it('recalcula a linha pela altura real do cabeçalho em resize e agrupa eventos por frame', async () => {
        mountSections();
        bounds.precos = { top: 100, bottom: 500 };
        await flushFrame();
        expectCurrent(null);
        window.requestAnimationFrame.mockClear();

        headerBottom = 100;
        window.dispatchEvent(new Event('resize'));
        window.dispatchEvent(new Event('resize'));
        window.dispatchEvent(new Event('scroll'));
        expect(window.requestAnimationFrame).toHaveBeenCalledTimes(1);
        await flushFrame();
        expectCurrent('#precos');

        headerBottom = 68;
        window.dispatchEvent(new Event('resize'));
        await flushFrame();
        expectCurrent(null);
    });

    it('limpa o destaque ao retornar ao hero e não marca se o cabeçalho ocupa a tela', async () => {
        mountSections();
        bounds.contato = { top: 0, bottom: 800 };
        await flushFrame();
        expectCurrent('#contato');

        bounds.contato = { top: 1000, bottom: 1800 };
        bounds.hero = { top: 0, bottom: 800 };
        window.dispatchEvent(new Event('scroll'));
        await flushFrame();
        expectCurrent(null);

        bounds.contato = { top: 0, bottom: 2000 };
        headerBottom = window.innerHeight;
        window.dispatchEvent(new Event('resize'));
        await flushFrame();
        expectCurrent(null);
    });

    it('não marca destinos em páginas legais mesmo se houver IDs iguais no conteúdo', async () => {
        mountSections({ hasHero: false });
        bounds.funcionalidades = { top: 0, bottom: 500 };
        await flushFrame();
        window.dispatchEvent(new Event('scroll'));
        window.dispatchEvent(new Event('resize'));
        await flushFrame();
        expectCurrent(null);
    });

    it('preserva a navegação nativa por âncora sem antecipar o estado atual', async () => {
        mountSections();
        bounds.demonstracao = { top: 0, bottom: 500 };
        await flushFrame();
        const link = wrapper.get('.nav-links a[href="/#precos"]');
        const click = new MouseEvent('click', { bubbles: true, cancelable: true });
        link.element.dispatchEvent(click);
        await nextTick();
        expect(click.defaultPrevented).toBe(false);
        expect(link.attributes('href')).toBe('/#precos');
        expectCurrent('#demonstracao');
    });

    it('cancela o frame pendente e remove os listeners ao desmontar', async () => {
        const removeListener = vi.spyOn(window, 'removeEventListener');
        mountSections();
        await flushFrame();
        await wrapper.get('.nav-mobile-btn').trigger('click');
        window.dispatchEvent(new Event('resize'));
        const pendingFrame = [...frames.keys()][0];

        wrapper.unmount();
        wrapper = null;
        expect(window.cancelAnimationFrame).toHaveBeenCalledWith(pendingFrame);
        expect(frames.size).toBe(0);
        expect(document.documentElement.classList.contains('site-menu-open')).toBe(false);
        expect(removeListener).toHaveBeenCalledWith('scroll', expect.any(Function));
        expect(removeListener).toHaveBeenCalledWith('resize', expect.any(Function));

        window.requestAnimationFrame.mockClear();
        window.dispatchEvent(new Event('scroll'));
        window.dispatchEvent(new Event('resize'));
        expect(window.requestAnimationFrame).not.toHaveBeenCalled();
    });
});

describe('SiteLayout — estrutura', () => {
    it('"Pular para o conteúdo" é o primeiro link e leva ao <main> (antes a página não tinha <main>)', () => {
        mountLayout();

        const first = wrapper.find('a');
        expect(first.classes()).toContain('skip-link');
        expect(first.attributes('href')).toBe('#conteudo');
        expect(first.text()).toBe('Pular para o conteúdo');

        const main = wrapper.get('main#conteudo');
        expect(main.attributes('tabindex')).toBe('-1');
        expect(main.text()).toBe('conteúdo');
    });

    it('colunas do rodapé são h2 (antes h5 solto na hierarquia)', () => {
        mountLayout();

        expect(wrapper.findAll('footer h2').map((heading) => heading.text())).toEqual(['Produto', 'Acesso e contato']);
        expect(wrapper.find('footer h5').exists()).toBe(false);
    });
});
