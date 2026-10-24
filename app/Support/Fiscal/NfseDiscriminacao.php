<?php

declare(strict_types=1);

namespace App\Support\Fiscal;

use App\Enums\BillingCycle;
use App\Models\Billing\Invoice;
use App\Support\Money;
use Illuminate\Support\Facades\App;

/**
 * Texto da discriminação do serviço (municipal) / descrição do serviço (nacional) a partir do modelo configurado no
 * Manager. Placeholders: {plano}, {ciclo}, {periodo}, {fatura}, {valor} (R$ da fatura), {cliente}. {creditos} é legado: não é mais
 * preenchido (o pacote de IA é vendido em reais) e some do texto — revise modelos customizados no manager.
 *
 * Placeholder sem valor some, e o que ele deixaria torto também: parênteses vazios e espaços repetidos. Placeholder que
 * não existe vira vazio (um erro de digitação no modelo nunca vaza `{xyz}` para a nota fiscal). O resultado respeita o
 * limite de 1000 caracteres do padrão nacional.
 */
final class NfseDiscriminacao
{
    public const MAX_LENGTH = 1000;

    public const PLACEHOLDERS = ['plano', 'ciclo', 'periodo', 'fatura', 'valor', 'cliente', 'creditos'];

    /**
     * Texto da nota de uma fatura: modelo do pacote de IA ou da assinatura, preenchido com os dados dela. Sempre em
     * português (documento fiscal brasileiro), qualquer que seja o idioma de quem pediu a emissão.
     */
    public static function forInvoice(Invoice $invoice, NfseSettings $settings): string
    {
        $previous = App::getLocale();

        App::setLocale('pt_BR');

        try {
            $invoice->loadMissing(['plan', 'subscription.plan', 'entity']);

            $cycle = data_get($invoice->metadata, 'plan_change.billing_cycle');
            $cycle = is_string($cycle) ? BillingCycle::tryFrom($cycle) : ($invoice->subscription?->effectiveCycle() ?? $invoice->plan?->billing_cycle);

            $period = $invoice->period_start !== null && $invoice->period_end !== null
                ? $invoice->period_start->format('d/m/Y') . ' a ' . $invoice->period_end->format('d/m/Y')
                : ($invoice->paid_at ?? $invoice->created_at)?->format('d/m/Y');

            return self::render(
                $settings->discriminacao($invoice->isAiCreditPack() ? 'ia' : 'plano'),
                [
                    'plano'   => $invoice->plan?->name ?? $invoice->subscription?->plan?->name,
                    'ciclo'   => $cycle?->label(),
                    'periodo' => $period,
                    'fatura'  => $invoice->reference,
                    'valor'   => Money::formatBrl((int) round(((float) $invoice->amount) * 100)),
                    'cliente' => $invoice->entity?->name,
                ],
                // O teto do municipal varia por prefeitura (config); o builder nacional corta nos 1000 da DPS.
                max(self::MAX_LENGTH, (int) config('fiscal.limits.discriminacao', 2000)),
            );
        } finally {
            App::setLocale($previous);
        }
    }

    /**
     * @param array<string, scalar|null> $values
     */
    public static function render(string $template, array $values, int $max = self::MAX_LENGTH): string
    {
        $text = preg_replace_callback(
            '/\{([a-z_]+)\}/',
            static fn (array $match) => trim((string) ($values[$match[1]] ?? '')),
            $template,
        ) ?? $template;

        $text = preg_replace('/\(\s*\)/u', '', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return mb_substr(trim($text), 0, max(1, $max));
    }
}
