import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import DeductionRatesCard from '@/Pages/Panel/Financial/DoctorPayouts/DeductionRatesCard.vue';
import { t } from './fixtures.js';

/**
 * Deduções antes de dividir (E4): vigências por tipo com a que vale hoje,
 * cadastro de nova vigência (POST) e exclusão com confirmação (DELETE).
 */

const forms = vi.hoisted(() => []);
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');

    return {
        usePage: () => ({ props: { locale: 'pt_BR' } }),
        router: { delete: vi.fn() },
        useForm: (data) => {
            const initial = { ...data };
            const form = reactive({
                ...data,
                errors: {},
                processing: false,
                reset: (...fields) => {
                    (fields.length ? fields : Object.keys(initial)).forEach((field) => { form[field] = initial[field]; });
                },
                post: vi.fn(),
            });
            forms.push(form);

            return form;
        },
    };
});

const routes = {
    deduction_rate_store: '/doctor-payouts/deduction-rates',
    deduction_rate_destroy: '/doctor-payouts/deduction-rates/__ID__',
};

const RATES = [
    { id: 'tax-old', kind: 'tax', percentage: 6, valid_from: '2026-01-01', notes: null },
    { id: 'tax-next', kind: 'tax', percentage: 6.5, valid_from: '2026-10-01', notes: 'Reforma' },
    { id: 'credit', kind: 'card_credit', percentage: 3.49, valid_from: '2025-06-01', notes: null },
];

let wrapper;

function mountCard(props = {}) {
    wrapper = mount(DeductionRatesCard, { props: { rates: RATES, routes, today: '2026-09-29', t, ...props } });

    return wrapper;
}

const form = () => forms.at(-1);
/** Textos das partes da linha (o espaço entre elas vem do gap do flex, não do texto). */
const parts = (rate) => [...rate.element.children].filter((el) => el.tagName === 'SPAN').map((el) => el.textContent.trim());

beforeEach(() => {
    vi.mocked(router.delete).mockReset();
});
afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    forms.length = 0;
    vi.unstubAllGlobals();
});

describe('Financial/DoctorPayouts/DeductionRatesCard', () => {
    it('sem taxas: aviso de que o repasse é calculado sobre o recebido', () => {
        const w = mountCard({ rates: [] });

        expect(w.find('h2').text()).toBe('Deductions before splitting');
        expect(w.find('[data-test="deductions-empty"]').text()).toBe('No rates registered.');
        expect(w.find('[data-test="deduction-group"]').exists()).toBe(false);
    });

    it('agrupa por tipo (ordem fixa), mais recente primeiro, e marca a que vale hoje', () => {
        const w = mountCard();

        const groups = w.findAll('[data-test="deduction-group"]');
        expect(groups.map((group) => group.attributes('data-kind'))).toEqual(['card_credit', 'tax']);

        const tax = groups[1].findAll('[data-test="deduction-rate"]');
        expect(tax.map(parts)).toEqual([
            ['6,5%', 'Valid from 01/10/2026', '· Reforma'],
            ['6%', 'Valid from 01/01/2026', 'Active'],
        ]);
        // Vigência futura não vale hoje; a de janeiro vale.
        expect(tax[0].find('[data-test="deduction-current"]').exists()).toBe(false);
        expect(tax[1].find('[data-test="deduction-current"]').exists()).toBe(true);
        expect(parts(groups[0].find('[data-test="deduction-rate"]'))).toEqual(['3,49%', 'Valid from 01/06/2025', 'Active']);
    });

    it('nova vigência: POST com tipo, taxa e data; sucesso limpa taxa, data e notas (mantém o tipo)', async () => {
        const w = mountCard();

        expect(w.findAll('[data-test="deduction-kind"] option').map((o) => [o.attributes('value'), o.text()])).toEqual([
            ['card_debit', 'Debit card'],
            ['card_credit', 'Credit card'],
            ['tax', 'Tax'],
            ['admin', 'Administrative fee'],
        ]);

        await w.find('[data-test="deduction-kind"]').setValue('admin');
        await w.find('[data-test="deduction-percentage"]').setValue('2.5');
        await w.find('[data-test="deduction-from"]').setValue('2026-10-01');
        await w.find('[data-test="deduction-form"]').trigger('submit');

        expect(form().post).toHaveBeenCalledWith('/doctor-payouts/deduction-rates', expect.objectContaining({ preserveScroll: true }));
        expect({ kind: form().kind, percentage: form().percentage, valid_from: form().valid_from }).toEqual({ kind: 'admin', percentage: 2.5, valid_from: '2026-10-01' });

        form().post.mock.calls[0][1].onSuccess();
        expect({ kind: form().kind, percentage: form().percentage, valid_from: form().valid_from }).toEqual({ kind: 'admin', percentage: '', valid_from: '' });
    });

    it('enviando: não reenvia e trava os campos', async () => {
        const w = mountCard();

        form().processing = true;
        await nextTick();

        expect(w.find('[data-test="deduction-add"]').attributes('disabled')).toBeDefined();
        expect(w.find('[data-test="deduction-percentage"]').attributes('disabled')).toBeDefined();

        await w.find('[data-test="deduction-form"]').trigger('submit');
        expect(form().post).not.toHaveBeenCalled();
    });

    it('erro do servidor (vigência duplicada) aparece ligado aos campos', async () => {
        const w = mountCard();

        form().errors = { valid_from: 'There is already a rate of this type from this date.' };
        await nextTick();

        const error = w.find('[data-test="deduction-errors"]');
        expect(error.text()).toBe('There is already a rate of this type from this date.');
        expect(error.attributes('role')).toBe('alert');
        expect(w.find('[data-test="deduction-from"]').attributes('aria-invalid')).toBe('true');
        expect(w.find('[data-test="deduction-from"]').attributes('aria-describedby')).toBe(error.attributes('id'));
    });

    it('excluir confirma com o aviso e apaga pela rota da vigência; cancelar não apaga', async () => {
        const confirm = vi.fn(() => true);
        vi.stubGlobal('confirm', confirm);
        const w = mountCard();

        const deleteTax = w.findAll('[data-test="deduction-group"]')[1].findAll('[data-test="deduction-delete"]')[1];
        expect(deleteTax.attributes('aria-label')).toBe('Delete rate: Tax 6% 01/01/2026');

        await deleteTax.trigger('click');
        expect(confirm).toHaveBeenCalledWith('Delete this rate?\n\nThe previous rate applies again.');
        expect(router.delete).toHaveBeenCalledWith('/doctor-payouts/deduction-rates/tax-old', { preserveScroll: true });

        confirm.mockReturnValue(false);
        await deleteTax.trigger('click');
        expect(router.delete).toHaveBeenCalledTimes(1);
    });
});
