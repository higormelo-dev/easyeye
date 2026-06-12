import { describe, it, expect, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import ProviderTopupModal from '@/Pages/Panel/Manager/AiCreditPurchases/ProviderTopupModal.vue';

/**
 * Testes do modal de registro de recarga (topup) do EasyEye nos provedores.
 *
 * Cobre:
 *   - Renderização condicional via prop `open`
 *   - 3 provedores hardcoded (openai/anthropic/gemini)
 *   - Reset do form ao reabrir
 *   - Pré-seleção via presetProvider
 *   - Validação de isValid
 *   - Emit submit com payload correto
 *   - Métodos expostos setSaving e setError
 */
describe('ProviderTopupModal', () => {
    const tStubs = {
        topup: {
            modal_title:  'Registrar recarga no provedor',
            provider:     'Provedor',
            amount:       'Valor (USD)',
            topped_up_at: 'Data',
            reference:    'Referência',
            note:         'Observação',
            submit:       'Registrar recarga',
            cancel:       'Cancelar',
        },
    };

    function mountModal(propsOverride = {}) {
        return mount(ProviderTopupModal, {
            attachTo: document.body,
            props: {
                open: true,
                t: tStubs,
                ...propsOverride,
            },
        });
    }

    // Os dois campos numéricos compartilham type/step; distinguimos pelo max:
    // R$ (valor pago) usa max=99999999; US$ (creditado) usa max=1000000.
    const brlInput = () => document.body.querySelector('input[type="number"][max="99999999"]');
    const usdInput = () => document.body.querySelector('input[type="number"][max="1000000"]');

    function fillNumber(input, value) {
        input.value = String(value);
        input.dispatchEvent(new Event('input'));
    }

    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('não renderiza quando open=false', () => {
        const wrapper = mountModal({ open: false });
        expect(document.body.querySelector('.modal')).toBeNull();
        wrapper.unmount();
    });

    it('renderiza modal e exibe título quando open=true', () => {
        const wrapper = mountModal();
        const modal = document.body.querySelector('.modal');
        expect(modal).not.toBeNull();
        expect(modal.textContent).toContain('Registrar recarga no provedor');
        wrapper.unmount();
    });

    it('renderiza os 3 provedores como opções (radio)', () => {
        const wrapper = mountModal();
        const radios = document.body.querySelectorAll('input[type="radio"][name][value], input[type="radio"].btn-check');
        const values = Array.from(document.body.querySelectorAll('input[type="radio"].btn-check')).map((r) => r.value);

        expect(values).toContain('openai');
        expect(values).toContain('anthropic');
        expect(values).toContain('gemini');
        expect(values.length).toBe(3);
        wrapper.unmount();
    });

    it('default selecionado é openai', () => {
        const wrapper = mountModal();
        const checked = document.body.querySelector('input[type="radio"].btn-check:checked');
        expect(checked.value).toBe('openai');
        wrapper.unmount();
    });

    it('aplica presetProvider ao abrir (open: false → true)', async () => {
        const wrapper = mountModal({ open: false, presetProvider: 'gemini' });
        await wrapper.setProps({ open: true });
        await wrapper.vm.$nextTick();

        const checked = document.body.querySelector('input[type="radio"].btn-check:checked');
        expect(checked.value).toBe('gemini');
        wrapper.unmount();
    });

    it('valor padrão = 100 creditado no provedor (US$)', () => {
        const wrapper = mountModal();
        expect(Number(usdInput().value)).toBe(100);
        wrapper.unmount();
    });

    it('isValid só fica true após informar o valor pago (R$)', async () => {
        const wrapper = mountModal();

        // Sem valor pago (amount_brl) o form é inválido, mesmo com US$ default.
        expect(document.body.querySelector('button[type="submit"]').disabled).toBe(true);

        fillNumber(brlInput(), '550');
        await wrapper.vm.$nextTick();

        expect(document.body.querySelector('button[type="submit"]').disabled).toBe(false);
        wrapper.unmount();
    });

    it('isValid=false quando amount_usd ≤ 0', async () => {
        const wrapper = mountModal();
        const valueInput = document.body.querySelector('input[type="number"]');
        valueInput.value = '0';
        valueInput.dispatchEvent(new Event('input'));
        await wrapper.vm.$nextTick();

        expect(document.body.querySelector('button[type="submit"]').disabled).toBe(true);
        wrapper.unmount();
    });

    it('isValid=false quando topped_up_at vazio', async () => {
        const wrapper = mountModal();
        const dateInput = document.body.querySelector('input[type="datetime-local"]');
        dateInput.value = '';
        dateInput.dispatchEvent(new Event('input'));
        await wrapper.vm.$nextTick();

        expect(document.body.querySelector('button[type="submit"]').disabled).toBe(true);
        wrapper.unmount();
    });

    it('emite submit com payload completo (incl. reference/note null quando vazios)', async () => {
        const wrapper = mountModal({ presetProvider: 'anthropic' });
        await wrapper.setProps({ open: false });
        await wrapper.setProps({ open: true });
        await wrapper.vm.$nextTick();

        fillNumber(brlInput(), '1300');
        fillNumber(usdInput(), '250.5');
        await wrapper.vm.$nextTick();

        document.body.querySelector('form').dispatchEvent(new Event('submit'));
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('submit')).toBeTruthy();
        const payload = wrapper.emitted('submit')[0][0];

        expect(payload.provider).toBe('anthropic');
        expect(payload.amount_usd).toBe(250.5);
        expect(payload.amount_brl).toBe(1300);
        expect(payload.reference).toBeNull();                           // vazio → null
        expect(payload.note).toBeNull();
        expect(payload.topped_up_at).toBeTruthy();                      // datetime preenchido por default
        wrapper.unmount();
    });

    it('emite submit com reference e note quando preenchidos', async () => {
        const wrapper = mountModal();

        fillNumber(brlInput(), '550');                                   // valor pago obrigatório

        const inputs = document.body.querySelectorAll('input[type="text"]');
        const refInput = inputs[0];                                      // referência é o primeiro text
        refInput.value = 'ch_TEST_001';
        refInput.dispatchEvent(new Event('input'));

        const noteInput = document.body.querySelector('textarea');
        noteInput.value = 'recarga preventiva';
        noteInput.dispatchEvent(new Event('input'));

        await wrapper.vm.$nextTick();

        document.body.querySelector('form').dispatchEvent(new Event('submit'));
        await wrapper.vm.$nextTick();

        const payload = wrapper.emitted('submit')[0][0];
        expect(payload.reference).toBe('ch_TEST_001');
        expect(payload.note).toBe('recarga preventiva');
        wrapper.unmount();
    });

    it('emite close ao clicar em cancelar', async () => {
        const wrapper = mountModal();

        const cancelBtn = Array.from(document.body.querySelectorAll('button'))
            .find((b) => b.textContent.trim() === 'Cancelar');

        cancelBtn.click();
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('close')).toBeTruthy();
        wrapper.unmount();
    });

    it('expõe setSaving — desabilita botão submit e cancelar', async () => {
        const wrapper = mountModal();

        wrapper.vm.setSaving(true);
        await wrapper.vm.$nextTick();

        const submit = document.body.querySelector('button[type="submit"]');
        const cancel = Array.from(document.body.querySelectorAll('button'))
            .find((b) => b.textContent.trim() === 'Cancelar');

        expect(submit.disabled).toBe(true);
        expect(cancel.disabled).toBe(true);
        wrapper.unmount();
    });

    it('expõe setError — renderiza alert vermelho com mensagem', async () => {
        const wrapper = mountModal();

        wrapper.vm.setError('Falha na requisição XPTO');
        await wrapper.vm.$nextTick();

        const alert = document.body.querySelector('.alert-danger');
        expect(alert).not.toBeNull();
        expect(alert.textContent).toContain('Falha na requisição XPTO');
        wrapper.unmount();
    });

    it('reseta o form ao reabrir (open false → true) — limpa erro também', async () => {
        const wrapper = mountModal();

        // Polui o estado
        wrapper.vm.setError('erro antigo');
        const noteInput = document.body.querySelector('textarea');
        noteInput.value = 'rascunho antigo';
        noteInput.dispatchEvent(new Event('input'));
        await wrapper.vm.$nextTick();

        // Fecha e reabre
        await wrapper.setProps({ open: false });
        await wrapper.setProps({ open: true });
        await wrapper.vm.$nextTick();

        expect(document.body.querySelector('.alert-danger')).toBeNull();
        expect(document.body.querySelector('textarea').value).toBe('');
        wrapper.unmount();
    });

    it('não emite close enquanto saving=true (impede fechar durante request)', async () => {
        const wrapper = mountModal();

        wrapper.vm.setSaving(true);
        await wrapper.vm.$nextTick();

        const cancelBtn = Array.from(document.body.querySelectorAll('button'))
            .find((b) => b.textContent.trim() === 'Cancelar');

        cancelBtn.click();
        await wrapper.vm.$nextTick();

        // Saving impede emit (botão está disabled, mas se forçar não deve emitir tb)
        expect(wrapper.emitted('close')).toBeFalsy();
        wrapper.unmount();
    });
});
