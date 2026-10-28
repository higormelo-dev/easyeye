<?php

declare(strict_types=1);

namespace App\Domains\AI\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Conversão ÚNICA dos saldos em "créditos" (modelo antigo) para CENTAVOS de BRL (plano ai-credit-packs-brl §2.2).
 *
 * Regras (todas parametrizadas — o dono troca antes do deploy, em config/ai.php `legacy_conversion` ou nos argumentos do
 * comando `ai:convert-legacy-credits`):
 *   - saldo comprado: pelo VALOR MÉDIO PAGO POR CRÉDITO da própria clínica (soma do valor das compras pagas e creditadas
 *     dividido pela soma dos créditos delas); sem compra paga, `default_credit_cents` (R$ 2,00);
 *   - cota mensal: `quota_credit_cents` por crédito (1 crédito = R$ 1,00 → o plano de 30 vira R$ 30,00);
 *   - ledger, compras e execuções antigas ganham os valores em centavos (as colunas antigas ficam congeladas);
 *   - toda carteira convertida recebe `legacy_conversion` (fator, método e valores originais) — trilha de auditoria;
 *   - idempotente: carteira com `legacy_conversion` não é convertida de novo.
 * Arredondamento HALF_UP. Nada aqui chama serviço externo.
 */
final class AiLegacyCreditConverter
{
    private const QUOTA_KEY = 'ai.legacy_conversion.plan_features_cents';

    public function __construct(
        private readonly int $defaultCreditCents,
        private readonly int $quotaCreditCents,
        private readonly float $legacyMarkup,
        private readonly float $legacyRate,
    ) {
    }

    public static function fromConfig(?int $defaultCreditCents = null, ?int $quotaCreditCents = null): self
    {
        return new self(
            $defaultCreditCents ?? (int) config('ai.legacy_conversion.default_credit_cents', 200),
            $quotaCreditCents ?? (int) config('ai.legacy_conversion.quota_credit_cents', 100),
            (float) config('ai.legacy_conversion.legacy_markup', 2.0),
            (float) config('ai.legacy_conversion.legacy_fallback_rate', 5.50),
        );
    }

    /**
     * @return array{wallets: int, ledger_entries: int, purchases: int, runs: int, provider_calls: int, plan_features: int, dry_run: bool}
     */
    public function convertAll(bool $dryRun = false): array
    {
        $report = ['wallets' => 0, 'ledger_entries' => 0, 'purchases' => 0, 'runs' => 0, 'provider_calls' => 0, 'plan_features' => 0, 'dry_run' => $dryRun];

        DB::beginTransaction();

        try {
            DB::table('ai_credit_wallets')->whereNull('legacy_conversion')->orderBy('id')->get()
                ->each(function (object $wallet) use (&$report): void {
                    $this->convertWallet($wallet, $report);
                });

            // Execuções e compras de entidades sem carteira (raro): fator padrão.
            $report['runs'] += $this->convertRuns(null, (float) $this->defaultCreditCents);
            $report['purchases'] += $this->convertPurchases(null, (float) $this->defaultCreditCents);
            $report['provider_calls'] = $this->convertProviderCalls();
            $report['plan_features']  = $this->convertPlanFeatures();

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return $report;
    }

    /**
     * Confere a conversão: nenhum saldo negativo, toda carteira com trilha e `saldo + reservado` sem aumento em relação ao
     * original convertido. Lista vazia = conciliado.
     *
     * @return list<string>
     */
    public function reconcile(): array
    {
        $issues = [];

        foreach (DB::table('ai_credit_wallets')->orderBy('id')->get() as $wallet) {
            $id = (string) $wallet->entity_id;

            if ($wallet->legacy_conversion === null) {
                $issues[] = "carteira {$id} sem legacy_conversion";

                continue;
            }

            foreach (['balance_cents', 'reserved_balance_cents', 'monthly_quota_cents', 'monthly_quota_used_cents'] as $column) {
                if ((int) $wallet->{$column} < 0) {
                    $issues[] = "carteira {$id}: {$column} negativo";
                }
            }

            $meta = json_decode((string) $wallet->legacy_conversion, true);

            if (($meta['method'] ?? null) === 'native_brl') {
                continue; // nasceu em centavos: não há saldo legado para comparar
            }

            $max = (int) round(((int) data_get($meta, 'original.balance', 0) + (int) data_get($meta, 'original.reserved_balance', 0)) * (float) data_get($meta, 'factor_cents_per_credit', 0)) + 1;

            if ((int) $wallet->balance_cents + (int) $wallet->reserved_balance_cents > $max) {
                $issues[] = "carteira {$id}: saldo + reservado aumentou na conversão";
            }
        }

        return $issues;
    }

    /** @param array<string, int|bool> $report */
    private function convertWallet(object $wallet, array &$report): void
    {
        $entityId          = (string) $wallet->entity_id;
        [$factor, $method] = $this->purchaseFactor($entityId);
        $quotaC            = (float) $this->quotaCreditCents;
        $c                 = fn (int|string|null $credits): int => $this->half((float) $credits * $factor);
        $q                 = fn (int|string|null $credits): int => $this->half((float) $credits * $quotaC);

        $original = [];

        foreach (['balance', 'reserved_balance', 'lifetime_purchased', 'lifetime_consumed', 'monthly_quota', 'monthly_quota_used', 'monthly_quota_lifetime_granted', 'lifetime_consumed_openai', 'lifetime_consumed_anthropic', 'lifetime_consumed_gemini'] as $column) {
            $original[$column] = (int) $wallet->{$column};
        }

        DB::table('ai_credit_wallets')->where('id', $wallet->id)->update([
            'balance_cents'                        => $c($wallet->balance),
            'reserved_balance_cents'               => $c($wallet->reserved_balance),
            'lifetime_purchased_cents'             => $c($wallet->lifetime_purchased),
            'lifetime_consumed_cents'              => $c($wallet->lifetime_consumed),
            'lifetime_consumed_openai_cents'       => $c($wallet->lifetime_consumed_openai),
            'lifetime_consumed_anthropic_cents'    => $c($wallet->lifetime_consumed_anthropic),
            'lifetime_consumed_gemini_cents'       => $c($wallet->lifetime_consumed_gemini),
            'monthly_quota_cents'                  => $q($wallet->monthly_quota),
            'monthly_quota_used_cents'             => $q($wallet->monthly_quota_used),
            'monthly_quota_lifetime_granted_cents' => $q($wallet->monthly_quota_lifetime_granted),
            'legacy_conversion'                    => json_encode([
                'method'                  => $method,
                'factor_cents_per_credit' => $factor,
                'default_credit_cents'    => $this->defaultCreditCents,
                'quota_credit_cents'      => $this->quotaCreditCents,
                'original'                => $original,
                'converted_at'            => now()->toAtomString(),
            ], JSON_THROW_ON_ERROR),
        ]);

        $report['wallets']++;
        $report['ledger_entries'] += $this->convertLedger($wallet->id, $factor, $quotaC);
        $report['purchases'] += $this->convertPurchases($entityId, $factor);
        $report['runs'] += $this->convertRuns($entityId, $factor);
    }

    /** @return array{0: float, 1: string} */
    private function purchaseFactor(string $entityId): array
    {
        $row = DB::table('ai_credit_purchases')
            ->where('entity_id', $entityId)
            ->where('status', 'credited')
            ->where('amount_cents', '>', 0)
            ->where('credits', '>', 0)
            ->selectRaw('SUM(amount_cents) as paid, SUM(credits) as credits')
            ->first();

        if ($row !== null && (int) $row->credits > 0 && (int) $row->paid > 0) {
            return [(float) $row->paid / (float) $row->credits, 'purchase_average'];
        }

        return [(float) $this->defaultCreditCents, 'default'];
    }

    private function convertLedger(string $walletId, float $factor, float $quotaC): int
    {
        $count = 0;

        DB::table('ai_credit_ledger_entries')->where('wallet_id', $walletId)->whereNull('amount_cents')->orderBy('created_at')->get()
            ->each(function (object $entry) use ($factor, $quotaC, &$count): void {
                $meta    = $this->decodeMeta($entry->metadata);
                $isQuota = ($meta['kind'] ?? null) === 'monthly_quota' || in_array($entry->type, ['grant', 'expire'], true);
                $amount  = (int) $entry->amount;
                $sign    = $amount < 0 ? -1 : 1;

                $fromQuota   = (int) ($meta['from_quota'] ?? 0);
                $fromBalance = (int) ($meta['from_balance'] ?? 0);
                $toQuota     = (int) ($meta['to_quota'] ?? 0) + (int) ($meta['quota_forfeited'] ?? 0);
                $toBalance   = (int) ($meta['to_balance'] ?? 0);

                $amountCents = match (true) {
                    $entry->type === 'consume'                               => 0,
                    $entry->type === 'reserve' && isset($meta['from_quota']) => $sign * ($this->half($fromQuota * $quotaC) + $this->half($fromBalance * $factor)),
                    $entry->type === 'release' && isset($meta['to_quota'])   => $this->half($toQuota * $quotaC) + $this->half($toBalance * $factor),
                    $isQuota                                                 => $this->half($amount * $quotaC),
                    default                                                  => $this->half($amount * $factor),
                };

                // Chaves em centavos ao lado das antigas: uma reserva ainda aberta na hora do deploy liquida certo.
                $extra = [];

                if (isset($meta['from_quota']) || isset($meta['from_balance'])) {
                    $extra['from_quota_cents']   = $this->half($fromQuota * $quotaC);
                    $extra['from_balance_cents'] = $this->half($fromBalance * $factor);
                }

                if ($entry->type === 'reserve') {
                    $extra['reserved_cents'] = ($extra['from_quota_cents'] ?? 0) + ($extra['from_balance_cents'] ?? 0);
                }

                if ($entry->type === 'consume') {
                    $extra['consumed_from_reservation_cents'] = ($extra['from_quota_cents'] ?? 0) + ($extra['from_balance_cents'] ?? 0);
                    $extra['reserved_delta_cents']            = $extra['from_balance_cents'] ?? $this->half((int) ($meta['reserved_delta'] ?? 0) * $factor);
                }

                if ($entry->type === 'release') {
                    $extra['to_quota_cents']        = $this->half((int) ($meta['to_quota'] ?? 0) * $quotaC);
                    $extra['to_balance_cents']      = $this->half($toBalance * $factor);
                    $extra['quota_forfeited_cents'] = $this->half((int) ($meta['quota_forfeited'] ?? 0) * $quotaC);
                    $extra['released_cents']        = $amountCents;
                }

                DB::table('ai_credit_ledger_entries')->where('id', $entry->id)->update([
                    'amount_cents'        => $amountCents,
                    'balance_after_cents' => $this->half((int) $entry->balance_after * $factor),
                    'unit'                => 'legacy_credit',
                    'metadata'            => $extra === [] ? $entry->metadata : json_encode([...$meta, ...$extra], JSON_THROW_ON_ERROR),
                ]);

                $count++;
            });

        return $count;
    }

    private function convertPurchases(?string $entityId, float $factor): int
    {
        $query = DB::table('ai_credit_purchases')->whereNull('wallet_credit_cents');

        if ($entityId !== null) {
            $query->where('entity_id', $entityId);
        } else {
            $query->whereNotIn('entity_id', DB::table('ai_credit_wallets')->select('entity_id'));
        }

        $count = 0;

        $query->get()->each(function (object $purchase) use ($factor, &$count): void {
            $cents = match ($purchase->status) {
                'credited', 'refunded' => $this->half((float) $purchase->credits * $factor),
                'pending_payment'      => (int) $purchase->amount_cents,
                default                => 0,
            };

            $update = ['wallet_credit_cents' => $cents];
            $meta   = $this->decodeMeta($purchase->metadata);

            // Estorno parcial antigo (em créditos): o código novo lê só *_cents — sem converter, revogaria de novo.
            if (isset($meta['partial_refund']) && is_array($meta['partial_refund']) && ! isset($meta['partial_refund']['handled_cents'])) {
                $partial                  = $meta['partial_refund'];
                $partial['handled_cents'] = $this->half((int) ($partial['handled_credits'] ?? 0) * $factor);
                $partial['revoked_cents'] = $this->half((int) ($partial['revoked_credits'] ?? 0) * $factor);
                $partial['shortfall']     = $this->half((int) ($partial['shortfall'] ?? 0) * $factor);
                $partial['legacy_unit']   = 'legacy_credit';
                $meta['partial_refund']   = $partial;
                $update['metadata']       = json_encode($meta, JSON_THROW_ON_ERROR);
            }

            DB::table('ai_credit_purchases')->where('id', $purchase->id)->update($update);
            $count++;
        });

        return $count;
    }

    private function convertRuns(?string $entityId, float $factor): int
    {
        $where = $entityId !== null ? 'entity_id = ?' : 'entity_id NOT IN (SELECT entity_id FROM ai_credit_wallets)';
        $bind  = $entityId !== null ? [$entityId] : [];
        $f     = number_format($factor, 6, '.', '');

        return DB::update(
            "UPDATE ai_runs SET estimated_charge_cents = ROUND(estimated_credits::numeric * {$f}), reserved_charge_cents = ROUND(reserved_credits::numeric * {$f}), consumed_charge_cents = ROUND(consumed_credits::numeric * {$f}), pricing_source = 'legacy_migration' WHERE pricing_source IS NULL AND {$where}",
            $bind,
        );
    }

    private function convertProviderCalls(): int
    {
        $factor = number_format($this->legacyRate * $this->legacyMarkup * 100, 6, '.', '');

        return DB::update(
            "UPDATE ai_run_provider_calls SET normalized_charge_cents = ROUND(raw_cost_usd * {$factor}) WHERE normalized_charge_cents IS NULL AND raw_cost_usd IS NOT NULL AND raw_cost_usd > 0",
        );
    }

    /** `plan_features.value` de ai_monthly_credits passa de créditos para centavos (×quota_credit_cents). Uma vez só. */
    private function convertPlanFeatures(): int
    {
        if (DB::table('system_settings')->where('key', self::QUOTA_KEY)->exists()) {
            return 0;
        }

        $updated = DB::update(
            'UPDATE plan_features SET value = (ROUND(value::numeric * ?))::bigint::text WHERE feature = ? AND value ~ ?',
            [number_format($this->quotaCreditCents, 6, '.', ''), 'ai_monthly_credits', '^[0-9]+$'],
        );

        DB::table('system_settings')->insert([
            'key'         => self::QUOTA_KEY,
            'value'       => now()->toAtomString(),
            'type'        => 'string',
            'group'       => 'ai',
            'description' => 'Franquia de IA dos planos convertida de créditos para centavos de BRL.',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return $updated;
    }

    /** Metadados JSON (coluna json/jsonb, às vezes gravados como string JSON dentro do JSON). @return array<string, mixed> */
    private function decodeMeta(mixed $raw): array
    {
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function half(float $value): int
    {
        return (int) round($value, 0, PHP_ROUND_HALF_UP);
    }
}
