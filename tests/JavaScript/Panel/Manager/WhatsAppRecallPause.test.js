import { describe, it, expect, vi, beforeEach } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

/**
 * Manager → WhatsApp: a pausa GLOBAL dos convites de retorno (kill-switch do EasyEye, Fase 6). Motivo obrigatório (10+
 * caracteres), impacto à vista ("N clínicas e M retornos afetados") antes de confirmar e retomada com confirmação.
 */
vi.mock('@inertiajs/vue3', async () => (await import('./RecallProtocols/support/inertiaMock.js')).buildInertiaMock());

import { router, resetInertiaMock } from './RecallProtocols/support/inertiaMock.js';
import WhatsAppRecallPause from '@/Pages/Panel/Manager/WhatsApp/WhatsAppRecallPause.vue';
import whatsappT from '../Recall/support/whatsapp.json';

const routes = { recall_pause: '/manager/whatsapp/recall/pause', recall_resume: '/manager/whatsapp/recall/resume' };
const global = { stubs: { Teleport: true } };

const mountCard = (recall = {}) =>
    mount(WhatsAppRecallPause, {
        props: {
            recall: { paused: null, impact: { clinics: 3, recalls: 120 }, ...recall },
            routes,
            t: whatsappT,
        },
        global,
    });

describe('Pausa global dos convites de retorno', () => {
    beforeEach(() => {
        resetInertiaMock();
        window.axios = { post: vi.fn(() => Promise.resolve({ data: {} })) };
    });

    it('ativo: mostra o impacto e oferece pausar', () => {
        const wrapper = mountCard();

        expect(wrapper.find('[data-test="recall-impact"]').text()).toBe('3 clínicas e 120 retornos afetados');
        expect(wrapper.find('[data-test="recall-pause-open"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="recall-resume-open"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="recall-paused-badge"]').exists()).toBe(false);
    });

    it('pausar: o motivo precisa de 10 caracteres, o impacto aparece na confirmação e o envio leva o motivo', async () => {
        const wrapper = mountCard();

        await wrapper.find('[data-test="recall-pause-open"]').trigger('click');

        expect(wrapper.find('[data-test="recall-dialog-impact"]').text()).toContain(
            '3 clínicas e 120 retornos afetados',
        );

        const confirm = wrapper.find('[data-test="recall-confirm"]');
        expect(confirm.attributes('disabled')).toBeDefined();

        await wrapper.find('#wa-recall-reason').setValue('curto');
        expect(confirm.attributes('disabled')).toBeDefined();

        await wrapper.find('#wa-recall-reason').setValue('  Número queimado na Meta  ');
        expect(confirm.attributes('disabled')).toBeUndefined();

        await wrapper.find('#wa-recall-form').trigger('submit');
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledWith(
            routes.recall_pause,
            { reason: 'Número queimado na Meta' },
            expect.objectContaining({ headers: expect.any(Object) }),
        );
        expect(router.reload).toHaveBeenCalledWith(expect.objectContaining({ only: ['recall'] }));
        expect(wrapper.find('#wa-recall-form').exists()).toBe(false);
    });

    it('erro de validação do servidor aparece no campo e o modal continua aberto', async () => {
        window.axios.post = vi.fn(() =>
            Promise.reject({ response: { data: { errors: { reason: ['O motivo é obrigatório.'] } } } }),
        );
        const wrapper = mountCard();

        await wrapper.find('[data-test="recall-pause-open"]').trigger('click');
        await wrapper.find('#wa-recall-reason').setValue('Número queimado na Meta');
        await wrapper.find('#wa-recall-form').trigger('submit');
        await flushPromises();

        expect(wrapper.find('#wa-recall-form [role="alert"]').text()).toContain('O motivo é obrigatório.');
        expect(router.reload).not.toHaveBeenCalled();
    });

    it('pausado: mostra quem, quando e por quê, e oferece liberar (sem pedir motivo)', async () => {
        const wrapper = mountCard({
            paused: { reason: 'Modelo reprovado pela Meta', by_name: 'Dono do SaaS', at: '2026-02-01T10:00:00-03:00' },
        });

        expect(wrapper.find('[data-test="recall-paused-badge"]').text()).toBe('Pausado');
        expect(wrapper.find('[data-test="recall-paused-info"]').text()).toContain('Pausado por Dono do SaaS');
        expect(wrapper.find('[data-test="recall-paused-info"]').text()).toContain('Motivo: Modelo reprovado pela Meta');
        expect(wrapper.find('[data-test="recall-pause-open"]').exists()).toBe(false);

        await wrapper.find('[data-test="recall-resume-open"]').trigger('click');

        expect(wrapper.find('#wa-recall-reason').exists()).toBe(false);
        expect(wrapper.find('[data-test="recall-confirm"]').attributes('disabled')).toBeUndefined();

        await wrapper.find('#wa-recall-form').trigger('submit');
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledWith(routes.recall_resume, {}, expect.any(Object));
    });

    it('cancelar fecha sem enviar', async () => {
        const wrapper = mountCard();

        await wrapper.find('[data-test="recall-pause-open"]').trigger('click');
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Cancelar')
            .trigger('click');

        expect(wrapper.find('#wa-recall-form').exists()).toBe(false);
        expect(window.axios.post).not.toHaveBeenCalled();
    });
});
