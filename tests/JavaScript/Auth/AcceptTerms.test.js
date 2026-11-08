import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import AcceptTerms from '@/Pages/Auth/AcceptTerms.vue';

const pageProps = { errors: {} };

vi.mock('@/Layouts/GuestLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { render: () => null },
    usePage: () => ({ props: pageProps }),
    useForm: () => ({ processing: false, post: vi.fn() }),
}));

const documents = [
    { version_id: '5f10ce11-b529-4f97-9859-7c9cb4a93d72', version: '1.0', title: 'Termos de Uso', url: '/termos' },
    {
        version_id: '8f56648c-89ec-4eac-a985-434b131384bd',
        version: '1.1',
        title: 'Política de Privacidade',
        url: '/privacidade',
    },
];
const t = { checkbox: 'Li e aceito', submit: 'Aceitar e continuar', version: 'versão :version' };

let wrapper;

beforeEach(() => {
    document.head.innerHTML = '<meta name="csrf-token" content="tok-123">';
    pageProps.errors = {};
});

afterEach(() => wrapper?.unmount());

describe('AcceptTerms', () => {
    it('envia exatamente as versões exibidas, com o token CSRF, só depois de marcar a caixa', async () => {
        wrapper = mount(AcceptTerms, { props: { documents, t } });
        await wrapper.vm.$nextTick();

        const form = wrapper.find('form[action="/accept-terms"]');
        expect(form.attributes('method')).toBe('post');
        expect(form.find('input[name="_token"]').element.value).toBe('tok-123');
        expect(form.findAll('input[name="version_ids[]"]').map((input) => input.element.value)).toEqual(
            documents.map((doc) => doc.version_id),
        );
        expect(wrapper.text()).toContain('versão 1.1');

        const submit = form.find('button[type=submit]');
        expect(submit.attributes('disabled')).toBeDefined();

        await wrapper.find('#terms-reaccept').setValue(true);
        expect(submit.attributes('disabled')).toBeUndefined();
    });

    it('mostra o erro devolvido pelo servidor', () => {
        pageProps.errors = { accepted: 'Os documentos foram atualizados.' };
        wrapper = mount(AcceptTerms, { props: { documents, t } });

        expect(wrapper.find('[role="alert"]').text()).toContain('Os documentos foram atualizados.');
    });
});
