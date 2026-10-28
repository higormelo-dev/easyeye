import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
import AllocateReceiptModal from '@/Pages/Panel/Financial/DoctorPayouts/AllocateReceiptModal.vue';
import { t, itemRows, brl } from './fixtures.js';

/**
 * Recebimento manual: escolher a receita avulsa (saldo), digitar o valor por
 * item ou "Sugerir" (proporcional ao a receber, em centavos, resto nos
 * primeiros), total acima do saldo bloqueia; envia só itens com valor.
 */

// useForm reativo com transform encadeável (o do setup.js não re-renderiza).
const forms = vi.hoisted(() => []);
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');

    return {
        usePage: () => ({ props: { locale: 'pt_BR' } }),
        useForm: (data) => {
            const initial = JSON.parse(JSON.stringify(data));
            const form = reactive({
                ...data,
                errors: {},
                processing: false,
                transformer: null,
                reset: () => Object.assign(form, JSON.parse(JSON.stringify(initial))),
                clearErrors: () => {
                    form.errors = {};
                },
                transform: (fn) => {
                    form.transformer = fn;
                    return form;
                },
                post: vi.fn(),
            });
            forms.push(form);

            return form;
        },
    };
});

vi.mock('@/Components/Panel/CenteredModal.vue', () => ({
    default: {
        props: ['open', 'size'],
        emits: ['close'],
        template: `<div v-if="open" class="modal-stub"><header><slot name="header" /></header><slot /><footer><slot name="footer" /></footer></div>`,
    },
}));

vi.mock('@/Components/Panel/MoneyInput.vue', () => ({
    default: {
        props: ['modelValue', 'invalid', 'disabled'],
        emits: ['update:modelValue'],
        template: `<input class="money-stub" :value="modelValue ?? ''" :disabled="disabled"
            @input="$emit('update:modelValue', $event.target.value === '' ? null : Number($event.target.value))">`,
    },
}));

const receipts = [
    { id: 'ce1', date: '2026-09-05', description: 'Depósito convênio', amount: 300, allocated: 0, remaining: 300 },
    { id: 'ce2', date: '2026-09-06', description: 'PIX avulso', amount: 50, allocated: 40, remaining: 10 },
];

const rows = [
    { ...itemRows[0], key: 'schedule:s1', receipt: { status: 'awaiting', open: 200 } },
    {
        ...itemRows[1],
        key: 'patient_exam:p|e|2026-09-03',
        receipt: { status: 'not_linked', open: null },
        forecast: 100,
    },
];

let wrapper;

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    forms.length = 0;
});

async function mountModal(props = {}) {
    wrapper = mount(AllocateReceiptModal, {
        props: { open: false, rows, receipts, action: '/doctor-payouts/allocations', t, ...props },
    });

    await wrapper.setProps({ open: true });
    await flushPromises();

    return wrapper;
}

const form = () => forms[0];

describe('Financial/DoctorPayouts/AllocateReceiptModal', () => {
    it('lista as receitas com saldo e um campo de valor por item (um por ato)', async () => {
        const w = await mountModal({ rows: [...rows, { ...rows[0], row_id: 'dup' }] });

        const options = w.findAll('[data-test="allocate-receipt"] option').map((option) => option.text());
        expect(options[0]).toBe('Select the income');
        expect(options[1]).toBe(`05/09/2026 · Depósito convênio · balance ${brl(300)}`);
        expect(w.findAll('[data-test="allocate-item"]')).toHaveLength(2);
        expect(w.find('[data-test="allocate-item"] label').text()).toContain('Maria Souza');
    });

    it('sem receitas carregadas pede o carregamento ao abrir', async () => {
        const w = await mountModal({ receipts: null });

        expect(w.emitted('load')).toHaveLength(1);
    });

    it('"Sugerir" divide o saldo proporcional ao a receber (centavos, resto no primeiro)', async () => {
        const w = await mountModal();

        await w.find('[data-test="allocate-receipt"]').setValue('ce1');
        await w.find('[data-test="allocate-suggest"]').trigger('click');
        await nextTick();

        // pesos: 200 (a receber) e 100 (previsão) → 300 do saldo = 200 + 100
        expect(form().items.map((item) => item.amount)).toEqual([200, 100]);
        expect(w.find('[data-test="allocate-total"]').text()).toContain(
            `Total allocated: ${brl(300)} of ${brl(300)} available`,
        );
    });

    it('total acima do saldo bloqueia a confirmação', async () => {
        const w = await mountModal();

        await w.find('[data-test="allocate-receipt"]').setValue('ce2'); // saldo 10
        await w.findAll('.money-stub')[0].setValue('15');

        expect(w.find('[data-test="allocate-total"]').text()).toContain('The total exceeds the income balance.');
        expect(w.find('[data-test="allocate-confirm"]').attributes('disabled')).toBeDefined();
    });

    it('confirma enviando a receita e só os itens com valor', async () => {
        const w = await mountModal();

        await w.find('[data-test="allocate-receipt"]').setValue('ce1');
        await w.findAll('.money-stub')[0].setValue('120.5');
        await w.find('[data-test="allocate-confirm"]').trigger('click');

        expect(form().post).toHaveBeenCalledWith(
            '/doctor-payouts/allocations',
            expect.objectContaining({ preserveScroll: true }),
        );

        const payload = form().transformer({
            cash_entry_id: form().cash_entry_id,
            items: form().items,
            notes: form().notes,
        });
        expect(payload.cash_entry_id).toBe('ce1');
        expect(payload.items).toEqual([{ key: 'schedule:s1', amount: 120.5 }]);
    });
});
