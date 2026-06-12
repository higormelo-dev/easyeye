import { describe, it, expect, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import ManualCreditModal from '@/Pages/Panel/Manager/AiCreditPurchases/ManualCreditModal.vue';
import SearchSelect from '@/Components/Panel/SearchSelect.vue';

/**
 * Testes de unidade do modal de concessão de crédito à clínica.
 *
 * A seleção de empresa usa SearchSelect (wrapper do @vueform/multiselect, que
 * NÃO renderiza um <select> nativo), então interagimos com a entity emitindo
 * update:modelValue diretamente no componente — robusto e independente do DOM.
 *
 * Cobre:
 *   - Renderização condicional (open=true vs open=false)
 *   - Validação de isValid (entity_id + credits + reason ≥ 10 chars)
 *   - Validação extra na compra paga (amount_reais > 0)
 *   - Filtro de entidades por permissão (create_manual_for_internal)
 *   - Reset do form ao reabrir (watch open)
 *   - Pré-seleção de entity (presetEntityId)
 *   - Detecção de entity interna selecionada
 *   - Emit close e submit (cortesia × compra)
 */
describe('ManualCreditModal', () => {
    const entities = [
        { id: 'ent-internal', name: 'Easyeye Internal', is_client: false },
        { id: 'ent-client-a', name: 'Clínica A',        is_client: true },
        { id: 'ent-client-b', name: 'Clínica B',        is_client: true },
    ];

    const defaultPermissions = {
        create_manual: true,
        create_manual_unlimited: true,
        create_manual_for_internal: true,
        support_daily_limit: 500,
    };

    const tStubs = {
        manual: {
            modal_title:    'Conceder crédito a uma clínica',
            submit:         'Conceder crédito',
            cancel:         'Cancelar',
            kind_purchase:  'Compra (paga)',
            badge_internal: 'Sua empresa',
        },
    };

    function mountModal(propsOverride = {}) {
        return mount(ManualCreditModal, {
            attachTo: document.body,
            props: {
                open: true,
                entities,
                permissions: defaultPermissions,
                t: tStubs,
                ...propsOverride,
            },
        });
    }

    // Seleciona a entity via o componente SearchSelect (sem <select> nativo).
    async function selectEntity(wrapper, value) {
        wrapper.findComponent(SearchSelect).vm.$emit('update:modelValue', value);
        await wrapper.vm.$nextTick();
    }

    function entityOptionIds(wrapper) {
        return wrapper.findComponent(SearchSelect).props('options').map((o) => o.id);
    }

    function fillReason(text) {
        const textarea = document.body.querySelector('textarea');
        textarea.value = text;
        textarea.dispatchEvent(new Event('input'));
    }

    // Alterna para "compra paga" e, após o re-render, informa o valor em reais.
    async function setPurchaseAmount(wrapper, reais) {
        const purchaseRadio = document.body.querySelector('#kind-purchase');
        purchaseRadio.checked = true;
        purchaseRadio.dispatchEvent(new Event('change'));
        await wrapper.vm.$nextTick();

        const amount = document.body.querySelector('input[type="number"][step="0.01"]');
        if (amount && reais != null) {
            amount.value = String(reais);
            amount.dispatchEvent(new Event('input'));
            await wrapper.vm.$nextTick();
        }
    }

    function submitBtn() {
        return document.body.querySelector('button[type="submit"]');
    }

    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('não renderiza quando open=false', () => {
        const wrapper = mountModal({ open: false });
        expect(document.body.querySelector('.modal')).toBeNull();
        wrapper.unmount();
    });

    it('renderiza modal com título quando open=true', () => {
        const wrapper = mountModal();
        const modal = document.body.querySelector('.modal');
        expect(modal).not.toBeNull();
        expect(modal.textContent).toContain('Conceder crédito a uma clínica');
        wrapper.unmount();
    });

    it('botão submit começa desabilitado (form vazio)', () => {
        const wrapper = mountModal();
        expect(submitBtn().disabled).toBe(true);
        wrapper.unmount();
    });

    it('botão submit fica habilitado quando entity + credits + reason ≥ 10 chars (cortesia)', async () => {
        const wrapper = mountModal();

        await selectEntity(wrapper, 'ent-client-a');
        fillReason('cortesia institucional por incidente xpto');
        await wrapper.vm.$nextTick();

        expect(submitBtn().disabled).toBe(false);
        wrapper.unmount();
    });

    it('rejeita reason com menos de 10 caracteres', async () => {
        const wrapper = mountModal();

        await selectEntity(wrapper, 'ent-client-a');
        fillReason('curto');
        await wrapper.vm.$nextTick();

        expect(submitBtn().disabled).toBe(true);
        wrapper.unmount();
    });

    it('na compra paga, submit fica desabilitado sem valor e habilita ao informar amount_reais', async () => {
        const wrapper = mountModal();

        await selectEntity(wrapper, 'ent-client-a');
        fillReason('compra avulsa de créditos paga via pix');
        await wrapper.vm.$nextTick();

        // Alterna para "compra" sem informar valor → inválido
        await setPurchaseAmount(wrapper, null);
        expect(submitBtn().disabled).toBe(true);

        // Informa valor em reais → válido
        const amount = document.body.querySelector('input[type="number"][step="0.01"]');
        amount.value = '249.90';
        amount.dispatchEvent(new Event('input'));
        await wrapper.vm.$nextTick();

        expect(submitBtn().disabled).toBe(false);
        wrapper.unmount();
    });

    it('filtra entidades internas quando permission create_manual_for_internal=false', () => {
        const wrapper = mountModal({
            permissions: { ...defaultPermissions, create_manual_for_internal: false },
        });

        const ids = entityOptionIds(wrapper);
        expect(ids).toContain('ent-client-a');
        expect(ids).toContain('ent-client-b');
        expect(ids).not.toContain('ent-internal');
        wrapper.unmount();
    });

    it('inclui entity interna na lista quando permission é true', () => {
        const wrapper = mountModal();
        expect(entityOptionIds(wrapper)).toContain('ent-internal');
        wrapper.unmount();
    });

    it('pré-seleciona entity via presetEntityId ao abrir', async () => {
        const wrapper = mountModal({ open: false, presetEntityId: 'ent-internal' });

        await wrapper.setProps({ open: true });
        await wrapper.vm.$nextTick();

        expect(wrapper.findComponent(SearchSelect).props('modelValue')).toBe('ent-internal');
        wrapper.unmount();
    });

    it('mostra badge "Sua empresa" ao selecionar entity interna', async () => {
        const wrapper = mountModal({ open: false, presetEntityId: 'ent-internal' });
        await wrapper.setProps({ open: true });
        await wrapper.vm.$nextTick();

        expect(document.body.textContent).toContain('Sua empresa');
        wrapper.unmount();
    });

    it('não mostra badge ao selecionar entity-cliente normal', async () => {
        const wrapper = mountModal({ open: false, presetEntityId: 'ent-client-a' });
        await wrapper.setProps({ open: true });
        await wrapper.vm.$nextTick();

        const badge = document.body.querySelector('.badge.bg-primary-subtle');
        expect(badge).toBeNull();
        wrapper.unmount();
    });

    it('emite close ao clicar no botão de cancelar', async () => {
        const wrapper = mountModal();

        const cancelBtn = Array.from(document.body.querySelectorAll('button'))
            .find((b) => b.textContent.trim() === 'Cancelar');

        cancelBtn.click();
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('close')).toBeTruthy();
        expect(wrapper.emitted('close').length).toBe(1);
        wrapper.unmount();
    });

    it('emite submit de cortesia com payload correto (kind=courtesy, amount_reais=0)', async () => {
        const wrapper = mountModal();

        await selectEntity(wrapper, 'ent-client-a');
        fillReason('motivo válido com mais de 10 caracteres');
        await wrapper.vm.$nextTick();

        document.body.querySelector('form').dispatchEvent(new Event('submit'));
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('submit')).toBeTruthy();
        const payload = wrapper.emitted('submit')[0][0];
        expect(payload.entity_id).toBe('ent-client-a');
        expect(payload.credits).toBe(100);                              // default
        expect(payload.reason).toBe('motivo válido com mais de 10 caracteres');
        expect(payload.kind).toBe('courtesy');                          // default
        expect(payload.amount_reais).toBe(0);                           // cortesia não tem valor
        expect(payload.package_code).toBeUndefined();                   // não enviamos mais package_code
        wrapper.unmount();
    });

    it('emite submit de compra paga com amount_reais informado', async () => {
        const wrapper = mountModal();

        await selectEntity(wrapper, 'ent-client-b');
        fillReason('compra avulsa paga fora do app via boleto');
        await wrapper.vm.$nextTick();

        await setPurchaseAmount(wrapper, '249.90');

        document.body.querySelector('form').dispatchEvent(new Event('submit'));
        await wrapper.vm.$nextTick();

        const payload = wrapper.emitted('submit')[0][0];
        expect(payload.kind).toBe('purchase');
        expect(payload.amount_reais).toBe(249.9);
        wrapper.unmount();
    });

    it('reseta o form quando reabre (watch open: false→true)', async () => {
        const wrapper = mountModal();

        fillReason('algum texto antigo');
        await wrapper.vm.$nextTick();

        await wrapper.setProps({ open: false });
        await wrapper.setProps({ open: true });
        await wrapper.vm.$nextTick();

        const textareaReset = document.body.querySelector('textarea');
        expect(textareaReset.value).toBe('');
        wrapper.unmount();
    });

    it('mostra alerta de limite Support quando create_manual_unlimited=false', () => {
        const wrapper = mountModal({
            permissions: { ...defaultPermissions, create_manual_unlimited: false },
        });

        const alert = document.body.querySelector('.alert-warning');
        expect(alert).not.toBeNull();
        expect(alert.textContent).toContain('500');                     // limite
        wrapper.unmount();
    });

    it('NÃO mostra alerta de limite Support quando create_manual_unlimited=true', () => {
        const wrapper = mountModal();
        const alert = document.body.querySelector('.alert-warning');
        expect(alert).toBeNull();
        wrapper.unmount();
    });
});
