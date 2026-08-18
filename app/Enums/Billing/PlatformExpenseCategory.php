<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * Categorias de despesa OPERACIONAL do próprio EasyEye (SaaS), não da clínica.
 *
 * Duas categorias de custo do P&L interno NÃO usam este enum porque já têm
 * dado real automático em outra tabela — lançá-las aqui duplicaria/divergiria
 * da fonte de verdade:
 *  - IA: soma de `ai_run_provider_calls.raw_cost_usd` (custo real pago aos
 *    provedores — ver App\Domains\AI\Services\AiProviderCostService).
 *  - Taxas de gateway: soma de `payments.gateway_fee` (cobrado pelo Asaas/
 *    Stripe/etc a cada pagamento recebido).
 *
 * As categorias abaixo são as que NÃO têm integração automática — lançamento
 * manual pelo dono/admin (ex.: fatura da AWS, folha de pagamento, campanha
 * de marketing, guia de imposto).
 */
enum PlatformExpenseCategory: string
{
    case Servers      = 'servers';
    case Integrations = 'integrations';
    case Payroll      = 'payroll';
    case Marketing    = 'marketing';
    case Taxes        = 'taxes';
    case Other        = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Servers      => 'Servidores e infraestrutura',
            self::Integrations => 'Integrações',
            self::Payroll      => 'Funcionários e prestadores',
            self::Marketing    => 'Marketing',
            self::Taxes        => 'Impostos',
            self::Other        => 'Outros custos',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
