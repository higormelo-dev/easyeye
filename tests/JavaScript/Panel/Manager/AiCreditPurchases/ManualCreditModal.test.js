import { describe, it, expect, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import ManualCreditModal from '@/Pages/Panel/Manager/AiCreditPurchases/ManualCreditModal.vue';

/**
 * Testes de unidade do modal de crédito manual.
 *
 * Cobre:
 *   - Renderização condicional (open=true vs open=false)
 *   - Validação de isValid (entity_id + credits + reason ≥ 10 chars)
 *   - Filtro de entidades por permissão (create_manual_for_internal)
 *   - Reset do form ao reabrir (watch open)
 *   - Pré-seleção de entity (presetEntityId)
 *   - Detecção de entity interna selecionada
 *   - Emit close e submit
 */
describe('ManualCreditModal', () => {
    const entities = [
        { id: 'ent-internal', name: 'Easyeye Internal', is_client: false },
        { id: 'ent-client-a', name: 'Clínica A',        is_client: true },
        { id: 'ent-client-b', name: 'Clínica B',        is_client: true },
    ];

    const providers = [
        { value: 'openai',    label: 'ChatGPT' },
        { value: 'anthropic', label: 'Claude' },
        { value: 'gemini',    label: 'Gemini' },
    ];

    const defaultPermissions = {
        create_manual: true,
        create_manual_unlimited: true,
        create_manual_for_internal: true,
        support_daily_limit: 500,
    };

    const tStubs = {
        manual: {
            modal_title:     'Adicionar crédito manual',
            submit:          'Lançar crédito',
            cancel:          'Cancelar',
            no_provider:     'Sem preferência',
            badge_internal:  'Sua empresa',
        },
    };

    function mountModal(propsOverride = {}) {
        return mount(ManualCreditModal, {
            attachTo: document.body,
            props: {
                open: true,
                entities,
                providers,
                permissions: defaultPermissions,
                t: tStubs,
                ...propsOverride,
            },
        });
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
        expect(modal.textContent).toContain('Adicionar crédito manual');
        wrapper.unmount();
    });

    it('botão submit começa desabilitado (form vazio)', () => {
        const wrapper = mountModal();
        const submit = document.body.querySelector('button[type="submit"]');
        expect(submit.disabled).toBe(true);
        wrapper.unmount();
    });

    it('botão submit fica habilitado quando entity + credits + reason ≥ 10 chars', async () => {
        const wrapper = mountModal();

        const select = document.body.querySelector('select');
        select.value = 'ent-client-a';
        select.dispatchEvent(new Event('change'));

        const textarea = document.body.querySelector('textarea');
        textarea.value = 'cortesia institucional por incidente xpto';
        textarea.dispatchEvent(new Event('input'));

        await wrapper.vm.$nextTick();

        const submit = document.body.querySelector('button[type="submit"]');
        expect(submit.disabled).toBe(false);
        wrapper.unmount();
    });

    it('rejeita reason com menos de 10 caracteres', async () => {
        const wrapper = mountModal();

        document.body.querySelector('select').value = 'ent-client-a';
        document.body.querySelector('select').dispatchEvent(new Event('change'));

        const textarea = document.body.querySelector('textarea');
        textarea.value = 'curto';
        textarea.dispatchEvent(new Event('input'));

        await wrapper.vm.$nextTick();

        expect(document.body.querySelector('button[type="submit"]').disabled).toBe(true);
        wrapper.unmount();
    });

    it('filtra entidades internas quando permission create_manual_for_internal=false', () => {
        const wrapper = mountModal({
            permissions: { ...defaultPermissions, create_manual_for_internal: false },
        });

        const options = Array.from(document.body.querySelector('select').options)
            .map((o) => o.value)
            .filter(Boolean);

        expect(options).toContain('ent-client-a');
        expect(options).toContain('ent-client-b');
        expect(options).not.toContain('ent-internal');
        wrapper.unmount();
    });

    it('inclui entity interna na lista quando permission é true', () => {
        const wrapper = mountModal();
        const options = Array.from(document.body.querySelector('select').options).map((o) => o.value);
        expect(options).toContain('ent-internal');
        wrapper.unmount();
    });

    it('pré-seleciona entity via presetEntityId ao abrir', async () => {
        const wrapper = mountModal({ open: false, presetEntityId: 'ent-internal' });

        // Simula abertura
        await wrapper.setProps({ open: true });
        await wrapper.vm.$nextTick();

        const select = document.body.querySelector('select');
        expect(select.value).toBe('ent-internal');
        wrapper.unmount();
    });

    it('mostra badge "Sua empresa" ao selecionar entity interna', async () => {
        // Monta fechado e abre depois para que o watch(open) dispare e
        // aplique o presetEntityId — simula o fluxo real de "abrir modal".
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

        // Badge só aparece em entity interna
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

    it('emite submit com payload completo quando form é válido', async () => {
        const wrapper = mountModal();

        document.body.querySelector('select').value = 'ent-client-a';
        document.body.querySelector('select').dispatchEvent(new Event('change'));

        const textarea = document.body.querySelector('textarea');
        textarea.value = 'motivo válido com mais de 10 caracteres';
        textarea.dispatchEvent(new Event('input'));

        await wrapper.vm.$nextTick();

        document.body.querySelector('form').dispatchEvent(new Event('submit'));
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('submit')).toBeTruthy();
        const payload = wrapper.emitted('submit')[0][0];
        expect(payload.entity_id).toBe('ent-client-a');
        expect(payload.credits).toBe(100);                              // default
        expect(payload.reason).toBe('motivo válido com mais de 10 caracteres');
        expect(payload.package_code).toBe('manual');                    // default
        wrapper.unmount();
    });

    it('reseta o form quando reabre (watch open: false→true)', async () => {
        const wrapper = mountModal();

        const textarea = document.body.querySelector('textarea');
        textarea.value = 'algum texto antigo';
        textarea.dispatchEvent(new Event('input'));
        await wrapper.vm.$nextTick();

        // Fecha e reabre
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
