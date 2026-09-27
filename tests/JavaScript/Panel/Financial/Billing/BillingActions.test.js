import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import FixPendingModal from '@/Pages/Panel/Financial/Billing/FixPendingModal.vue';
import PreValidationResult from '@/Pages/Panel/Financial/Billing/PreValidationResult.vue';
import { isPaginator, pageRows } from '@/Pages/Panel/Financial/Billing/billingHelpers.js';
import { resetInertiaMock } from './support/inertiaMock.js';
import { t, claim, paginate } from './support/fixtures.js';

vi.mock('@inertiajs/vue3', async () => (await import('./support/inertiaMock.js')).buildInertiaMock());
// Cid10Picker real busca na API; o stub devolve uma seleção ao clicar.
vi.mock('@/Components/Panel/Cid10Picker.vue', () => ({
    default: {
        props: ['modelValue', 'searchUrl', 'multiple', 'placeholder'],
        emits: ['update:modelValue'],
        template: `<button type="button" class="cid-stub" :data-url="searchUrl"
            @click="$emit('update:modelValue', [{ code: 'H40.1', description: 'Glaucoma' }])">{{ (modelValue || []).map((i) => i.code).join(',') }}</button>`,
    },
}));

const pending = claim({
    id: 'c5',
    code: 'GUI-0005',
    guide_number: 'GUI-202609-000005',
    patient_name: 'Maria Souza',
    allowed_actions: ['fix_pending'],
    fix_pending: { url: '/claims/c5/fix-pending', clinical_indication: 'H52.1', beneficiary_card_number: null, authorization_number: 'A1' },
});

let wrapper;

function mountModal(props = {}) {
    wrapper = mount(FixPendingModal, {
        props: { open: true, claim: pending, cid10SearchUrl: '/cid10', t, ...props },
        global: { stubs: { teleport: true } },
        attachTo: document.body,
    });

    return wrapper;
}

function axiosError(status, data) {
    return Object.assign(new Error(String(status)), { response: { status, data } });
}

beforeEach(() => resetInertiaMock());
afterEach(() => {
    wrapper?.unmount();
    delete window.axios;
});

describe('FixPendingModal (corrigir pendência)', () => {
    it('abre com os valores atuais da guia e rótulos/dicas ligados aos campos', () => {
        const w = mountModal();

        expect(w.find('[data-test="fix-summary"]').text()).toContain('GUI-0005');
        expect(w.find('[data-test="fix-summary"]').text()).toContain('Maria Souza');
        expect(w.find('.cid-stub').text()).toBe('H52.1');
        expect(w.find('[data-test="fix-card"]').element.value).toBe('');
        expect(w.find('[data-test="fix-auth"]').element.value).toBe('A1');
        expect(w.find('label[for="billing-fix-card"]').text()).toBe(t.fix_card_number);
        expect(w.find('[data-test="fix-card"]').attributes('aria-describedby')).toBe('billing-fix-card-hint');
    });

    it('422 de campo vai para o campo (aria-invalid + mensagem ligada); erro geral vira alerta', async () => {
        window.axios = {
            post: vi.fn(() => Promise.reject(axiosError(422, {
                message: 'The given data was invalid.',
                errors: {
                    clinical_indication: ['Informe o CID-10 no padrão da ANS (letra + 2 dígitos, ex.: H40.1).'],
                    beneficiary_card_number: ['Use no máximo 64 caracteres.'],
                },
            }))),
        };
        const w = mountModal();

        await w.find('[data-test="fix-save"]').trigger('click');
        await flushPromises();

        expect(w.find('#billing-fix-cid-error').text()).toContain('CID-10');
        const card = w.find('[data-test="fix-card"]');
        expect(card.attributes('aria-invalid')).toBe('true');
        expect(card.attributes('aria-describedby')).toBe('billing-fix-card-hint billing-fix-beneficiary_card_number-error');
        expect(w.find('#billing-fix-beneficiary_card_number-error').text()).toBe('Use no máximo 64 caracteres.');
        expect(w.find('[data-test="fix-error"]').exists()).toBe(false);
    });

    it('recusa do servidor (guia já no lote / enviada) aparece como alerta geral, sem resultado', async () => {
        window.axios = {
            post: vi.fn(() => Promise.reject(axiosError(422, {
                message: 'A guia GUI-0005 não tem pendência TISS a corrigir.',
                errors: { status: ['A guia GUI-0005 não tem pendência TISS a corrigir: a guia TISS já entrou no lote ou já foi enviada.'] },
            }))),
        };
        const w = mountModal();

        await w.find('[data-test="fix-save"]').trigger('click');
        await flushPromises();

        expect(w.find('[data-test="fix-error"]').attributes('role')).toBe('alert');
        expect(w.find('[data-test="fix-error"]').text()).toContain('já entrou no lote');
        expect(w.find('[data-test="fix-result"]').exists()).toBe(false);
        expect(w.emitted('saved')).toBeUndefined();
    });

    it('falha de rede/500 mostra a mensagem genérica traduzida', async () => {
        window.axios = { post: vi.fn(() => Promise.reject(axiosError(500, {}))) };
        const w = mountModal();

        await w.find('[data-test="fix-save"]').trigger('click');
        await flushPromises();

        expect(w.find('[data-test="fix-error"]').text()).toBe(t.fix_failed);
    });

    it('sucesso: resultado num aviso ao vivo (role=status), foco no resultado, emite saved', async () => {
        const data = {
            message: 'Dados da guia GUI-0005 salvos, mas ainda há pendência: veja os erros abaixo.',
            attached: false,
            validation: {
                passes: false,
                errors: [{ message: 'Número da carteirinha do beneficiário não informado.', suggestion: 'Informe o número.' }],
                warnings: [],
                summary: 'Guia possui pendências que causarão glosa.',
            },
        };
        window.axios = { post: vi.fn(() => Promise.resolve({ data })) };
        const w = mountModal();

        await w.find('.cid-stub').trigger('click');
        await w.find('[data-test="fix-save"]').trigger('click');
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledWith('/claims/c5/fix-pending', {
            clinical_indication: 'H40.1', beneficiary_card_number: '', authorization_number: 'A1',
        });
        expect(w.find('[role="status"] [data-test="fix-result"]').exists()).toBe(true);
        expect(w.find('[data-test="fix-message"]').classes()).toContain('alert-warning');
        expect(w.find('[data-test="prevalidation-errors"]').text()).toContain('carteirinha');
        expect(document.activeElement).toBe(w.find('[data-test="fix-result"]').element);
        expect(w.emitted('saved')[0][0]).toEqual(data);
    });

    it('Esc e "Fechar" fecham (não durante o envio)', async () => {
        let resolve;
        window.axios = { post: vi.fn(() => new Promise((r) => { resolve = r; })) };
        const w = mountModal();

        await w.find('[data-test="fix-save"]').trigger('click');
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(w.emitted('close')).toBeUndefined();
        expect(w.find('[data-test="fix-save"]').attributes('disabled')).toBeDefined();

        resolve({ data: { message: 'ok', attached: false, validation: { passes: true, errors: [], warnings: [] } } });
        await flushPromises();

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(w.emitted('close')).toHaveLength(1);

        await w.find('[data-test="fix-close"]').trigger('click');
        expect(w.emitted('close')).toHaveLength(2);
    });
});

describe('PreValidationResult', () => {
    it('sem pendência: mensagem de sucesso; com erros/avisos: listas separadas', () => {
        const ok = mount(PreValidationResult, { props: { t, result: { passes: true, errors: [], warnings: [], summary: 'OK' } } });
        expect(ok.text()).toContain(t.pending_no_issues);

        const bad = mount(PreValidationResult, {
            props: { t, result: { passes: false, errors: [{ message: 'Sem CID' }], warnings: [{ message: 'Sem olho' }] } },
        });
        expect(bad.find('[data-test="prevalidation-errors"]').text()).toContain('Sem CID');
        expect(bad.find('[data-test="prevalidation-warnings"]').text()).toContain('Sem olho');
        expect(bad.text()).not.toContain(t.pending_no_issues);
    });
});

describe('billingHelpers (paginação)', () => {
    it('pageRows lê o paginator ou um array; isPaginator exige links', () => {
        expect(pageRows(paginate([{ id: 1 }]))).toEqual([{ id: 1 }]);
        expect(pageRows([{ id: 2 }])).toEqual([{ id: 2 }]);
        expect(pageRows(null)).toEqual([]);
        expect(isPaginator(paginate([]))).toBe(true);
        expect(isPaginator([])).toBe(false);
        expect(isPaginator({ data: [] })).toBe(false);
    });
});
