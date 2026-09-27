import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import Legal from '@/Pages/Site/Legal.vue';

vi.mock('@/Layouts/SiteLayout.vue', () => ({
    default: { props: ['t', 'routes', 'appName', 'hasHero'], template: '<div class="layout-stub"><slot /></div>' },
}));

/**
 * /privacidade e /termos: documento oficial vigente em texto puro (nunca
 * v-html) ou aviso de indisponível com o e-mail de suporte.
 */
const t = {
    nav: {},
    footer: {},
    legal: {
        privacy_title: 'Política de Privacidade',
        terms_title: 'Termos de Uso',
        privacy_description: 'Política de Privacidade do EasyEye.',
        terms_description: 'Termos de Uso do EasyEye.',
        version: 'Versão :version · vigente desde :date',
        unavailable_title: 'Documento em publicação',
        unavailable_text: 'Para recebê-la agora, escreva para',
        back_home: 'Voltar para o início',
    },
};

let wrapper;

function mountLegal(props = {}) {
    wrapper = mount(Legal, {
        props: {
            kind: 'privacy',
            document: null,
            t,
            routes: { siteHome: '/' },
            contact: { sales: 'contato@easyeye.app', support: 'suporte@easyeye.app' },
            ...props,
        },
    });

    return wrapper;
}

afterEach(() => wrapper?.unmount());

describe('Site/Legal', () => {
    it('mostra título, versão vigente e parágrafos do documento', () => {
        mountLegal({
            document: { version: '2.0', effectiveFrom: '27 de setembro de 2026', content: 'Primeiro parágrafo\ncom quebra.\n\n  Segundo parágrafo.  ' },
        });

        expect(wrapper.get('h1').text()).toBe('Política de Privacidade');
        expect(wrapper.get('[data-test="legal-version"]').text()).toBe('Versão 2.0 · vigente desde 27 de setembro de 2026');
        const paragraphs = wrapper.get('[data-test="legal-body"]').findAll('p').map((p) => p.text());
        expect(paragraphs).toEqual(['Primeiro parágrafo\ncom quebra.', 'Segundo parágrafo.']);
    });

    it('conteúdo é texto: marcação vira texto, nunca HTML executado', () => {
        mountLegal({ kind: 'terms', document: { version: '1.0', effectiveFrom: 'hoje', content: 'Aceite <img src=x onerror="alert(1)"> os termos.' } });

        expect(wrapper.get('h1').text()).toBe('Termos de Uso');
        expect(wrapper.find('[data-test="legal-body"] img').exists()).toBe(false);
        expect(wrapper.get('[data-test="legal-body"]').text()).toContain('<img src=x onerror="alert(1)">');
    });

    it('sem documento publicado: aviso com o e-mail de suporte (antes: 404)', () => {
        mountLegal();

        const notice = wrapper.get('[data-test="legal-unavailable"]');
        expect(notice.attributes('role')).toBe('status');
        expect(notice.text()).toContain('Documento em publicação');
        expect(notice.get('a').attributes('href')).toBe('mailto:suporte@easyeye.app');
        expect(wrapper.get('.legal-back').attributes('href')).toBe('/');
    });
});
