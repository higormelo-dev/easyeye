import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import Register from '@/Pages/Auth/Register.vue';

vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn(), defaults: { headers: { common: {} } } } }));
vi.mock('@/Layouts/SiteLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@inertiajs/vue3', () => ({ Head: { render: () => null }, router: { reload: vi.fn() } }));
vi.mock('@/echo.js', () => ({}));

const plan = {
    id: 'pro',
    name: 'Pro',
    price: 299.9,
    default_cycle: 'monthly',
    price_period_label: '/mês',
    prices: [{ cycle: 'monthly', label: 'Mensal', price: 299.9, period_label: '/mês' }],
    features: [],
};
const terms = {
    required: true,
    version_id: '5f10ce11-b529-4f97-9859-7c9cb4a93d72',
    title: 'Li e aceito os documentos legais:',
    url: '/termos',
    summary: 'Termos, incluindo o tratamento por prestador de pagamento.',
    documents: [
        {
            type: 'terms_of_service',
            version_id: '5f10ce11-b529-4f97-9859-7c9cb4a93d72',
            version: '1.0',
            title: 'Termos de Uso',
            url: '/termos',
        },
        {
            type: 'privacy_policy',
            version_id: '8f56648c-89ec-4eac-a985-434b131384bd',
            version: '1.0',
            title: 'Política de Privacidade',
            url: '/privacidade',
        },
    ],
};

let wrapper;

async function mountAtStepTwo(extraProps = {}) {
    wrapper = mount(Register, {
        props: {
            plans: [plan],
            selectedPlanId: 'pro',
            trialDays: 14,
            routes: { siteHome: '/' },
            terms,
            t: { nav: {}, testimonials: { items: [] }, contact: {} },
            tAuth: { register: { terms_required: 'Aceite os termos para continuar.' } },
            tCheckout: { ui: {}, errors: {} },
            ...extraProps,
        },
        global: { directives: { mask: {} }, stubs: { Transition: { template: '<div><slot /></div>' } } },
    });
    await wrapper.get('[data-testid="register-name"]').setValue('Pessoa de Teste');
    await wrapper.get('[data-testid="register-email"]').setValue('pessoa@example.com');
    await wrapper.get('[data-testid="register-password"]').setValue('Password1!');
    await wrapper.get('[data-testid="register-password-confirmation"]').setValue('Password1!');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    await wrapper.get('[data-testid="register-company-name"]').setValue('Clínica de Teste');
    await wrapper.get('[data-testid="register-company-phone"]').setValue('11988887777');
}

beforeEach(() => vi.clearAllMocks());
afterEach(() => wrapper?.unmount());

describe('Cadastro — aceite dos termos', () => {
    it('mostra o termo vigente e bloqueia o POST enquanto não houver aceite', async () => {
        await mountAtStepTwo();

        expect(wrapper.get('[data-testid="terms-link-terms_of_service"]').attributes('href')).toBe('/termos');
        expect(wrapper.get('[data-testid="terms-link-privacy_policy"]').attributes('href')).toBe('/privacidade');
        expect(wrapper.get('[data-testid="terms-link"]').text()).toContain('Termos de Uso');
        expect(wrapper.get('.reg-check-row').text()).toContain('prestador de pagamento');
        await wrapper.get('form').trigger('submit');

        expect(axios.post).not.toHaveBeenCalled();
        expect(wrapper.get('[data-testid="register-terms-error"]').text()).toBe('Aceite os termos para continuar.');
    });

    it('envia terms_accepted=true quando marcado', async () => {
        axios.post.mockResolvedValue({ data: { redirect: '/panel/dashboard' } });
        await mountAtStepTwo();

        await wrapper.get('[data-testid="terms-accept-checkbox"]').setValue(true);
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith(
            '/register',
            expect.objectContaining({
                terms_accepted: true,
                terms_version_ids: ['5f10ce11-b529-4f97-9859-7c9cb4a93d72', '8f56648c-89ec-4eac-a985-434b131384bd'],
            }),
        );
    });

    it('mantém o erro 422 do servidor no campo de aceite', async () => {
        axios.post.mockRejectedValue({
            response: { status: 422, data: { errors: { terms_accepted: ['Aceite exigido.'] } } },
        });
        await mountAtStepTwo();

        await wrapper.get('[data-testid="terms-accept-checkbox"]').setValue(true);
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(wrapper.get('[data-testid="register-terms-error"]').text()).toBe('Aceite exigido.');
    });

    it('desmarca e permite recarregar quando o servidor informa que os termos foram atualizados', async () => {
        const { router } = await import('@inertiajs/vue3');
        axios.post.mockRejectedValue({
            response: { status: 422, data: { errors: { terms_accepted: ['Os termos foram atualizados.'] } } },
        });
        await mountAtStepTwo();

        await wrapper.get('[data-testid="terms-accept-checkbox"]').setValue(true);
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(wrapper.get('[data-testid="terms-accept-checkbox"]').element.checked).toBe(false);
        await wrapper.get('[data-testid="register-terms-reload"]').trigger('click');
        expect(router.reload).toHaveBeenCalledWith(expect.objectContaining({ onFinish: expect.any(Function) }));
        expect(wrapper.get('[data-testid="terms-accept-checkbox"]').attributes('disabled')).toBeDefined();
    });

    it('exige novo aceite quando os UUIDs dos documentos mudam na mesma página', async () => {
        axios.post.mockResolvedValue({ data: { redirect: '/panel/dashboard' } });
        await mountAtStepTwo();
        await wrapper.get('[data-testid="terms-accept-checkbox"]').setValue(true);

        await wrapper.setProps({
            terms: {
                ...terms,
                documents: terms.documents.map((document) => ({
                    ...document,
                    version_id: `${document.version_id}-nova`,
                })),
            },
        });

        expect(wrapper.get('[data-testid="terms-accept-checkbox"]').element.checked).toBe(false);
        await wrapper.get('form').trigger('submit');
        expect(axios.post).not.toHaveBeenCalled();

        await wrapper.get('[data-testid="terms-accept-checkbox"]').setValue(true);
        await wrapper.get('form').trigger('submit');
        await flushPromises();
        expect(axios.post).toHaveBeenCalledWith(
            '/register',
            expect.objectContaining({
                terms_version_ids: [
                    '5f10ce11-b529-4f97-9859-7c9cb4a93d72-nova',
                    '8f56648c-89ec-4eac-a985-434b131384bd-nova',
                ],
            }),
        );
    });

    it('não mostra checkbox quando não há versão vigente e envia false normalmente', async () => {
        axios.post.mockResolvedValue({ data: { redirect: '/panel/dashboard' } });
        await mountAtStepTwo({
            terms: { required: false, version_id: null, title: '', url: '', summary: '', documents: [] },
        });

        expect(wrapper.find('[data-testid="terms-accept-checkbox"]').exists()).toBe(false);
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith('/register', expect.objectContaining({ terms_accepted: false }));
    });
});
