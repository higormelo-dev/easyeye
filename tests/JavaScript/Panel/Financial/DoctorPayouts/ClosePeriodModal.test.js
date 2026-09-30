import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
import ClosePeriodModal from '@/Pages/Panel/Financial/DoctorPayouts/ClosePeriodModal.vue';
import { t, doctors, brl } from './fixtures.js';

/**
 * Confirmação do fechamento: mostra a prévia do servidor (centavos → reais),
 * os alertas com contagem e envia a prévia CONFERIDA (expected_*) + notas;
 * erros do servidor aparecem dentro do modal.
 */

// useForm reativo (o do setup.js não re-renderiza nem tem clearErrors/data()).
const forms = vi.hoisted(() => []);
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');

    return {
        usePage: () => ({ props: { locale: 'pt_BR' } }),
        useForm: (data) => {
            const fields = Object.keys(data);
            const form = reactive({
                ...data,
                errors: {},
                processing: false,
                data: () => Object.fromEntries(fields.map((field) => [field, form[field]])),
                clearErrors: () => { form.errors = {}; },
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
        template: `<div v-if="open" class="modal-stub"><header><slot name="header" /></header><slot /><footer><slot name="footer" /></footer>
            <button type="button" class="modal-stub-backdrop" @click="$emit('close')"></button></div>`,
    },
}));

const preview = {
    period_start: '2026-09-01', period_end: '2026-09-27', count: 12, charged_cents: 123450, payout_cents: 61725,
    blocking: 0, warnings: { doctor_mismatch: 2, late_item: 1 }, can_close: true,
};

let wrapper;

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    forms.length = 0;
});

async function mountModal(props = {}) {
    wrapper = mount(ClosePeriodModal, {
        props: { open: false, preview, doctorId: 'd1', doctor: doctors[0], action: '/doctor-payouts/closings', t, ...props },
        attachTo: document.body,
    });
    await wrapper.setProps({ open: true });
    await flushPromises();

    return wrapper;
}

describe('Financial/DoctorPayouts/ClosePeriodModal', () => {
    it('resumo da prévia: médico, período, itens e valores em reais (centavos do servidor)', async () => {
        const w = await mountModal();

        expect(w.find('header').text()).toContain('Close the period payout');
        expect(w.text()).toContain('Pending items are frozen.');
        expect(w.find('[data-test="close-doctor"]').text()).toBe('Dra. Ana Lima');
        expect(w.find('[data-test="close-period"]').text()).toBe('01/09/2026 – 27/09/2026');
        expect(w.find('[data-test="close-count"]').text()).toBe('12');
        expect(w.find('[data-test="close-charged"]').text()).toBe(brl(1234.5));
        expect(w.find('[data-test="close-payout"]').text()).toBe(brl(617.25));
    });

    it('alertas para conferir: rótulo, quantidade e explicação de cada um', async () => {
        const w = await mountModal();

        const warnings = w.find('[data-test="close-warnings"]');
        expect(warnings.text()).toContain('Warnings to check');

        const mismatch = warnings.find('[data-warning="doctor_mismatch"]');
        expect(mismatch.text()).toContain('Record by another doctor');
        expect(mismatch.text()).toContain('2');
        expect(mismatch.text()).toContain('Fix the appointment doctor.');
        expect(warnings.find('[data-warning="late_item"]').text()).toContain('1');
    });

    it('sem alertas: o quadro não aparece', async () => {
        const w = await mountModal({ preview: { ...preview, warnings: {} } });

        expect(w.find('[data-test="close-warnings"]').exists()).toBe(false);
    });

    it('confirmar envia a prévia conferida + observações para a rota de fechamento', async () => {
        const w = await mountModal();

        await w.find('[data-test="close-notes"]').setValue('Conferido com a secretária');
        await w.find('[data-test="close-confirm"]').trigger('click');

        const form = forms.at(-1);
        expect(form.post).toHaveBeenCalledWith('/doctor-payouts/closings', expect.objectContaining({ preserveScroll: true }));
        expect(form.data()).toEqual({
            doctor_id:             'd1',
            period_start:          '2026-09-01',
            period_end:            '2026-09-27',
            expected_count:         12,
            expected_charged_cents: 123450,
            expected_payout_cents:  61725,
            notes:                  'Conferido com a secretária',
        });
    });

    it('sucesso fecha o modal (o servidor redireciona ao demonstrativo)', async () => {
        const w = await mountModal();
        forms.at(-1).post.mockImplementation((url, options) => options.onSuccess?.());

        await w.find('[data-test="close-confirm"]').trigger('click');

        expect(w.emitted('close')).toHaveLength(1);
    });

    it('erros do servidor (período/médico) aparecem dentro do modal', async () => {
        const w = await mountModal();
        const form = forms.at(-1);

        form.errors = {
            period_start: 'The calculation changed while you were checking it.',
            doctor_id:    'Select the doctor.',
            notes:        'Too long.',
        };
        await nextTick();

        const error = w.find('[data-test="close-error"]');
        expect(error.attributes('role')).toBe('alert');
        expect(error.text()).toContain('The calculation changed while you were checking it.');
        expect(error.text()).toContain('Select the doctor.');
        expect(error.text()).not.toContain('Too long.');
        expect(w.find('[data-test="close-notes"]').classes()).toContain('is-invalid');
        expect(w.text()).toContain('Too long.');
    });

    it('reabrir o modal limpa erros antigos e recarrega a prévia atual', async () => {
        const w = await mountModal();
        const form = forms.at(-1);
        form.errors = { period_start: 'Old error.' };

        await w.setProps({ open: false });
        await w.setProps({ open: true, preview: { ...preview, count: 13, charged_cents: 140000, payout_cents: 70000 } });
        await flushPromises();

        expect(w.find('[data-test="close-error"]').exists()).toBe(false);
        expect(form.expected_count).toBe(13);
        expect(form.expected_charged_cents).toBe(140000);
        expect(form.expected_payout_cents).toBe(70000);
    });

    it('foco inicial em "Cancelar"; Esc e Cancelar fecham sem enviar', async () => {
        const w = await mountModal();

        expect(document.activeElement?.textContent?.trim()).toBe('Cancel');

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(w.emitted('close')).toHaveLength(1);

        await w.findAll('footer button')[0].trigger('click');
        expect(w.emitted('close')).toHaveLength(2);
        expect(forms.at(-1).post).not.toHaveBeenCalled();
    });

    it('enviando: botões desabilitados e Esc não fecha', async () => {
        const w = await mountModal();
        forms.at(-1).processing = true;
        await nextTick();

        expect(w.find('[data-test="close-confirm"]').attributes('disabled')).toBeDefined();
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(w.emitted('close')).toBeUndefined();
    });

    it('observações avisam que aparecem no demonstrativo do médico', async () => {
        const w = await mountModal();

        const notes = w.find('[data-test="close-notes"]');
        expect(w.find('[data-test="close-notes-hint"]').text()).toBe('Shown on the doctor statement.');
        expect(notes.attributes('aria-describedby')).toBe(w.find('[data-test="close-notes-hint"]').attributes('id'));
    });
});
