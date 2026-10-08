import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import ClinicalAlertBanner from '@/Components/Panel/ClinicalSafety/ClinicalAlertBanner.vue';
import AllergyBadge from '@/Components/Panel/ClinicalSafety/AllergyBadge.vue';
import { summary } from './fixtures.js';

/**
 * Alerta fixo (Fase 2 / A1-A2): alergia com texto (não só cor), estado
 * "nega"/"sem registro" nunca em branco, alertas de risco com a fonte.
 */
describe('ClinicalAlertBanner', () => {
    it('alergia ativa: "ALERGIA:" + substância, reação, gravidade e "não confirmada"; descartada fica fora', () => {
        const w = mount(ClinicalAlertBanner, { props: { summary: summary() } });
        const alert = w.find('[data-testid="cs-allergy-alert"]');

        expect(alert.attributes('role')).toBe('alert');
        expect(alert.text()).toContain('ALERGIA:');
        expect(alert.text()).toContain('Dipirona');
        expect(alert.text()).toContain('Edema de glote · Grave / anafilaxia');
        expect(alert.text()).toContain('Penicilina');
        expect(alert.text()).toContain('não confirmada');
        expect(alert.text()).not.toContain('Camarão');
        expect(alert.find('.ti-alert-triangle').attributes('aria-hidden')).toBe('true');
    });

    it('ninguém perguntou: "Sem registro de alergias" (não some)', () => {
        const w = mount(ClinicalAlertBanner, {
            props: { summary: summary({ allergy_state: 'unknown', allergies: [], risk_alerts: [] }) },
        });

        expect(w.find('[data-testid="cs-allergy-unknown"]').text()).toContain('Sem registro de alergias');
        expect(w.find('[data-testid="cs-allergy-alert"]').exists()).toBe(false);
    });

    it('nega alergias: quem e quando', () => {
        const w = mount(ClinicalAlertBanner, {
            props: {
                summary: summary({
                    allergy_state: 'denied',
                    allergies: [],
                    denied: { by: 'SOFIA RECEPÇÃO', at_label: '01/10/2026 08:00' },
                }),
            },
        });

        expect(w.find('[data-testid="cs-allergy-denied"]').text()).toBe(
            'Nega alergias — registrado por SOFIA RECEPÇÃO em 01/10/2026 08:00',
        );
    });

    it('alertas de risco das medicações com a fonte sob demanda; sem alerta, nada', async () => {
        const w = mount(ClinicalAlertBanner, { props: { summary: summary() } });
        const risks = w.find('[data-testid="cs-risk-alerts"]');

        expect(risks.text()).toContain('Alfa-1 bloqueadores');
        expect(risks.text()).toContain('SECOTEX 0,4 MG');
        expect(risks.text()).toContain('IFIS');
        expect(risks.text()).not.toContain('Chang DF');

        const toggle = risks.find('button');
        expect(toggle.attributes('aria-expanded')).toBe('false');
        await toggle.trigger('click');
        expect(w.find('[data-testid="cs-risk-alerts"]').text()).toContain('Chang DF');

        const none = mount(ClinicalAlertBanner, { props: { summary: summary({ risk_alerts: [] }) } });
        expect(none.find('[data-testid="cs-risk-alerts"]').exists()).toBe(false);

        const hidden = mount(ClinicalAlertBanner, { props: { summary: summary(), showRisks: false } });
        expect(hidden.find('[data-testid="cs-risk-alerts"]').exists()).toBe(false);
    });

    it('"Revisar" só quando pode revisar e emite o evento', async () => {
        expect(
            mount(ClinicalAlertBanner, { props: { summary: summary() } })
                .find('[data-testid="cs-review"]')
                .exists(),
        ).toBe(false);

        const w = mount(ClinicalAlertBanner, { props: { summary: summary(), reviewable: true } });
        await w.find('[data-testid="cs-review"]').trigger('click');
        expect(w.emitted('review')).toHaveLength(1);
    });

    it('usa os textos do idioma da tela (t)', () => {
        const w = mount(ClinicalAlertBanner, {
            props: {
                summary: summary({ allergy_state: 'unknown', allergies: [], risk_alerts: [] }),
                t: { banner: { no_record: 'No allergy record' } },
            },
        });

        expect(w.text()).toContain('No allergy record');
    });
});

describe('AllergyBadge (listas da Agenda / pronto atendimento)', () => {
    it('texto em cada estado; gravidade marcada na própria alergia', () => {
        const has = mount(AllergyBadge, {
            props: {
                allergy: {
                    state: 'has',
                    items: [
                        { substance: 'Dipirona', severe: true },
                        { substance: 'Penicilina', severe: false },
                    ],
                    severe: true,
                },
            },
        });
        expect(has.text()).toBe('ALERGIA: Dipirona (grave), Penicilina');
        expect(has.classes()).toContain('cs-allergy-badge--has');

        expect(mount(AllergyBadge, { props: { allergy: { state: 'denied', items: [] } } }).text()).toBe(
            'Nega alergias',
        );
        expect(mount(AllergyBadge, { props: { allergy: { state: 'unknown', items: [] } } }).text()).toBe(
            'Sem registro de alergias',
        );
        expect(mount(AllergyBadge, { props: { allergy: null } }).html()).toBe('<!--v-if-->');
    });
});
