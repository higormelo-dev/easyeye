import { describe, it, expect, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import SiteLayout from '@/Layouts/SiteLayout.vue';

/**
 * Rodapé do site: antes 10 links fixos para páginas inexistentes (404). Agora
 * cada página só aparece quando o servidor manda a rota (routes.* != null).
 */
const t = {
    nav: {
        features: 'Funcionalidades', how: 'Como funciona', pricing: 'Preços', testimonials: 'Depoimentos',
        faq: 'FAQ', contact: 'Contato', login: 'Entrar', get_started: 'Começar grátis', language: 'Idioma',
        menu: 'Menu principal', skip: 'Pular para o conteúdo',
    },
    footer: {
        tagline: 'Gestão clínica.', product: 'Produto', system: 'Sistema', company: 'Empresa',
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

function mountLayout(routes = {}) {
    wrapper = mount(SiteLayout, {
        props: { t, routes: { ...routesWithoutPages, ...routes } },
        slots: { default: '<p>conteúdo</p>' },
    });

    return wrapper;
}

const footerHrefs = () => wrapper.findAll('footer a').map((link) => link.attributes('href'));

afterEach(() => wrapper?.unmount());

describe('SiteLayout — rodapé', () => {
    it('não aponta para páginas que não existem (antes: 10 URLs fixas respondendo 404)', () => {
        mountLayout();

        for (const dead of ['/ajuda', '/status', '/api-docs', '/sobre', '/blog', '/parceiros', '/carreiras', '/privacidade', '/termos', '/lgpd']) {
            expect(footerHrefs()).not.toContain(dead);
        }
        expect(wrapper.find('.footer-legal').exists()).toBe(false);
        expect(footerHrefs()).toEqual(expect.arrayContaining(['/go', '/register', '/#contato']));
    });

    it('página que passa a existir aparece sozinha, na ordem de antes', () => {
        mountLayout({ privacy: '/privacidade', terms: '/termos', apiDocs: '/docs/api', about: '/sobre' });

        const legal = wrapper.get('.footer-legal').findAll('a').map((link) => [link.text(), link.attributes('href')]);
        expect(legal).toEqual([['Privacidade', '/privacidade'], ['Termos de uso', '/termos']]);
        expect(wrapper.get('footer a[href="/docs/api"]').text()).toBe('API & Integrações');
        expect(wrapper.get('footer a[href="/sobre"]').text()).toBe('Sobre nós');
    });
});

describe('SiteLayout — navegação', () => {
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

        expect(wrapper.findAll('footer h2').map((heading) => heading.text())).toEqual(['Produto', 'Sistema', 'Empresa']);
        expect(wrapper.find('footer h5').exists()).toBe(false);
    });
});
