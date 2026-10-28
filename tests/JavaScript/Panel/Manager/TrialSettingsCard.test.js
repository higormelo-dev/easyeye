import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import TrialSettingsCard from '@/Pages/Panel/Manager/Plans/TrialSettingsCard.vue';

/**
 * Manager → Planos: dias de trial das empresas novas (sem dias de graça).
 */
const t = {
    trial_title: 'Período de teste (trial)',
    trial_days: 'Dias de trial',
    trial_days_unit: 'dia|dias',
    trial_hint: 'Quando o trial acaba, o acesso é bloqueado.',
    trial_save: 'Salvar',
    trial_saved: 'Dias de trial atualizados.',
    trial_save_failed: 'Não foi possível salvar.',
};

let wrapper;

beforeEach(() => {
    window.axios = { put: vi.fn() };
    window.showSuccessToast = vi.fn();
});

afterEach(() => {
    wrapper?.unmount();
    delete window.showSuccessToast;
});

function render(trialDays = 7) {
    wrapper = mount(TrialSettingsCard, { props: { trialDays, t } });

    return wrapper;
}

const saveButton = () => wrapper.get('button[type="submit"]');

describe('TrialSettingsCard', () => {
    it('mostra os dias atuais, a unidade no plural e a regra de bloqueio', () => {
        render(7);

        expect(wrapper.get('#trial-days').element.value).toBe('7');
        expect(wrapper.get('.input-group-text').text()).toBe('dias');
        expect(wrapper.get('#trial-days-hint').text()).toContain('o acesso é bloqueado');
        // Nada mudou: não há o que salvar.
        expect(saveButton().attributes('disabled')).toBeDefined();
    });

    it('salva os novos dias e avisa', async () => {
        window.axios.put.mockResolvedValue({
            data: { message: 'Dias de trial atualizados.', data: { trial_days: 14 } },
        });
        render(7);

        await wrapper.get('#trial-days').setValue('14');
        expect(saveButton().attributes('disabled')).toBeUndefined();

        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(window.axios.put).toHaveBeenCalledWith(
            '/_routes/manager.plans.trial-settings',
            { trial_days: 14 },
            { headers: { Accept: 'application/json' } },
        );
        expect(window.showSuccessToast).toHaveBeenCalledWith('Dias de trial atualizados.');
        // Salvo vira o novo valor de referência.
        expect(saveButton().attributes('disabled')).toBeDefined();
    });

    it('não envia valor fora de 1 a 365', async () => {
        render(7);

        await wrapper.get('#trial-days').setValue('0');
        expect(saveButton().attributes('disabled')).toBeDefined();
        expect(wrapper.get('#trial-days').classes()).toContain('is-invalid');

        await wrapper.get('form').trigger('submit');
        expect(window.axios.put).not.toHaveBeenCalled();
    });

    it('mostra o erro de validação do servidor no campo', async () => {
        window.axios.put.mockRejectedValue({
            response: {
                status: 422,
                data: { errors: { trial_days: ['O campo dias de trial deve ser no máximo 365.'] } },
            },
        });
        render(7);

        await wrapper.get('#trial-days').setValue('30');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toBe('O campo dias de trial deve ser no máximo 365.');
        expect(window.showSuccessToast).not.toHaveBeenCalled();
    });

    it('um dia usa a unidade no singular', async () => {
        render(7);

        await wrapper.get('#trial-days').setValue('1');

        expect(wrapper.get('.input-group-text').text()).toBe('dia');
    });
});
