import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import Register from '@/Pages/Auth/Register.vue';

vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }));
vi.mock('@/Layouts/SiteLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@inertiajs/vue3', () => ({ Head: { render: () => null } }));

const plans = [
    { id: 'basic', name: 'Básico', price: 299.9, price_period_label: '/mês', features: [] },
    { id: 'pro', name: 'Pro', price: 899.9, price_period_label: '/mês', features: [] },
    { id: 'premium', name: 'Premium', price: 1799.9, price_period_label: '/mês', features: [] },
];

let wrapper;

async function mountRegistration(selectedPlanId = 'premium', siteContent = {}) {
    wrapper = mount(Register, {
        props: {
            plans,
            selectedPlanId,
            trialDays: 9,
            routes: { siteHome: '/' },
            t: { nav: { contact: 'Contato' }, metrics: [], testimonials: { items: [] }, contact: { trust_nps: '' }, ...siteContent },
            tAuth: { register: { quick_start: 'Começar com o plano', days_free: 'dias grátis' } },
        },
        global: {
            directives: { mask: {} },
            stubs: { Transition: { template: '<div><slot /></div>' } },
        },
    });
    const fields = wrapper.findAll('input');
    await fields[0].setValue('Pessoa de Teste');
    await fields[1].setValue('pessoa@example.com');
    await fields[2].setValue('Password1!');
    await fields[3].setValue('Password1!');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    const companyFields = wrapper.findAll('input');
    await companyFields[0].setValue('Clínica de Teste');
    await companyFields[1].setValue('11988887777');
}

beforeEach(() => {
    vi.clearAllMocks();
    axios.post.mockResolvedValue({ data: {} });
});

afterEach(() => wrapper?.unmount());

describe('Cadastro — seleção do plano', () => {
    it('preserva a escolha da landing na etapa de planos e na criação da conta', async () => {
        await mountRegistration();
        expect(wrapper.get('.plan-grid-card.selected').text()).toContain('Premium');
        expect(wrapper.get('.plan-grid-card.selected').attributes('aria-pressed')).toBe('true');
        expect(wrapper.get('.plan-grid-badge').text()).toBe('9 dias grátis');

        await wrapper.get('form').trigger('submit');
        await flushPromises();
        expect(axios.post).toHaveBeenCalledWith('/register', expect.objectContaining({ plan_id: 'premium' }));
    });

    it('início rápido anuncia e mantém o plano selecionado', async () => {
        await mountRegistration();
        expect(wrapper.get('.reg-quick-start').text()).toContain('Premium');
        await wrapper.get('.reg-quick-start').trigger('click');
        await flushPromises();
        expect(axios.post).toHaveBeenCalledWith('/register', expect.objectContaining({ plan_id: 'premium' }));
    });

    it('permite mudar de plano sem perder a escolha ao voltar de etapa', async () => {
        await mountRegistration();
        const pro = wrapper.findAll('.plan-grid-card')[1];
        expect(pro.element.tagName).toBe('BUTTON');
        await pro.trigger('click');
        await wrapper.get('.reg-btn-secondary').trigger('click');
        await wrapper.get('form').trigger('submit');
        await flushPromises();
        expect(wrapper.get('.plan-grid-card.selected').text()).toContain('Pro');

        await wrapper.get('.reg-quick-start').trigger('click');
        await flushPromises();
        expect(axios.post).toHaveBeenCalledWith('/register', expect.objectContaining({ plan_id: 'pro' }));
    });

    it('seleciona o primeiro plano quando a preferência não está no catálogo', async () => {
        await mountRegistration('inactive');
        expect(wrapper.get('.plan-grid-card.selected').text()).toContain('Básico');
        await wrapper.get('form').trigger('submit');
        await flushPromises();
        expect(axios.post).toHaveBeenCalledWith('/register', expect.objectContaining({ plan_id: 'basic' }));
    });

    it('preserva a escolha após erro de validação do cadastro', async () => {
        await mountRegistration();
        axios.post.mockRejectedValueOnce({ response: { data: { errors: { company_name: ['Nome inválido.'] } } } });
        await wrapper.get('form').trigger('submit');
        await flushPromises();
        expect(wrapper.get('.plan-grid-card.selected').text()).toContain('Premium');
        expect(wrapper.get('.reg-error').text()).toBe('Nome inválido.');
    });

    it('exibe o impedimento de teste desativado e oferece acesso ao contato', async () => {
        await mountRegistration();
        axios.post.mockRejectedValueOnce({ response: { data: { errors: { plan_id: ['O cadastro para teste está indisponível.'] } } } });
        await wrapper.get('form').trigger('submit');
        await flushPromises();
        const alert = wrapper.get('[role="alert"]');
        expect(alert.text()).toContain('O cadastro para teste está indisponível.');
        expect(alert.get('a').attributes('href')).toBe('/#contato');
        expect(wrapper.get('.plan-grid-card.selected').text()).toContain('Premium');
    });
});

describe('Cadastro — conteúdo público', () => {
    it('não exibe prova social antes do lançamento e mantém o prazo real e o formulário', async () => {
        await mountRegistration();

        expect(wrapper.find('.reg-hero-metrics').exists()).toBe(false);
        expect(wrapper.find('.reg-testimonial').exists()).toBe(false);
        expect(wrapper.get('.reg-hero-badge').text()).toContain('9 dias grátis');
        expect(wrapper.findAll('.reg-trust-item span').map(item => item.text())).toEqual(['SSL 256-bit', 'LGPD', 'CFM']);
        expect(wrapper.findAll('.reg-card-trust-item span').map(item => item.text())).toEqual(['SSL', 'LGPD', 'CFM']);
        expect(wrapper.text()).not.toMatch(/500\+|97%|Ricardo Mendes/);

        await wrapper.get('.reg-btn-secondary').trigger('click');
        expect(wrapper.get('input[autocomplete="name"]').element.value).toBe('Pessoa de Teste');
        await wrapper.get('form').trigger('submit');
        expect(wrapper.get('.plan-grid-card.selected').text()).toContain('Premium');
        expect(wrapper.get('.plan-grid-badge').text()).toBe('9 dias grátis');
    });

    it('usa exclusivamente o depoimento e o indicador publicados pelo servidor', async () => {
        const published = {
            text: 'Relato autorizado recebido do servidor.', name: 'Pessoa do depoimento',
            role: 'Responsável pela clínica', initials: 'PD', stars: 4,
        };
        await mountRegistration('premium', {
            testimonials: { items: [published, { text: 'Segundo relato publicado.' }], rating: ':stars de 5 estrelas' },
            contact: { trust_nps: 'Satisfação publicada: 92%' },
        });

        const testimonial = wrapper.get('.reg-testimonial');
        expect(testimonial.get('.reg-testimonial-text').text()).toBe(published.text);
        expect(testimonial.get('.reg-testimonial-name').text()).toBe(published.name);
        expect(testimonial.get('.reg-testimonial-role').text()).toBe(published.role);
        expect(testimonial.get('.reg-testimonial-avatar').text()).toBe(published.initials);
        expect(testimonial.get('[role="img"]').attributes('aria-label')).toBe('4 de 5 estrelas');
        expect(testimonial.findAll('.ti-star-filled')).toHaveLength(4);
        expect(testimonial.findAll('.ti-star')).toHaveLength(1);
        expect(wrapper.text()).not.toContain('Segundo relato publicado.');
        expect(wrapper.findAll('.reg-trust-item, .reg-card-trust-item').filter(item => item.text() === 'Satisfação publicada: 92%')).toHaveLength(2);

        await wrapper.setProps({ t: { testimonials: { items: [] }, contact: { trust_nps: '' } } });
        expect(wrapper.find('.reg-testimonial').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Satisfação publicada: 92%');
        expect(wrapper.get('.plan-grid-card.selected').text()).toContain('Premium');
    });
});
