import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { reactive } from 'vue';
import PlanFormModal from '@/Pages/Panel/Manager/Plans/PlanFormModal.vue';

/**
 * Formulário de plano: preços por ciclo (oferecer, preço, ciclo padrão) e o
 * que vai para o servidor.
 */
const submitted = [];

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'pt_BR' } }),
    useForm: (initial) => {
        const form = reactive({
            ...initial,
            errors: {},
            processing: false,
            reset() {
                Object.assign(form, initial);
            },
            clearErrors() {
                form.errors = {};
            },
            transform(callback) {
                const data = Object.fromEntries(Object.keys(initial).map((key) => [key, form[key]]));
                const send = (method) => (url) => submitted.push({ method, url, data: callback(data) });

                return { post: send('post'), put: send('put') };
            },
        });

        return form;
    },
}));

const billingCycles = [
    { value: 'monthly', label: 'Mensal', months: 1 },
    { value: 'quarterly', label: 'Trimestral', months: 3 },
    { value: 'semiannual', label: 'Semestral', months: 6 },
    { value: 'yearly', label: 'Anual', months: 12 },
];

const t = {
    tab_data: 'Dados',
    tab_pricing: 'Preços e ciclos',
    tab_features: 'Recursos',
    pricing_default: 'Padrão',
    pricing_offer: 'Oferecer :cycle',
    pricing_price_label: 'Preço :cycle',
    pricing_default_label: 'Padrão :cycle',
    pricing_monthly_equivalent: '≈ :price/mês',
    pricing_savings: ':percent% de economia',
    pricing_apply_discount: 'Calcular',
    btn_create_plan: 'Criar plano',
};

let wrapper;
afterEach(() => {
    wrapper?.unmount();
    submitted.length = 0;
});

async function openNew() {
    wrapper = mount(PlanFormModal, {
        props: { open: false, planId: null, features: [], billingCycles, t },
        global: {
            stubs: { OffcanvasPanel: { template: '<div><slot name="tabs" /><slot /><slot name="footer" /></div>' } },
        },
    });
    await wrapper.setProps({ open: true });
    await flushPromises();
}

const row = (cycle) => wrapper.get(`[data-cycle="${cycle}"]`);

async function setPrice(cycle, text) {
    const input = row(cycle).get('input[inputmode="decimal"]');
    await input.trigger('focus');
    await input.setValue(text);
    await input.trigger('blur');
}

describe('PlanFormModal — preços por ciclo', () => {
    it('novo plano começa só com o mensal, que é o padrão', async () => {
        await openNew();

        expect(row('monthly').get('input[type="checkbox"]').element.checked).toBe(true);
        expect(row('yearly').get('input[type="checkbox"]').element.checked).toBe(false);
        expect(row('monthly').get('input[type="radio"]').element.checked).toBe(true);
        expect(row('yearly').get('input[type="radio"]').element.disabled).toBe(true);
    });

    it('calcula os ciclos marcados pelo desconto sobre o mensal e mostra a economia', async () => {
        await openNew();
        await setPrice('monthly', '300,00');
        await row('yearly').get('input[type="checkbox"]').setValue(true);
        await wrapper.get('#plan-discount').setValue(20);

        const apply = wrapper.findAll('button').find((b) => b.text().includes('Calcular'));
        await apply.trigger('click');

        expect(row('yearly').get('input[inputmode="decimal"]').element.value).toBe('2.880,00');
        expect(row('yearly').text().replace(/\s/g, ' ')).toContain('≈ R$ 240,00/mês · 20% de economia');
    });

    it('envia só os ciclos oferecidos e troca o padrão quando ele deixa de ser oferecido', async () => {
        await openNew();
        await setPrice('monthly', '300,00');
        await row('yearly').get('input[type="checkbox"]').setValue(true);
        await setPrice('yearly', '3.000,00');
        await row('yearly').get('input[type="radio"]').setValue(true);

        // Desmarca o anual (que era o padrão): o padrão volta para o mensal.
        await row('yearly').get('input[type="checkbox"]').setValue(false);
        await flushPromises();
        expect(row('monthly').get('input[type="radio"]').element.checked).toBe(true);

        await row('yearly').get('input[type="checkbox"]').setValue(true);
        await wrapper
            .findAll('button')
            .find((b) => b.text() === 'Criar plano')
            .trigger('click');

        expect(submitted).toHaveLength(1);
        expect(submitted[0].method).toBe('post');
        expect(submitted[0].data.billing_cycle).toBe('monthly');
        expect(submitted[0].data.prices).toEqual([
            { billing_cycle: 'monthly', price: 300 },
            { billing_cycle: 'yearly', price: 3000 },
        ]);
    });
});
