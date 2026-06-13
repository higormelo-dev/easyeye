import { describe, it, expect, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import ProviderCostsCard from '@/Pages/Panel/Manager/AiCreditPurchases/ProviderCostsCard.vue';

/**
 * Testes do card de custo do EasyEye nos provedores (lado supplier).
 *
 * Cobre:
 *   - Renderização condicional via prop `costs`
 *   - Resumo agregado (MTD, 7d, ontem, forecast)
 *   - Cards individuais por provedor
 *   - Saldo estimado (has_topups true/false)
 *   - Indicador de alerta (ok/warning/critical/exhausted)
 *   - Classe pulse-danger em alerta crítico/esgotado
 *   - Botão "+" só renderiza se permission create_topup
 *   - Emit add-topup com chave do provedor
 *   - Checklist colapsável
 *   - Margem bruta com cor por % (success/warning/danger)
 *   - Formatação USD
 */
describe('ProviderCostsCard', () => {
    const baseCosts = {
        summary: {
            month_to_date_usd:  125.50,
            last_7d_usd:        40.20,
            yesterday_usd:      5.10,
            month_forecast_usd: 250.00,
            total_calls_mtd:    1200,
        },
        by_provider: {
            openai: {
                label:                 'ChatGPT',
                month_to_date_usd:     80.00,
                last_7d_usd:           28.00,
                yesterday_usd:         3.50,
                month_forecast_usd:    160.00,
                calls_mtd:             800,
                avg_cost_per_call_usd: 0.10,
                models_breakdown: [
                    { model: 'gpt-5-mini', calls: 600, cost_usd: 60.00 },
                    { model: 'gpt-5',       calls: 200, cost_usd: 20.00 },
                ],
                estimated_balance: {
                    has_topups:                     true,
                    total_topped_up_usd:            200.00,
                    consumed_since_first_topup_usd: 80.00,
                    remaining_usd:                  120.00,
                    daily_burn_usd:                 4.00,
                    days_remaining:                 30,
                    alert_level:                    'ok',
                    first_topup_at:                 '2026-05-01 10:00:00',
                },
                recent_topups: [
                    { id: 't1', amount_usd: 100, topped_up_at: '2026-05-15T10:00:00Z', reference: 'ch_1', note: null, created_by_name: 'Higor' },
                    { id: 't2', amount_usd: 100, topped_up_at: '2026-05-01T10:00:00Z', reference: null, note: null, created_by_name: 'Higor' },
                ],
            },
            anthropic: {
                label:                 'Claude',
                month_to_date_usd:     30.00,
                last_7d_usd:           10.00,
                yesterday_usd:         1.00,
                month_forecast_usd:    60.00,
                calls_mtd:             200,
                avg_cost_per_call_usd: 0.15,
                models_breakdown:      [],
                estimated_balance: {
                    has_topups:                     true,
                    total_topped_up_usd:            50.00,
                    consumed_since_first_topup_usd: 30.00,
                    remaining_usd:                  20.00,
                    daily_burn_usd:                 1.42,
                    days_remaining:                 14,
                    alert_level:                    'warning',
                    first_topup_at:                 '2026-05-10 10:00:00',
                },
                recent_topups: [],
            },
            gemini: {
                label:                 'Gemini',
                month_to_date_usd:     15.50,
                last_7d_usd:           2.20,
                yesterday_usd:         0.60,
                month_forecast_usd:    30.00,
                calls_mtd:             200,
                avg_cost_per_call_usd: 0.077,
                models_breakdown:      [],
                estimated_balance: {
                    has_topups:                     false,
                    total_topped_up_usd:            0,
                    consumed_since_first_topup_usd: 0,
                    remaining_usd:                  null,
                    daily_burn_usd:                 0.31,
                    days_remaining:                 null,
                    alert_level:                    'unknown',
                    first_topup_at:                 null,
                },
                recent_topups: [],
            },
        },
        margin: {
            credits_consumed_mtd:  10000,
            revenue_estimate_usd:  100.00,
            cost_mtd_usd:          125.50,
            gross_margin_usd:      -25.50,
            gross_margin_pct:      -25.5,
            margin_multiplier:     2.0,
        },
    };

    const tStubs = {
        provider_costs: {
            title:              'Custos',
            subtitle:           'sub',
            alerts_setup:       'Alertas externos',
            mtd:                'Mês',
            last_7d:            '7 dias',
            yesterday:          'Ontem',
            forecast:           'Forecast',
            forecast_help:      'extrap.',
            estimated_balance:  'Saldo estimado',
            days_left:          'dias',
            no_topups:          'Registre uma recarga',
            recent_topups:      'Últimas recargas',
            margin: {
                title:   'Margem (mês)',
                revenue: 'Receita',
                cost:    'Custo',
                gross:   'Lucro',
            },
            checklist: {
                title:    'Configure alertas',
                subtitle: 'sub',
            },
        },
        topup: { add: 'Registrar recarga' },
    };

    function mountCard(propsOverride = {}) {
        return mount(ProviderCostsCard, {
            props: {
                costs:       baseCosts,
                permissions: { create_topup: true },
                t:           tStubs,
                ...propsOverride,
            },
        });
    }

    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('não renderiza quando costs=null', () => {
        const wrapper = mountCard({ costs: null });
        expect(wrapper.find('.provider-costs-card').exists()).toBe(false);
    });

    it('renderiza card com título', () => {
        const wrapper = mountCard();
        expect(wrapper.text()).toContain('Custos');
    });

    it('exibe resumo agregado (MTD + 7d + ontem + forecast)', () => {
        const wrapper = mountCard();
        const text = wrapper.text();

        // Todos os valores devem aparecer formatados como USD
        expect(text).toContain('$125.50');                              // MTD total
        expect(text).toContain('$40.20');                               // last_7d
        expect(text).toContain('$5.10');                                // yesterday
        expect(text).toContain('$250.00');                              // forecast
        expect(text).toContain('1.200');                                // total_calls_mtd (toLocaleString pt-BR usa ponto)
    });

    it('renderiza 3 cards por provedor (1 por provedor)', () => {
        const wrapper = mountCard();
        const tiles = wrapper.findAll('.provider-cost-tile');
        expect(tiles.length).toBe(3);
    });

    it('aplica classe pulse-danger em alerta critical', () => {
        const costsCritical = JSON.parse(JSON.stringify(baseCosts));
        costsCritical.by_provider.openai.estimated_balance.alert_level = 'critical';

        const wrapper = mountCard({ costs: costsCritical });
        const tiles = wrapper.findAll('.provider-cost-tile');

        // OpenAI é o primeiro
        expect(tiles[0].classes()).toContain('provider-cost-tile--danger');
        // Outros não
        expect(tiles[1].classes()).not.toContain('provider-cost-tile--danger');
    });

    it('aplica classe pulse-danger em alerta exhausted', () => {
        const costs = JSON.parse(JSON.stringify(baseCosts));
        costs.by_provider.anthropic.estimated_balance.alert_level = 'exhausted';

        const wrapper = mountCard({ costs });
        const tiles = wrapper.findAll('.provider-cost-tile');
        expect(tiles[1].classes()).toContain('provider-cost-tile--danger');
    });

    it('mostra "Registre uma recarga" quando provider sem topups', () => {
        const wrapper = mountCard();
        // Gemini não tem topups
        const tiles = wrapper.findAll('.provider-cost-tile');
        expect(tiles[2].text()).toContain('Registre uma recarga');
    });

    it('mostra saldo estimado quando provider tem topups', () => {
        const wrapper = mountCard();
        const tiles = wrapper.findAll('.provider-cost-tile');

        // OpenAI: $120.00 restantes, ~30 dias
        expect(tiles[0].text()).toContain('$120.00');
        expect(tiles[0].text()).toContain('30 dias');

        // Anthropic: $20.00, ~14 dias
        expect(tiles[1].text()).toContain('$20.00');
        expect(tiles[1].text()).toContain('14 dias');
    });

    it('aplica classe estimated-balance--success para alert_level=ok', () => {
        const wrapper = mountCard();
        const tiles = wrapper.findAll('.provider-cost-tile');
        const balanceBox = tiles[0].find('.estimated-balance');
        expect(balanceBox.classes()).toContain('estimated-balance--success');
    });

    it('aplica classe estimated-balance--warning para alert_level=warning', () => {
        const wrapper = mountCard();
        const tiles = wrapper.findAll('.provider-cost-tile');
        const balanceBox = tiles[1].find('.estimated-balance');
        expect(balanceBox.classes()).toContain('estimated-balance--warning');
    });

    it('aplica classe estimated-balance--secondary quando sem topups (alert=unknown)', () => {
        const wrapper = mountCard();
        const tiles = wrapper.findAll('.provider-cost-tile');
        const balanceBox = tiles[2].find('.estimated-balance');
        expect(balanceBox.classes()).toContain('estimated-balance--secondary');
    });

    it('mostra botão "+" em cada provider quando permission create_topup=true', () => {
        const wrapper = mountCard();
        const addButtons = wrapper.findAll('button[title*="recarga" i], button[title="Registrar recarga"]');
        expect(addButtons.length).toBe(3);                              // 1 por provedor
    });

    it('OCULTA botão "+" quando create_topup=false', () => {
        const wrapper = mountCard({ permissions: { create_topup: false } });
        const addButtons = wrapper.findAll('button[title="Registrar recarga"]');
        expect(addButtons.length).toBe(0);
    });

    it('emite add-topup com chave do provedor ao clicar no botão "+"', async () => {
        const wrapper = mountCard();
        const addButtons = wrapper.findAll('button[title="Registrar recarga"]');

        // Clica no 2º (anthropic)
        await addButtons[1].trigger('click');

        expect(wrapper.emitted('add-topup')).toBeTruthy();
        expect(wrapper.emitted('add-topup')[0]).toEqual(['anthropic']);
    });

    it('checklist está oculto por padrão', () => {
        const wrapper = mountCard();
        expect(wrapper.find('.alert.alert-info').exists()).toBe(false);
    });

    it('checklist aparece ao clicar em "Alertas externos"', async () => {
        const wrapper = mountCard();
        const toggleBtn = wrapper.findAll('button').find((b) => b.text().includes('Alertas externos'));
        await toggleBtn.trigger('click');
        expect(wrapper.find('.alert.alert-info').exists()).toBe(true);
        expect(wrapper.find('.alert.alert-info').text()).toContain('Configure alertas');
    });

    it('checklist contém links para os 3 painéis externos de billing', async () => {
        const wrapper = mountCard();
        await wrapper.findAll('button').find((b) => b.text().includes('Alertas externos')).trigger('click');

        const links = wrapper.find('.alert.alert-info').findAll('a');
        const hrefs = links.map((a) => a.attributes('href'));

        expect(hrefs.some((h) => h.includes('platform.openai.com'))).toBe(true);
        expect(hrefs.some((h) => h.includes('console.anthropic.com'))).toBe(true);
        expect(hrefs.some((h) => h.includes('console.cloud.google.com'))).toBe(true);
    });

    it('marginVariant=danger quando gross_margin_pct < 20', () => {
        const wrapper = mountCard();
        // Margem -25.5% → variant danger
        const strip = wrapper.find('.margin-strip');
        expect(strip.classes()).toContain('margin-strip--danger');
    });

    it('marginVariant=warning quando gross_margin_pct entre 20 e 40', () => {
        const costs = JSON.parse(JSON.stringify(baseCosts));
        costs.margin.gross_margin_pct = 25;

        const wrapper = mountCard({ costs });
        const strip = wrapper.find('.margin-strip');
        expect(strip.classes()).toContain('margin-strip--warning');
    });

    it('marginVariant=success quando gross_margin_pct >= 40', () => {
        const costs = JSON.parse(JSON.stringify(baseCosts));
        costs.margin.gross_margin_pct = 50;

        const wrapper = mountCard({ costs });
        const strip = wrapper.find('.margin-strip');
        expect(strip.classes()).toContain('margin-strip--success');
    });

    it('renderiza últimas recargas quando provider tem topups', () => {
        const wrapper = mountCard();
        const tiles = wrapper.findAll('.provider-cost-tile');

        // OpenAI tem 2 topups recentes
        expect(tiles[0].text()).toContain('Últimas recargas');
        expect(tiles[0].text()).toContain('$100.00');
    });

    it('exibe top 3 modelos quando models_breakdown populado', () => {
        const wrapper = mountCard();
        const openaiTile = wrapper.findAll('.provider-cost-tile')[0];

        expect(openaiTile.text()).toContain('gpt-5-mini');
        expect(openaiTile.text()).toContain('gpt-5');
        expect(openaiTile.text()).toContain('Top modelos');
    });
});
