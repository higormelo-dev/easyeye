import { afterEach, describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import PricingPlans from '@/Components/Site/PricingPlans.vue';

const t = {
    choose_plan: 'Escolher :plan',
    details_label: 'Ver todos os recursos',
    summary_label: 'Compare os planos',
    groups: { capacity: 'Capacidade', ai: 'Inteligência artificial', resources: 'Recursos e integrações' },
    comparison_labels: {
        max_doctors: 'Médicos',
        max_storage_gb: 'Armazenamento',
        ai_monthly_credits: 'Créditos de IA',
        has_inventory_module: 'Estoque',
    },
    comparison_values: {
        up_to: 'Até :count',
        storage: ':count GB',
        credits: ':count/mês',
        unlimited: 'Ilimitado',
        none: 'Não incluído',
    },
    integrator_badge: 'Integrador incluído',
    integrator_plan: 'Incluído no :plan',
    integrator_title: 'Integrador de exames',
    integrator_flow: ['Aparelhos da clínica', 'Integrador', 'Exames no EasyEye'],
    included: 'Incluído',
    not_included: 'Não incluído',
    not_specified: 'Consultar',
    integrator_label: 'Integrador de exames',
    integrator_description: 'Envie os exames dos aparelhos para o EasyEye e mantenha-os organizados para consulta.',
    ai_chat_label: 'Assistente de IA em todas as telas',
    featured_badge: 'Mais popular',
    on_request: 'Sob consulta',
    trial_text: ':days dias grátis para testar',
    contact_cta: 'Falar com vendas no WhatsApp',
    upcoming_label: 'Em breve no Premium',
    upcoming: [{ icon: 'ti-eye', title: 'Programa de optotipos', badge: 'Em breve' }],
};

const feature = (key, display_label, extra = {}) => ({
    id: key,
    key,
    display_label,
    enabled: true,
    is_none: false,
    ...extra,
});
const plan = (id, extra = {}) => ({
    id,
    name: id,
    slug: id.toLowerCase(),
    price: 299.9,
    price_period_label: '/mês',
    is_featured: false,
    is_free: false,
    description: 'Descrição cadastrada do plano.',
    trial_days: 90,
    register_url: `/register?plan=${id}`,
    features: [
        feature('max_doctors', 'Até 1 médico', { value: 1 }),
        feature('max_storage_gb', '10 GB de armazenamento', { value: 10 }),
        feature('max_users', 'Até 3 usuários'),
        feature('max_patients', 'Até 2.000 pacientes'),
        feature('ai_monthly_credits', 'Sem créditos de IA', { is_none: true, value: 0 }),
        feature('has_ai_report_drafting', 'Redação de laudos com IA', { enabled: false }),
        feature('has_inventory_module', 'Módulo de estoque', { enabled: false }),
        feature('has_api_integrator', 'Integração com equipamentos', { enabled: false }),
    ],
    ...extra,
});

let wrapper;
function render(extra = {}) {
    wrapper = mount(PricingPlans, {
        props: {
            plans: [plan('Básico'), plan('Pro', { is_featured: true }), plan('Premium')],
            t,
            trialDays: 7,
            registerUrl: '/register',
            salesHref: 'https://wa.me/5561999999999',
            ...extra,
        },
    });
    return wrapper;
}
afterEach(() => wrapper?.unmount());

describe('PricingPlans', () => {
    it('compara capacidade, IA e estoque sem expor o controle interno da API', () => {
        render();
        for (const card of wrapper.findAll('.pricing-card')) {
            const summary = card.get('dl');
            expect(summary.attributes('aria-label')).toBe(t.summary_label);
            expect(summary.findAll('dt').map((row) => row.text())).toEqual(Object.values(t.comparison_labels));
            expect(summary.get('[data-feature="ai_monthly_credits"] dd').text()).toBe('Não incluído');
            expect(summary.get('[data-feature="max_doctors"] dd').text()).toBe('Até 1');
            expect(summary.get('[data-feature="max_storage_gb"] dd').text()).toBe('10 GB');
            expect(summary.get('[data-feature="has_inventory_module"] dd').text()).toBe('Não incluído');
            expect(summary.find('[data-feature="has_api_integrator"]').exists()).toBe(false);
        }
    });

    it('distingue limites ilimitados de créditos ausentes e mantém o detalhamento completo', () => {
        render({
            plans: [
                plan('Premium', {
                    features: [
                        feature('max_doctors', 'Médicos ilimitados', { value: 0 }),
                        feature('max_storage_gb', 'Armazenamento ilimitado', { value: 0 }),
                        feature('ai_monthly_credits', '30 créditos de IA por mês', { value: 30 }),
                    ],
                }),
            ],
        });
        expect(wrapper.findAll('dd').map((row) => row.text())).toEqual(['Ilimitado', 'Ilimitado', '30/mês']);
        expect(wrapper.get('details [data-feature="ai_monthly_credits"]').text()).toBe('30 créditos de IA por mês');
    });

    it('mantém os recursos comerciais e a descrição em detalhes nativos, sem depender do plano anterior', () => {
        render();
        for (const card of wrapper.findAll('.pricing-card')) {
            const details = card.get('details');
            expect(details.element.open).toBe(false);
            expect(details.get('summary').text()).toBe(t.details_label);
            expect(details.get('.pricing-desc').text()).toBe('Descrição cadastrada do plano.');
            expect(details.findAll('.pricing-features li')).toHaveLength(7);
            expect(details.get('[data-group="capacity"]').text()).toContain('Até 2.000 pacientes');
            expect(details.get('[data-group="ai"]').text().replace(/\s+/g, ' ')).toContain(
                'Redação de laudos com IA — Não incluído',
            );
            expect(details.get('[data-group="resources"]').text().replace(/\s+/g, ' ')).toContain(
                'Módulo de estoque — Não incluído',
            );
            expect(details.get('[data-feature="ai_monthly_credits"]').text()).toBe('Sem créditos de IA');
        }
        expect(wrapper.find('[data-test="pricing-inherits"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Tudo do');
    });

    it('preserva o plano escolhido no CTA e usa o prazo global em todos os planos pagos', () => {
        render();
        expect(wrapper.findAll('.pricing-cta a').map((link) => [link.text(), link.attributes('href')])).toEqual([
            ['Escolher Básico', '/register?plan=Básico'],
            ['Escolher Pro', '/register?plan=Pro'],
            ['Escolher Premium', '/register?plan=Premium'],
        ]);
        expect(wrapper.findAll('.pricing-trial').map((note) => note.text())).toEqual(
            Array(3).fill('7 dias grátis para testar'),
        );
        expect(wrapper.text()).not.toContain('90 dias');
    });

    it('mantém o fallback do cadastro para planos sem URL própria enquanto o teste está disponível', () => {
        render({ plans: [plan('Básico', { register_url: undefined })] });
        expect(wrapper.get('.pricing-cta a').attributes('href')).toBe('/register');
    });

    it('direciona todos os planos pagos ao contato comercial quando o teste está desativado', () => {
        render({ trialDays: 0 });
        expect(wrapper.findAll('.pricing-cta a').map((link) => [link.attributes('href'), link.text()])).toEqual(
            Array(3).fill(['https://wa.me/5561999999999', t.contact_cta]),
        );
        expect(wrapper.find('.pricing-trial').exists()).toBe(false);
        expect(wrapper.findAll('.price-value').map((price) => price.text())).toEqual(Array(3).fill('299,90'));
    });

    it('preserva Sob consulta e direciona o preço zero ao canal comercial, sem criar conta ou prometer teste', () => {
        render({ plans: [plan('Enterprise', { is_free: true, price: 0, is_featured: true })] });
        expect(wrapper.get('.pricing-price').text()).toBe('Sob consulta');
        expect(wrapper.get('.pricing-cta a').text()).toBe(t.contact_cta);
        expect(wrapper.get('.pricing-cta a').attributes('href')).toBe('https://wa.me/5561999999999');
        expect(wrapper.find('.pricing-trial').exists()).toBe(false);
    });

    it.each([
        ['pt-BR', '1.299,50'],
        ['pt_BR', '1.299,50'],
        ['en', '1,299.50'],
    ])('formata preço e mantém moeda brasileira no idioma %s', (locale, formatted) => {
        render({ locale, plans: [plan('Básico', { price: 1299.5 })] });
        expect(wrapper.get('.price-value').text()).toBe(formatted);
        expect(wrapper.get('.price-currency').text()).toBe('R$');
        expect(wrapper.get('.price-period').text()).toBe('/mês');
    });

    it('apresenta estoque na comparação e integrador habilitado nos detalhes', () => {
        render({
            plans: [
                plan('Pro', {
                    features: [
                        feature('has_inventory_module', 'Módulo de estoque'),
                        feature('has_api_integrator', 'Integração com equipamentos'),
                    ],
                }),
            ],
        });
        expect(wrapper.findAll('dd').map((value) => value.text())).toEqual(['Incluído']);
        expect(wrapper.text()).not.toContain('Não incluído');
    });

    it('diferencia recurso não informado de recurso não incluído e omite categorias ausentes de todo o catálogo', () => {
        render({
            plans: [
                plan('Básico', { features: [] }),
                plan('Pro', { features: [feature('max_doctors', 'Até 3 médicos')] }),
            ],
        });
        const cards = wrapper.findAll('.pricing-card');
        expect(cards[0].get('dd').text()).toBe('Consultar');
        expect(cards[0].text()).not.toContain('Não incluído');
        expect(cards[1].get('dd').text()).toBe('Até 3 médicos');
        expect(wrapper.findAll('dt')).toHaveLength(2);
        expect(wrapper.text()).not.toContain('Armazenamento');
    });

    it('preserva recursos novos em Recursos e integrações sem depender de uma lista fechada', () => {
        render({ plans: [plan('Pro', { features: [feature('future_feature', 'Novo recurso do catálogo')] })] });
        expect(wrapper.get('[data-group="resources"]').text()).toContain('Novo recurso do catálogo');
        expect(wrapper.find('dl').exists()).toBe(false);
    });

    it('explica o alcance do assistente de IA sem o termo de implementação chat flutuante', () => {
        render({
            plans: [
                plan('Pro', {
                    features: [feature('has_ai_chat_assistant', 'Assistente virtual de IA (chat flutuante)')],
                }),
            ],
        });
        expect(wrapper.get('[data-group="ai"]').text()).toContain('Assistente de IA em todas as telas');
        expect(wrapper.text()).not.toContain('chat flutuante');
    });

    it.each([false, undefined])(
        'omite a API e o integrador indisponível sem anunciar Não incluído (habilitado=%s)',
        (enabled) => {
            const features = [feature('api_monthly_exam_sends', 'Envios ilimitados pela API')];
            if (enabled !== undefined)
                features.push(feature('has_api_integrator', 'Integração com equipamentos oftalmológicos', { enabled }));
            const original = structuredClone(features);
            render({ plans: [plan('Básico', { features })] });
            expect(wrapper.find('[data-feature="has_api_integrator"]').exists()).toBe(false);
            expect(wrapper.find('[data-feature="api_monthly_exam_sends"]').exists()).toBe(false);
            expect(wrapper.text()).not.toContain('Não incluído');
            expect(wrapper.text()).not.toContain('API');
            expect(features).toEqual(original);
        },
    );

    it('destaca o benefício do integrador no Premium habilitado sem anunciar API pública', async () => {
        const features = [
            feature('api_monthly_exam_sends', 'Envios ilimitados pela API'),
            feature('has_api_integrator', 'Integração com equipamentos oftalmológicos'),
        ];
        render({ plans: [plan('Premium', { features })] });
        const integrator = wrapper.get('details [data-feature="has_api_integrator"]');
        expect(integrator.text()).toBe('Integrador de exames');
        expect(integrator.get('i').classes()).toContain('ti-circle-check');
        const highlight = wrapper.get('[data-test="premium-integrator"]');
        expect(highlight.get('h3').text()).toBe(t.integrator_title);
        expect(highlight.get('.pricing-integrator-plan').text()).toBe('Incluído no Premium');
        expect(highlight.element.closest('.pricing-card')).toBeNull();
        expect(wrapper.get('.pricing-badge--integrator').text()).toBe('Integrador incluído');
        expect(highlight.get('a').attributes('href')).toBe('/register?plan=Premium');
        expect(highlight.get('p').text()).toBe(t.integrator_description);
        expect(highlight.element.closest('details')).toBeNull();
        expect(wrapper.find('dl [data-feature="has_api_integrator"]').exists()).toBe(false);
        expect(wrapper.find('[data-feature="api_monthly_exam_sends"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('API');
        expect(wrapper.text()).not.toContain('Não incluído');
        await wrapper.setProps({ trialDays: 0 });
        expect(wrapper.get('.pricing-integrator-cta').attributes('href')).toBe('https://wa.me/5561999999999');
        expect(wrapper.get('.pricing-integrator-cta').text()).toContain(t.contact_cta);
        await wrapper.setProps({
            plans: [plan('Premium', { features: [feature('has_api_integrator', 'Integração', { enabled: false })] })],
        });
        expect(wrapper.find('[data-test="premium-integrator"]').exists()).toBe(false);
    });

    it('mantém optotipos identificados como Em breve somente nos detalhes do Premium', () => {
        render();
        const cards = wrapper.findAll('.pricing-card');
        expect(cards[0].find('[data-test="pricing-upcoming"]').exists()).toBe(false);
        expect(cards[1].find('[data-test="pricing-upcoming"]').exists()).toBe(false);
        const upcoming = cards[2].get('details [data-test="pricing-upcoming"]');
        expect(upcoming.text()).toContain('Programa de optotipos');
        expect(upcoming.get('.pricing-upcoming-badge').text()).toBe('Em breve');
    });

    it('aceita catálogo e recursos vazios sem inventar oferta ou esconder a descrição', () => {
        render({ plans: [] });
        expect(wrapper.find('article').exists()).toBe(false);
        wrapper.unmount();
        render({ plans: [plan('Pro', { features: undefined })] });
        expect(wrapper.find('dl').exists()).toBe(false);
        expect(wrapper.get('.pricing-desc').text()).toBe('Descrição cadastrada do plano.');
    });
});

describe('PricingPlans — ciclo de cobrança', () => {
    const cycleT = {
        ...t,
        cycle_selector_label: 'Ciclo de cobrança',
        cycle_save_up_to: 'até :percent% off',
        monthly_equivalent: 'equivale a :price/mês',
        savings_badge: 'Economize :percent%',
        cycle_unavailable: 'Disponível no ciclo :cycle',
    };
    const price = (cycle, label, months, value, savings = 0, periodLabel = '/mês') => ({
        cycle,
        label,
        months,
        price: value,
        period_label: periodLabel,
        monthly_equivalent: Math.round((value / months) * 100) / 100,
        savings_percent: savings,
    });
    const withCycles = () => [
        plan('Básico', { default_cycle: 'monthly', prices: [price('monthly', 'Mensal', 1, 299.9)] }),
        plan('Pro', {
            is_featured: true,
            default_cycle: 'monthly',
            prices: [price('monthly', 'Mensal', 1, 899.9), price('yearly', 'Anual', 12, 8639.04, 20, '/ano')],
        }),
    ];

    it('mostra os ciclos oferecidos com a maior economia e começa no mensal', () => {
        render({ t: cycleT, plans: withCycles() });

        const options = wrapper.findAll('[role="radio"]');
        expect(options.map((o) => o.attributes('aria-label'))).toEqual(['Mensal', 'Anual, até 20% off']);
        expect(options[0].attributes('aria-checked')).toBe('true');
        expect(wrapper.get('[role="radiogroup"]').attributes('aria-label')).toBe('Ciclo de cobrança');
        expect(wrapper.findAll('.price-value').map((p) => p.text())).toEqual(['299,90', '899,90']);
    });

    it('anual troca preço, período, equivalente mensal, economia e o link do cadastro', async () => {
        render({ t: cycleT, plans: withCycles() });

        await wrapper.get('[data-cycle="yearly"]').trigger('click');

        const [basic, pro] = wrapper.findAll('.pricing-card');
        expect(pro.get('.price-value').text()).toBe('8.639,04');
        expect(pro.get('.price-period').text()).toBe('/ano');
        expect(pro.get('[data-test="price-equivalent"]').text().replace(/\s+/g, ' ')).toBe(
            'equivale a R$ 719,92/mês Economize 20%',
        );
        expect(pro.get('.pricing-cta a').attributes('href')).toBe('/register?plan=Pro&cycle=yearly');

        // Plano sem anual continua vendável no ciclo dele, avisando.
        expect(basic.get('.price-value').text()).toBe('299,90');
        expect(basic.get('[data-test="cycle-unavailable"]').text()).toBe('Disponível no ciclo Mensal');
        expect(basic.get('.pricing-cta a').attributes('href')).toBe('/register?plan=Básico&cycle=monthly');
    });

    it('setas do teclado trocam o ciclo (radiogroup)', async () => {
        render({ t: cycleT, plans: withCycles() });

        await wrapper.get('[data-cycle="monthly"]').trigger('keydown', { key: 'ArrowRight' });

        expect(wrapper.get('[data-cycle="yearly"]').attributes('aria-checked')).toBe('true');
        expect(wrapper.get('[data-cycle="monthly"]').attributes('tabindex')).toBe('-1');
    });

    it('seletor lista os ciclos do mais curto ao mais longo', () => {
        const plans = [
            plan('Pro', {
                default_cycle: 'monthly',
                prices: [
                    price('yearly', 'Anual', 12, 8639.04, 20, '/ano'),
                    price('monthly', 'Mensal', 1, 899.9),
                    price('semiannual', 'Semestral', 6, 4859.46, 10, '/semestre'),
                ],
            }),
        ];
        render({ t: cycleT, plans });

        expect(wrapper.findAll('[role="radio"]').map((o) => o.attributes('data-cycle'))).toEqual([
            'monthly',
            'semiannual',
            'yearly',
        ]);
    });

    it('catálogo com um só ciclo não mostra seletor', () => {
        render({
            t: cycleT,
            plans: [plan('Básico', { default_cycle: 'monthly', prices: [price('monthly', 'Mensal', 1, 299.9)] })],
        });

        expect(wrapper.find('[role="radiogroup"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="price-equivalent"]').exists()).toBe(false);
    });
});
