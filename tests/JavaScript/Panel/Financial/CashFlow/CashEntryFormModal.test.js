import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
import CashEntryFormModal from '@/Pages/Panel/Financial/CashFlow/CashEntryFormModal.vue';

/**
 * Modal de lançamento do fluxo de caixa (Fase 3): ordem Tipo → Valor →
 * Descrição → Data → Forma → Categoria → Status, valor com MoneyInput
 * (vazio, placeholder localizado), forma de pagamento e convênio, Enter salva,
 * "Salvar e lançar outro", trava de valor/forma para recebimento da agenda com
 * pagamento dividido — mantendo edição pré-preenchida, erros dentro do modal
 * e confirmação ao fechar com alterações.
 */

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'pt_BR' } }),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

vi.mock('@/Components/Panel/CenteredModal.vue', () => ({
    default: {
        props: ['open', 'size'],
        emits: ['close'],
        template: `<div v-if="open" class="modal-stub">
            <div class="modal-stub-header"><slot name="header" /></div>
            <slot />
            <div class="modal-stub-footer"><slot name="footer" /></div>
            <button type="button" class="modal-stub-backdrop" @click="$emit('close')"></button>
        </div>`,
    },
}));

vi.mock('@/Components/Panel/SearchSelect.vue', () => ({
    default: {
        props: ['modelValue', 'options', 'placeholder', 'invalid', 'disabled'],
        emits: ['update:modelValue'],
        template: `<select class="search-select-stub" :value="modelValue ?? ''" @change="$emit('update:modelValue', $event.target.value)">
            <option value="">{{ placeholder }}</option>
            <option v-for="o in options" :key="o.id" :value="o.id">{{ o.name }}</option>
        </select>`,
    },
}));

const t = {
    form_title_new: 'New entry',
    form_title_edit: 'Edit entry',
    form_type: 'Type',
    form_description: 'Description',
    form_amount: 'Amount',
    form_date: 'Date',
    form_category: 'Category',
    form_category_none: 'No category',
    form_status: 'Status',
    form_notes: 'Notes',
    form_save: 'Save',
    form_create: 'Create',
    form_cancel: 'Cancel',
    form_payment_method: 'Payment method',
    form_payment_method_none: 'Not informed',
    form_covenant: 'Insurance',
    form_covenant_none: 'None',
    form_required: 'required',
    form_save_and_new: 'Save and add another',
    form_save_error: 'Could not save the entry.',
    form_schedule_locked: 'Amount and method come from the schedule.',
    form_schedule_link: 'Edit in the schedule',
    network_error: 'Connection failed.',
    session_expired: 'Session expired.',
    form_discard_title: 'Discard changes?',
    form_discard_confirm: 'Discard',
    form_discard_keep: 'Keep editing',
    lock_billing_claim_hint: 'Created by a claim payment.',
    lock_closed_period_hint: 'Period is closed.',
    types: { income: 'Income', expense: 'Expense' },
    statuses: { pending: 'Pending', paid: 'Paid', cancelled: 'Cancelled' },
};

const categories = [
    { id: 'cat-in', name: 'Consultas', type: 'income' },
    { id: 'cat-out', name: 'Aluguel', type: 'expense' },
];

const covenants = [{ id: 'cov-1', name: 'UNIMED' }];

const paymentMethods = [
    { value: 'cash', label: 'À Vista' },
    { value: 'credit', label: 'Crédito' },
    { value: 'credit_cash', label: 'Crédito e Dinheiro' },
];

const row = {
    id: 'e1',
    code: 'FLC-0000000001',
    entry_date: '2026-06-10',
    description: 'Consulta Maria',
    type: 'income',
    status: 'pending',
    amount: 180.5,
    category_id: 'cat-in',
    category_name: 'Consultas',
    covenant_id: 'cov-1',
    covenant_name: 'UNIMED',
    payment_method: 'credit',
    payment_method_label: 'Crédito',
    origin: 'manual',
    has_split: false,
    schedule_date: null,
    notes: 'Obs',
    lock_reason: null,
};

const scheduleSplitRow = {
    ...row,
    id: 'e2',
    origin: 'schedule',
    has_split: true,
    payment_method: 'credit_cash',
    amount: 300,
    schedule_date: '2026-06-09',
};

let wrapper;

function mountModal(props = {}) {
    wrapper = mount(CashEntryFormModal, {
        props: {
            open: true,
            entry: null,
            categories,
            covenants,
            paymentMethods,
            today: '2031-01-15',
            canEditSchedule: true,
            t,
            ...props,
        },
        attachTo: document.body,
    });

    return wrapper;
}

function jsonResponse(status, body) {
    return Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) });
}

const amountInput = (w) => w.find('[data-test="amount-input"]');
const descriptionInput = (w) => w.find('[data-test="description-input"]');
const paymentSelect = (w) => w.find('[data-test="payment-method-input"]');
const statusSelect = (w) => w.find('[data-test="status-input"]');
const [categorySelect, covenantSelect] = [
    (w) => w.findAll('select.search-select-stub')[0],
    (w) => w.findAll('select.search-select-stub')[1],
];
const sentBody = (call = 0) => JSON.parse(fetch.mock.calls[call][1].body);

beforeEach(() => {
    globalThis.fetch = vi.fn(() => jsonResponse(200, { message: 'Saved.', data: { id: 'e1' } }));
});
afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
});

describe('CashFlow/CashEntryFormModal — campos', () => {
    it('ordem dos campos: Tipo → Valor → Descrição → Data → Forma → Categoria → Status', () => {
        const w = mountModal();
        const labels = w.findAll('legend, label.form-label').map((el) => el.text().replace('*', '').trim());

        expect(labels.slice(0, 7)).toEqual([
            'Type (required)',
            'Amount',
            'Description',
            'Date',
            'Payment method',
            'Category',
            'Status',
        ]);
    });

    it('novo: valor vazio com placeholder localizado (sem "0" para apagar), "hoje" do servidor e foco no tipo', async () => {
        const w = mountModal({ open: false });
        await w.setProps({ open: true });
        await nextTick();

        expect(amountInput(w).element.value).toBe('');
        expect(amountInput(w).attributes('placeholder')).toBe('0,00');
        expect(w.find('.input-group-text').text()).toBe('R$');
        expect(w.find('input[type="date"]').element.value).toBe('2031-01-15');
        expect(document.activeElement).toBe(w.find('input[type="radio"][value="income"]').element);
    });

    it('editar pré-preenche tudo (inclusive forma e convênio) e envia PATCH com esses dados', async () => {
        const w = mountModal({ open: false });
        await w.setProps({ open: true, entry: row });
        await nextTick();

        expect(w.text()).toContain('Edit entry');
        expect(descriptionInput(w).element.value).toBe('Consulta Maria');
        expect(amountInput(w).element.value).toBe('180,50');
        expect(w.find('input[type="date"]').element.value).toBe('2026-06-10');
        expect(paymentSelect(w).element.value).toBe('credit');
        expect(statusSelect(w).element.value).toBe('pending');
        expect(categorySelect(w).element.value).toBe('cat-in');
        expect(covenantSelect(w).element.value).toBe('cov-1');
        expect(w.find('textarea').element.value).toBe('Obs');
        expect(w.find('[data-test="submit-another"]').exists()).toBe(false);

        // Dar baixa: só o status muda — o resto segue o que já estava gravado.
        await statusSelect(w).setValue('paid');
        await w.find('form').trigger('submit');
        await flushPromises();

        const [url] = fetch.mock.calls[0];
        expect(url).toBe('/_routes/panel.financial.cash-flow.update/e1');
        expect(sentBody()).toEqual({
            type: 'income',
            amount: 180.5,
            description: 'Consulta Maria',
            entry_date: '2026-06-10',
            payment_method: 'credit',
            category_id: 'cat-in',
            status: 'paid',
            covenant_id: 'cov-1',
            notes: 'Obs',
            _method: 'PATCH',
        });
        expect(w.emitted('saved')[0][0]).toMatchObject({ message: 'Saved.', entryDate: '2026-06-10', keepOpen: false });
    });

    it('valor digitado no formato do idioma vai como número; forma e convênio escolhidos vão no POST', async () => {
        const w = mountModal();

        await amountInput(w).setValue('1.234,5');
        await descriptionInput(w).setValue('Venda de colírio');
        await paymentSelect(w).setValue('cash');
        await covenantSelect(w).setValue('cov-1');
        await w.find('form').trigger('submit');
        await flushPromises();

        expect(fetch.mock.calls[0][0]).toBe('/_routes/panel.financial.cash-flow.store');
        expect(sentBody()).toMatchObject({
            entry_date: '2031-01-15',
            amount: 1234.5,
            description: 'Venda de colírio',
            payment_method: 'cash',
            covenant_id: 'cov-1',
        });
    });

    it('convênio/categoria inativados depois do lançamento continuam aparecendo na edição', () => {
        const w = mountModal({
            entry: {
                ...row,
                covenant_id: 'cov-old',
                covenant_name: 'CONVÊNIO ANTIGO',
                category_id: 'cat-old',
                category_name: 'Antiga',
            },
        });

        expect(
            covenantSelect(w)
                .findAll('option')
                .map((o) => o.text()),
        ).toContain('CONVÊNIO ANTIGO');
        expect(covenantSelect(w).element.value).toBe('cov-old');
        expect(
            categorySelect(w)
                .findAll('option')
                .map((o) => o.text()),
        ).toContain('Antiga');
    });

    it('trocar o tipo limpa a categoria do outro tipo e filtra as opções', async () => {
        const w = mountModal({ entry: row });

        await w.find('input[type="radio"][value="expense"]').trigger('change');

        expect(categorySelect(w).element.value).toBe('');
        const options = categorySelect(w)
            .findAll('option')
            .map((o) => o.text());
        expect(options).toContain('Aluguel');
        expect(options).not.toContain('Consultas');
    });

    it('rótulos vêm de `t` e todo campo tem rótulo associado', () => {
        const w = mountModal();

        for (const selector of [
            'input[type="date"]',
            '[data-test="amount-input"]',
            '[data-test="description-input"]',
            '[data-test="payment-method-input"]',
            '[data-test="status-input"]',
            'textarea',
        ]) {
            const id = w.find(selector).attributes('id');
            expect(id, selector).toBeTruthy();
            expect(w.find(`label[for="${id}"]`).exists(), selector).toBe(true);
        }
        expect(w.find(`label[for="${amountInput(w).attributes('id')}"]`).text()).toContain('Amount');
        expect(w.text()).toContain('Income');
        expect(w.text()).toContain('Expense');
    });
});

describe('CashFlow/CashEntryFormModal — teclado e "lançar outro"', () => {
    it('Enter num campo salva; Enter nas observações não', async () => {
        const w = mountModal();
        await descriptionInput(w).setValue('Taxa');
        await amountInput(w).setValue('10');

        await w.find('textarea').trigger('keydown', { key: 'Enter' });
        await flushPromises();
        expect(fetch).not.toHaveBeenCalled();

        await descriptionInput(w).trigger('keydown', { key: 'Enter' });
        await flushPromises();
        expect(fetch).toHaveBeenCalledTimes(1);
        expect(sentBody()).toMatchObject({ description: 'Taxa', amount: 10 });
    });

    it('"Salvar e lançar outro" mantém tipo/data/forma, limpa valor/descrição, foca o valor e não fecha', async () => {
        const w = mountModal();

        await w.find('input[type="radio"][value="expense"]').trigger('change');
        await amountInput(w).setValue('50');
        await descriptionInput(w).setValue('Material de limpeza');
        await w.find('input[type="date"]').setValue('2031-01-10');
        await paymentSelect(w).setValue('cash');
        await statusSelect(w).setValue('pending');

        await w.find('[data-test="submit-another"]').trigger('click');
        await flushPromises();

        expect(sentBody()).toMatchObject({
            type: 'expense',
            amount: 50,
            description: 'Material de limpeza',
            entry_date: '2031-01-10',
            payment_method: 'cash',
        });
        expect(w.emitted('saved')[0][0]).toMatchObject({ keepOpen: true, entryDate: '2031-01-10' });
        expect(w.emitted('close')).toBeUndefined();

        expect(w.find('input[type="radio"][value="expense"]').element.checked).toBe(true);
        expect(w.find('input[type="date"]').element.value).toBe('2031-01-10');
        expect(paymentSelect(w).element.value).toBe('cash');
        expect(amountInput(w).element.value).toBe('');
        expect(descriptionInput(w).element.value).toBe('');
        expect(statusSelect(w).element.value).toBe('paid');
        expect(document.activeElement).toBe(amountInput(w).element);

        // Formulário "novo" de novo: fechar não pede confirmação.
        await w.find('.modal-stub-backdrop').trigger('click');
        expect(w.emitted('close')).toHaveLength(1);
    });

    it('Esc fecha direto quando não há alterações', async () => {
        const w = mountModal();

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await nextTick();

        expect(w.emitted('close')).toHaveLength(1);
    });

    it('fechar com alterações pede confirmação; "Continuar editando" mantém; "Descartar" fecha', async () => {
        const w = mountModal();
        await descriptionInput(w).setValue('Digitado');

        await w.find('.modal-stub-backdrop').trigger('click');
        expect(w.emitted('close')).toBeUndefined();
        expect(w.find('[data-test="discard-prompt"]').text()).toContain('Discard changes?');

        await w.find('[data-test="keep-editing"]').trigger('click');
        expect(w.find('[data-test="discard-prompt"]').exists()).toBe(false);
        expect(descriptionInput(w).element.value).toBe('Digitado');

        await w.find('.modal-stub-backdrop').trigger('click');
        await w.find('[data-test="discard"]').trigger('click');
        expect(w.emitted('close')).toHaveLength(1);
    });
});

describe('CashFlow/CashEntryFormModal — recebimento da agenda com pagamento dividido', () => {
    it('valor e forma ficam só leitura, com aviso e link "editar pela agenda" no dia do agendamento', () => {
        const w = mountModal({ entry: scheduleSplitRow });

        expect(amountInput(w).attributes('readonly')).toBeDefined();
        expect(paymentSelect(w).attributes('disabled')).toBeDefined();

        const lock = w.find('[data-test="schedule-lock"]');
        expect(lock.text()).toContain('Amount and method come from the schedule.');
        expect(amountInput(w).attributes('aria-describedby')).toContain(lock.attributes('id'));
        expect(w.find('[data-test="schedule-link"]').text()).toBe('Edit in the schedule');
        expect(w.find('[data-test="schedule-link"]').attributes('href')).toBe(
            '/_routes/panel.schedules.index?date=2026-06-09',
        );
    });

    it('sem acesso à agenda: mantém a trava, sem o link', () => {
        const w = mountModal({ entry: scheduleSplitRow, canEditSchedule: false });

        expect(w.find('[data-test="schedule-lock"]').exists()).toBe(true);
        expect(w.find('[data-test="schedule-link"]').exists()).toBe(false);
    });

    it('salvar não reenvia a forma de pagamento (os demais campos seguem editáveis)', async () => {
        const w = mountModal({ entry: scheduleSplitRow });

        await statusSelect(w).setValue('paid');
        await w.find('form').trigger('submit');
        await flushPromises();

        const body = sentBody();
        expect(body).not.toHaveProperty('payment_method');
        expect(body).toMatchObject({ amount: 300, status: 'paid', _method: 'PATCH' });
    });

    it('lançamento da agenda sem divisão continua editável', () => {
        const w = mountModal({ entry: { ...scheduleSplitRow, has_split: false } });

        expect(amountInput(w).attributes('readonly')).toBeUndefined();
        expect(paymentSelect(w).attributes('disabled')).toBeUndefined();
        expect(w.find('[data-test="schedule-lock"]').exists()).toBe(false);
    });
});

describe('CashFlow/CashEntryFormModal — datas e erros', () => {
    it('aba aberta de um dia para o outro: novo lançamento usa o dia de HOJE, não o "today" velho do servidor', async () => {
        vi.useFakeTimers({ toFake: ['Date'] });
        try {
            vi.setSystemTime(new Date(2031, 0, 15, 20, 0));
            const w = mountModal({ open: false, today: '2031-01-15' });

            vi.setSystemTime(new Date(2031, 0, 16, 8, 30));
            await w.setProps({ open: true });
            await nextTick();

            expect(w.find('input[type="date"]').element.value).toBe('2031-01-16');
        } finally {
            vi.useRealTimers();
        }
    });

    it('sem "today" do servidor usa a data LOCAL (não o toISOString em UTC)', () => {
        const now = new Date();
        const expected = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
        const w = mountModal({ today: '' });

        expect(w.find('input[type="date"]').element.value).toBe(expected);
    });

    it('422 sem `errors` (período fechado) mostra a mensagem dentro do modal e não fecha', async () => {
        fetch.mockImplementationOnce(() => jsonResponse(422, { message: 'O caixa deste período está fechado.' }));
        const w = mountModal({ entry: row });

        await w.find('form').trigger('submit');
        await flushPromises();

        expect(w.find('[data-test="form-error"]').text()).toContain('O caixa deste período está fechado.');
        expect(w.emitted('saved')).toBeUndefined();
        expect(w.find('[data-test="submit"]').attributes('disabled')).toBeUndefined();
    });

    it('422 com errors: erro no próprio campo (valor e data) e chave fora do formulário no alerta', async () => {
        fetch.mockImplementationOnce(() =>
            jsonResponse(422, {
                message: 'Dados de validação inválidos',
                errors: {
                    amount: ['Valor e forma só pela agenda.'],
                    entry_date: ['Caixa fechado.'],
                    billing_claim_id: ['Lançamento de guia não pode ser alterado.'],
                },
            }),
        );
        const w = mountModal({ entry: row });

        await w.find('form').trigger('submit');
        await flushPromises();

        expect(w.find('input[type="date"]').classes()).toContain('is-invalid');
        expect(w.find('[data-test="entry-date-error"]').text()).toBe('Caixa fechado.');
        expect(w.find('[data-test="amount-error"]').text()).toBe('Valor e forma só pela agenda.');
        expect(amountInput(w).attributes('aria-invalid')).toBe('true');
        expect(amountInput(w).attributes('aria-describedby')).toBe(
            w.find('[data-test="amount-error"]').attributes('id'),
        );
        expect(w.find('[data-test="form-error"]').text()).toContain('Lançamento de guia não pode ser alterado.');
    });

    it('500 mostra a mensagem genérica; 419 pede para recarregar; falha de rede avisa e reabilita', async () => {
        fetch
            .mockImplementationOnce(() => jsonResponse(500, {}))
            .mockImplementationOnce(() => jsonResponse(419, { message: 'CSRF token mismatch.' }))
            .mockImplementationOnce(() => Promise.reject(new TypeError('Failed to fetch')));
        const w = mountModal({ entry: row });

        await w.find('form').trigger('submit');
        await flushPromises();
        expect(w.find('[data-test="form-error"]').text()).toContain('Could not save the entry.');

        await w.find('form').trigger('submit');
        await flushPromises();
        expect(w.find('[data-test="form-error"]').text()).toContain('Session expired.');

        await w.find('form').trigger('submit');
        await flushPromises();
        expect(w.find('[data-test="form-error"]').text()).toContain('Connection failed.');
        expect(w.find('[data-test="submit"]').attributes('disabled')).toBeUndefined();
    });

    it('linha travada: mostra o motivo, desabilita os campos e não envia', async () => {
        const w = mountModal({ entry: { ...row, lock_reason: 'billing_claim' } });

        expect(w.find('[data-test="lock-alert"]').text()).toContain('Created by a claim payment.');
        expect(w.find('[data-test="submit"]').attributes('disabled')).toBeDefined();
        expect(amountInput(w).attributes('disabled')).toBeDefined();

        await w.find('form').trigger('submit');
        await flushPromises();
        expect(fetch).not.toHaveBeenCalled();
    });
});
