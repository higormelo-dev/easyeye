import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import ReceiptModal from '@/Pages/Panel/Financial/Billing/ReceiptModal.vue';
import SubmitBatchModal from '@/Pages/Panel/Financial/Billing/SubmitBatchModal.vue';
import DenyClaimModal from '@/Pages/Panel/Financial/Billing/DenyClaimModal.vue';
import BatchFormModal from '@/Pages/Panel/Financial/Billing/BatchFormModal.vue';
import IndividualClaimModal from '@/Pages/Panel/Financial/Billing/IndividualClaimModal.vue';
import { forms, resetInertiaMock } from './support/inertiaMock.js';
import { t, claim, batch, schedule, brl, norm } from './support/fixtures.js';

vi.mock('@inertiajs/vue3', async () => (await import('./support/inertiaMock.js')).buildInertiaMock());
vi.mock('@/Components/Panel/SearchSelect.vue', () => ({
    default: {
        props: ['modelValue', 'options', 'placeholder', 'invalid'],
        template: '<div class="search-select-stub" />',
    },
}));
// Cid10Picker real busca na API; o stub devolve uma seleção ao clicar.
vi.mock('@/Components/Panel/Cid10Picker.vue', () => ({
    default: {
        props: ['modelValue', 'searchUrl', 'multiple', 'placeholder'],
        emits: ['update:modelValue'],
        template: `<button type="button" class="cid-stub" :data-url="searchUrl"
            @click="$emit('update:modelValue', [{ code: 'H40.1', description: 'Glaucoma' }])">{{ (modelValue || []).map((i) => i.code).join(',') }}</button>`,
    },
}));

const global = {
    stubs: {
        teleport: true,
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    },
};
const paymentMethods = [
    { value: 'transfer', label: 'Transferência bancária' },
    { value: 'cash', label: 'À vista (dinheiro)' },
];

let wrapper;

beforeEach(() => resetInertiaMock());
afterEach(() => wrapper?.unmount());

function lastForm() {
    return forms[forms.length - 1];
}

describe('ReceiptModal (registrar recebimento)', () => {
    function mountReceipt(claimOverrides = {}) {
        wrapper = mount(ReceiptModal, {
            props: { open: true, claim: claim(claimOverrides), paymentMethods, today: '2026-09-26', t },
            global,
        });

        return wrapper;
    }

    it('pré-preenche valor = guia − glosa, data de hoje e a primeira forma de pagamento', () => {
        const w = mountReceipt({ amount: 200, glosa_amount: 50, receivable_amount: 150 });
        const form = lastForm();

        expect(form.paid_amount).toBe(150);
        expect(form.paid_at).toBe('2026-09-26');
        expect(form.payment_method).toBe('transfer');
        expect(w.find('[data-test="receipt-expected"]').text()).toBe(brl(150));
        expect(w.find('[data-test="receipt-summary"]').text()).toContain('GUI-0001');
        expect(w.find('#billing-receipt-date').attributes('max')).toBe('2026-09-26');
    });

    it('avisa recebimento parcial quando o valor digitado é menor que o esperado', async () => {
        const w = mountReceipt({ receivable_amount: 150 });

        lastForm().paid_amount = 100;
        await nextTick();

        expect(w.find('[data-test="receipt-partial"]').text()).toContain(brl(50));
    });

    it('avisa que a parte acima do esperado reverte a glosa (ex.: recurso aceito)', async () => {
        const w = mountReceipt({ amount: 200, glosa_amount: 50, receivable_amount: 150 });

        expect(w.find('[data-test="receipt-glosa-reversed"]').exists()).toBe(false);

        lastForm().paid_amount = 180;
        await nextTick();

        expect(w.find('[data-test="receipt-glosa-reversed"]').text()).toContain(brl(30));
        expect(w.find('[data-test="receipt-partial"]').exists()).toBe(false);
    });

    it('não fala em reversão de glosa numa guia sem glosa', async () => {
        const w = mountReceipt({ amount: 200, glosa_amount: 0, receivable_amount: 200 });

        lastForm().paid_amount = 200;
        await nextTick();

        expect(w.find('[data-test="receipt-glosa-reversed"]').exists()).toBe(false);
    });

    it('posta na URL da guia preservando o estado e avisa o pai no sucesso', async () => {
        const w = mountReceipt();

        await w.find('[data-test="confirm-receipt"]').trigger('click');

        const form = lastForm();
        expect(form.post).toHaveBeenCalledTimes(1);
        expect(form.lastPost.url).toBe('/claims/c1/paid');
        expect(form.lastPost.options).toMatchObject({ preserveState: true, preserveScroll: true });

        form.lastPost.options.onSuccess();
        expect(w.emitted('saved')).toHaveLength(1);
    });

    it('mostra o erro de transição do servidor (status) e trava o botão durante o envio', async () => {
        const w = mountReceipt();
        const form = lastForm();

        form.setError('status', 'A guia GUI-0001 já está paga.');
        form.processing = true;
        await nextTick();

        expect(w.find('[data-test="receipt-error"]').text()).toBe('A guia GUI-0001 já está paga.');
        expect(w.find('[data-test="confirm-receipt"]').attributes('disabled')).toBeDefined();
    });

    it('fecha com Esc (e não fecha durante o envio)', async () => {
        const w = mountReceipt();

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(w.emitted('close')).toHaveLength(1);

        lastForm().processing = true;
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(w.emitted('close')).toHaveLength(1);
    });
});

describe('SubmitBatchModal (confirmação de envio)', () => {
    it('mostra código, operadora, guias, total e as pendências que ficam de fora', () => {
        wrapper = mount(SubmitBatchModal, { props: { open: true, batch: batch(), t }, global });

        expect(wrapper.text()).toContain(t.submit_confirm_title_tiss);
        expect(wrapper.find('[data-test="summary-code"]').text()).toBe('LOT-0007');
        expect(wrapper.find('[data-test="summary-operator"]').text()).toBe('Unimed');
        expect(wrapper.find('[data-test="summary-guides"]').text()).toBe('2 guia(s)');
        expect(wrapper.find('[data-test="summary-total"]').text()).toBe(brl(450));
        expect(wrapper.find('[data-test="summary-pending"]').text()).toContain('1 guia(s) com pendência');
        expect(wrapper.find('[data-test="confirm-submit"]').text()).toBe(t.action_submit_tiss);
    });

    it('lote particular pede "Marcar como cobrado"', () => {
        wrapper = mount(SubmitBatchModal, {
            props: { open: true, batch: batch({ is_particular: true, pending_count: 0 }), t },
            global,
        });

        expect(wrapper.text()).toContain(t.submit_confirm_title_particular);
        expect(wrapper.find('[data-test="confirm-submit"]').text()).toBe(t.action_mark_charged);
        expect(wrapper.find('[data-test="summary-pending"]').exists()).toBe(false);
    });

    it('mostra o erro devolvido pelo servidor e trava os botões enquanto envia', () => {
        wrapper = mount(SubmitBatchModal, {
            props: { open: true, batch: batch(), processing: true, error: 'Falha no envio TISS: timeout', t },
            global,
        });

        expect(wrapper.find('[data-test="submit-error"]').text()).toBe('Falha no envio TISS: timeout');
        expect(wrapper.find('[data-test="confirm-submit"]').attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-test="confirm-submit"]').text()).toBe(t.processing);
    });

    it('emite confirm só no clique do botão de confirmação', async () => {
        wrapper = mount(SubmitBatchModal, { props: { open: true, batch: batch(), t }, global });

        await wrapper.find('[data-test="confirm-submit"]').trigger('click');

        expect(wrapper.emitted('confirm')).toHaveLength(1);
    });
});

describe('DenyClaimModal (glosa total/parcial)', () => {
    function mountDeny(claimOverrides = {}) {
        wrapper = mount(DenyClaimModal, {
            props: {
                open: true,
                claim: claim({ amount: 200, ...claimOverrides }),
                glosasUrl: '/financial/tiss/glosas',
                t,
            },
            global,
            attachTo: document.body,
        });

        return wrapper;
    }

    it('mostra a guia (código, paciente só pelo nome, valor) e glosa TOTAL por padrão', async () => {
        const w = mountDeny();

        const summary = w.find('[data-test="deny-summary"]').text();
        expect(summary).toContain('GUI-0001');
        expect(summary).toContain('Maria Souza');
        expect(norm(summary)).toContain(norm(brl(200)));
        expect(w.find('[data-test="deny-type-total"]').element.checked).toBe(true);
        expect(w.find('#billing-deny-amount').exists()).toBe(false);
        expect(w.find('[data-test="deny-effect"]').text()).toBe(t.deny_effect_hint);

        await w.find('[data-test="confirm-deny"]').trigger('click');

        const { url, data } = lastForm().lastPost;
        expect(url).toBe('/claims/c1/denied');
        expect(data.glosa_amount).toBe(200);
    });

    it('parcial habilita o valor (MoneyInput) e exige > 0 e até o valor da guia', async () => {
        const w = mountDeny();

        await w.find('[data-test="deny-type-partial"]').setValue(true);
        const input = w.find('#billing-deny-amount');
        expect(input.attributes('aria-required')).toBe('true');
        expect(input.attributes('inputmode')).toBe('decimal');

        await w.find('[data-test="confirm-deny"]').trigger('click');
        expect(lastForm().post).not.toHaveBeenCalled();
        expect(w.find('[data-test="deny-amount-error"]').text()).toBe(t.deny_amount_required);
        expect(input.attributes('aria-invalid')).toBe('true');

        await input.setValue('250,00');
        await w.find('[data-test="confirm-deny"]').trigger('click');
        expect(lastForm().post).not.toHaveBeenCalled();
        expect(norm(w.find('[data-test="deny-amount-error"]').text())).toBe(
            norm(`O valor glosado não pode ser maior que o valor da guia (${brl(200)}).`),
        );

        await input.setValue('50,00');
        expect(norm(w.find('[data-test="deny-remaining"]').text())).toContain(norm(brl(150)));
        expect(w.find('[data-test="deny-effect"]').text()).toContain('antes de refaturar');

        await w.find('[data-test="confirm-deny"]').trigger('click');
        expect(lastForm().lastPost.data.glosa_amount).toBe(50);
    });

    it('depois de glosar mostra o próximo passo e o CTA "Abrir conciliação" (guia TISS)', async () => {
        const w = mountDeny();

        await w.find('[data-test="confirm-deny"]').trigger('click');
        lastForm().lastPost.options.onSuccess();
        await nextTick();

        expect(w.emitted('saved')).toHaveLength(1);
        expect(w.find('[data-test="deny-done"]').attributes('role')).toBe('status');
        expect(w.find('[data-test="deny-done"]').text()).toContain('Próximo passo');
        expect(w.find('[data-test="open-conciliation"]').attributes('href')).toBe(
            '/financial/tiss/glosas?search=GUI-0001',
        );
        expect(w.find('[data-test="confirm-deny"]').exists()).toBe(false);
    });

    it('guia particular: sem CTA de conciliação (não gera glosa TISS)', async () => {
        const w = mountDeny({ is_tiss: false });

        await w.find('[data-test="confirm-deny"]').trigger('click');
        lastForm().lastPost.options.onSuccess();
        await nextTick();

        expect(w.find('[data-test="deny-done"]').text()).toContain('Guia particular');
        expect(w.find('[data-test="open-conciliation"]').exists()).toBe(false);
    });

    it('fecha com Esc, mas não durante o envio', async () => {
        const w = mountDeny();

        lastForm().processing = true;
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(w.emitted('close')).toBeUndefined();

        lastForm().processing = false;
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(w.emitted('close')).toHaveLength(1);
    });
});

describe('BatchFormModal', () => {
    const covenants = [
        { id: 'cov-1', name: 'Unimed', has_ans_registry: true },
        { id: 'cov-2', name: 'Particular', has_ans_registry: false },
    ];
    const eligible = [
        schedule({ id: 's1', covenant_id: 'cov-1' }),
        schedule({ id: 's2', covenant_id: 'cov-1' }),
        schedule({ id: 's3', covenant_id: 'cov-2' }),
    ];

    function mountBatch(selectedIds, schedules = eligible) {
        wrapper = mount(BatchFormModal, {
            props: {
                open: true,
                covenants,
                eligibleSchedules: schedules,
                selectedIds,
                filters: { from: '2026-09-01', to: '2026-09-26', covenant_id: null },
                url: '/billing/batch',
                cid10SearchUrl: '/cid10',
                procedurePricesUrl: '/financial/procedure-prices',
                t,
            },
            global,
        });

        return wrapper;
    }

    it('resume quantos marcados entram e quantos de outro convênio ficam de fora', async () => {
        const w = mountBatch(['s1', 's3']);
        const form = lastForm();

        expect(form.covenant_id).toBe('cov-1');
        expect(w.find('[data-test="batch-summary"]').text()).toContain(
            '1 atendimento(s) marcado(s) deste convênio entrarão no lote.',
        );
        expect(w.find('[data-test="batch-excluded"]').text()).toContain(
            '1 marcado(s) de outro convênio ficarão de fora.',
        );

        form.unit_price = 150;
        await nextTick();
        expect(norm(w.find('[data-test="batch-estimated"]').text())).toContain(norm(brl(150)));
    });

    it('sem seleção, explica que entram todos os elegíveis do convênio no período', async () => {
        const w = mountBatch([]);
        const form = lastForm();

        form.covenant_id = 'cov-1';
        await nextTick();

        expect(w.find('[data-test="batch-summary"]').text()).toContain('(2 na lista atual)');
    });

    it('pré-preenche o valor unitário quando os atendimentos do lote têm o mesmo preço na tabela', async () => {
        const w = mountBatch(
            ['s1', 's2'],
            [
                schedule({ id: 's1', covenant_id: 'cov-1', suggested_price: 150 }),
                schedule({ id: 's2', covenant_id: 'cov-1', suggested_price: 150 }),
            ],
        );
        const form = lastForm();

        expect(form.unit_price).toBe(150);
        expect(form.isDirty).toBe(false);
        expect(norm(w.find('[data-test="batch-suggested"]').text())).toContain(
            norm(`Preço da tabela para os atendimentos do lote: ${brl(150)}.`),
        );
        expect(w.find('#billing-batch-price').element.value).toBe('150,00');
    });

    it('preços diferentes na tabela: avisa a faixa e não pré-preenche', async () => {
        const w = mountBatch(
            [],
            [
                schedule({
                    id: 's1',
                    covenant_id: 'cov-1',
                    date_time: '2026-09-10T09:00:00-03:00',
                    suggested_price: 150,
                }),
                schedule({
                    id: 's2',
                    covenant_id: 'cov-1',
                    date_time: '2026-09-11T09:00:00-03:00',
                    suggested_price: 180,
                }),
            ],
        );
        const form = lastForm();

        form.covenant_id = 'cov-1';
        await nextTick();

        expect(form.unit_price).toBeNull();
        expect(norm(w.find('[data-test="batch-suggested"]').text())).toContain(norm(`(${brl(150)} a ${brl(180)})`));
    });

    it('total estimado ao vivo = guias × quantidade × valor, e a quantidade vai no POST', async () => {
        const w = mountBatch(['s1', 's2']);
        const form = lastForm();

        form.unit_price = 100;
        form.quantity = 2;
        await nextTick();

        const estimated = norm(w.find('[data-test="batch-estimated"]').text());
        expect(estimated).toContain(norm(brl(400)));
        expect(estimated).toContain(norm(`2 guia(s) × 2 × ${brl(100)}`));

        await w.find('[data-test="create-batch"]').trigger('click');
        expect(form.lastPost.data.quantity).toBe(2);
    });

    it('envia todos os marcados (o servidor filtra pelo convênio) e não os campos de versão TISS', async () => {
        const w = mountBatch(['s1', 's3']);

        await w.find('[data-test="create-batch"]').trigger('click');

        const { url, data, options } = lastForm().lastPost;
        expect(url).toBe('/billing/batch');
        expect(data.schedule_ids).toEqual(['s1', 's3']);
        expect(data).not.toHaveProperty('tiss_version');
        expect(options.preserveState).toBe(true);
    });

    it('avisa cobrança particular ao escolher convênio sem registro ANS', async () => {
        const w = mountBatch([]);

        lastForm().covenant_id = 'cov-2';
        await nextTick();

        expect(w.find('[data-test="particular-hint"]').exists()).toBe(true);
    });

    it('CID pelo Cid10Picker: grava só o código e o grupo tem rótulo', async () => {
        const w = mountBatch(['s1']);

        const group = w.find('[data-test="billing-batch-cid-field"]');
        expect(group.attributes('role')).toBe('group');
        expect(w.find(`#${group.attributes('aria-labelledby')}`).text()).toBe(t.clinical_indication);

        await group.find('.cid-stub').trigger('click');

        expect(lastForm().clinical_indication).toBe('H40.1');
    });
});

describe('IndividualClaimModal', () => {
    const covenants = [{ id: 'cov-1', name: 'Unimed', has_ans_registry: true }];

    function mountIndividual(scheduleOverrides = {}) {
        wrapper = mount(IndividualClaimModal, {
            props: {
                open: true,
                schedule: schedule(scheduleOverrides),
                covenants,
                url: '/billing/individual',
                cid10SearchUrl: '/cid10',
                procedurePricesUrl: '/financial/procedure-prices',
                t,
            },
            global,
        });

        return wrapper;
    }

    it('mostra o resumo do atendimento, o aviso de lote TISS e o total ao vivo', async () => {
        const w = mountIndividual();
        const form = lastForm();

        expect(form.schedule_id).toBe('s1');
        expect(w.find('[data-test="individual-summary"]').text()).toContain('João Lima');
        expect(w.find('[data-test="individual-tiss-hint"]').text()).toContain('precisa entrar num lote');

        form.quantity = 2;
        form.unit_price = 75.5;
        await nextTick();

        expect(norm(w.find('[data-test="individual-total"]').text())).toContain(norm(brl(151)));
        expect(norm(w.find('[data-test="individual-total"]').text())).toContain(norm(`(2 × ${brl(75.5)})`));
    });

    it('sugere o valor pela tabela de preços (procedimento × convênio) sem sujar o formulário', async () => {
        const w = mountIndividual({ suggested_price: 150, procedure_name: 'CONSULTA' });
        const form = lastForm();

        expect(form.unit_price).toBe(150);
        expect(w.find('#billing-ind-price').element.value).toBe('150,00');
        expect(norm(w.find('[data-test="individual-suggested"]').text())).toContain(
            norm(`Preço da tabela (CONSULTA × Unimed): ${brl(150)}.`),
        );

        // Nada digitado: fecha sem pedir confirmação.
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(w.emitted('close')).toHaveLength(1);
    });

    it('valor digitado no formato do idioma; "Usar" volta ao preço da tabela', async () => {
        const w = mountIndividual({ suggested_price: 150 });
        const form = lastForm();

        await w.find('#billing-ind-price').setValue('1.234,50');
        expect(form.unit_price).toBe(1234.5);

        const use = w.find('[data-test="use-suggested"]');
        expect(norm(use.text())).toBe(norm(`Usar ${brl(150)}`));
        await use.trigger('click');
        expect(form.unit_price).toBe(150);
    });

    it('sem preço na tabela: avisa e oferece a tabela de preços em nova aba', () => {
        const w = mountIndividual();

        const hint = w.find('[data-test="individual-suggested"]');
        expect(hint.text()).toContain(t.suggested_price_none);
        const link = hint.find('a');
        expect(link.attributes('href')).toBe('/financial/procedure-prices');
        expect(link.attributes('target')).toBe('_blank');
        expect(link.text()).toContain(t.new_tab);
        expect(w.find('#billing-ind-price').attributes('aria-describedby')).toContain('billing-ind-price-hint');
    });

    it('CID pelo Cid10Picker com a URL de busca do servidor', async () => {
        const w = mountIndividual();

        const group = w.find('[data-test="billing-ind-cid-field"]');
        expect(group.find('.cid-stub').attributes('data-url')).toBe('/cid10');

        await group.find('.cid-stub').trigger('click');
        expect(lastForm().clinical_indication).toBe('H40.1');
    });

    it('mostra o erro de validação do valor ligado ao campo', async () => {
        const w = mountIndividual();

        lastForm().setError('unit_price', 'Informe o valor unitário.');
        await nextTick();

        const input = w.find('#billing-ind-price');
        expect(input.attributes('aria-invalid')).toBe('true');
        expect(input.attributes('aria-describedby')).toContain('billing-ind-price-error');
        expect(w.find('#billing-ind-price-error').text()).toBe('Informe o valor unitário.');
    });

    it('pede confirmação antes de descartar o que foi digitado', async () => {
        const w = mountIndividual();

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(w.emitted('close')).toHaveLength(1); // nada digitado: fecha direto

        lastForm().authorization_code = 'AUT-1';
        await nextTick();

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await nextTick();

        expect(w.emitted('close')).toHaveLength(1);
        expect(w.find('[data-test="discard-prompt"]').text()).toBe(t.discard_title);

        await w.find('[data-test="discard"]').trigger('click');
        expect(w.emitted('close')).toHaveLength(2);
    });
});
