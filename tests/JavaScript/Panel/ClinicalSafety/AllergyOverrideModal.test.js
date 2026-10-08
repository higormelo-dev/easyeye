import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import AllergyOverrideModal from '@/Components/Panel/ClinicalSafety/AllergyOverrideModal.vue';

/** Receita × alergia (Fase 2 / A4): mostra alergia e reação; seguir exige motivo. */
const CONFLICTS = [
    {
        allergy_id: 'a1',
        substance: 'Dipirona',
        reaction: 'Edema de glote',
        severity_label: 'Grave / anafilaxia',
        status_label: 'Confirmada pelo médico',
        match: 'text',
        matched: 'dipirona',
        class: null,
    },
    {
        allergy_id: 'a1',
        substance: 'Dipirona',
        reaction: 'Edema de glote',
        match: 'brand',
        matched: 'NOVALGINA',
        class: null,
    },
    {
        allergy_id: 'a2',
        substance: 'Penicilina',
        reaction: 'Urticária',
        match: 'class',
        matched: 'AMOXIL',
        class: 'Penicilinas / beta-lactâmicos',
    },
];

describe('AllergyOverrideModal', () => {
    it('lista cada alergia uma vez, com reação e o que bateu na receita', () => {
        const w = mount(AllergyOverrideModal, {
            props: { open: true, conflicts: CONFLICTS },
            global: { stubs: { teleport: true } },
        });
        const items = w.findAll('[data-testid="allergy-override-conflicts"] > li');

        expect(w.find('[role="alertdialog"]').exists()).toBe(true);
        expect(items).toHaveLength(2);
        expect(items[0].text()).toContain('Edema de glote');
        expect(items[0].text()).toContain('dipirona (citado no texto da receita); NOVALGINA (nome comercial no texto)');
        expect(items[1].text()).toContain('AMOXIL (mesma classe: Penicilinas / beta-lactâmicos)');
    });

    it('"Prescrever mesmo assim" só com motivo (mín. 5 caracteres) e devolve o motivo', async () => {
        const w = mount(AllergyOverrideModal, {
            props: { open: true, conflicts: CONFLICTS },
            global: { stubs: { teleport: true } },
        });
        const confirm = () => w.find('[data-testid="allergy-override-confirm"]');

        expect(confirm().attributes('disabled')).toBeDefined();
        await w.find('[data-testid="allergy-override-reason"]').setValue('ok');
        expect(confirm().attributes('disabled')).toBeDefined();
        await w.find('[data-testid="allergy-override-reason"]').setValue('  Teste de provocação negativo  ');
        expect(confirm().attributes('disabled')).toBeUndefined();
        await confirm().trigger('click');

        expect(w.emitted('confirm')).toEqual([['Teste de provocação negativo']]);
    });

    it('voltar não segue', async () => {
        const w = mount(AllergyOverrideModal, {
            props: { open: true, conflicts: CONFLICTS },
            global: { stubs: { teleport: true } },
        });

        await w.find('[data-testid="allergy-override-cancel"]').trigger('click');
        expect(w.emitted('cancel')).toHaveLength(1);
        expect(w.emitted('confirm')).toBeUndefined();
    });
});
