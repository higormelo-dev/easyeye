<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Actions;

use App\Domains\Tiss\Models\{TissEntityOperatorContract, TissEntityOperatorCredential, TissOperator};
use App\Models\Covenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Garante que um Covenant (modelo já usado pelo faturamento) tenha um
 * TissOperator, um TissEntityOperatorContract e uma credencial correspondentes,
 * criando-os na primeira vez que o convênio é usado para faturar TISS.
 *
 * A credencial gerada aqui não carrega segredo real (sem username/senha/certificado) —
 * ela só existe para satisfazer SendTissBatchService::resolveCredential(), que exige uma
 * linha ativa mesmo quando o transporte configurado é o mock (config('tiss.transport.driver')).
 * Para envio real a uma operadora, a clínica precisa substituir essa credencial pelas
 * credenciais reais do webservice contratado.
 */
class ResolveTissOperatorForCovenantAction
{
    /**
     * Indica se o convênio tem registro ANS cadastrado e, portanto, deve gerar
     * guia/lote TISS de verdade. Convênios sem registro ANS representam
     * cobrança particular (dinheiro/tabela própria) e não passam pelo
     * protocolo TISS — nesse caso o chamador (BillingService) não deve
     * invocar __invoke() para este covenant.
     */
    public function isEligible(Covenant $covenant): bool
    {
        return self::normalizeAnsCode($covenant->ans_registry) !== '';
    }

    public static function normalizeAnsCode(?string $rawAnsCode): string
    {
        return preg_replace('/\D+/', '', (string) $rawAnsCode) ?: '';
    }

    public function __invoke(Covenant $covenant, string $entityId): TissEntityOperatorContract
    {
        return DB::transaction(function () use ($covenant, $entityId): TissEntityOperatorContract {
            $operator = $this->resolveOperator($covenant);

            $this->resolveCredential($entityId, $operator->id);

            return TissEntityOperatorContract::query()->firstOrCreate(
                [
                    'entity_id'     => $entityId,
                    'operator_id'   => $operator->id,
                    'contract_code' => 'DEFAULT',
                ],
                [
                    'billing_regime'         => 'fee_for_service',
                    'requires_authorization' => false,
                    'default_guide_type'     => 'consultation',
                    'active'                 => true,
                ],
            );
        });
    }

    private function resolveCredential(string $entityId, string $operatorId): TissEntityOperatorCredential
    {
        $environment = (string) config('tiss.transport.environment', 'production');

        return TissEntityOperatorCredential::query()->firstOrCreate(
            [
                'entity_id'   => $entityId,
                'operator_id' => $operatorId,
                'environment' => $environment,
            ],
            [
                'active'   => true,
                'metadata' => ['provisioned_by' => self::class, 'note' => 'Credencial placeholder — substituir pelas credenciais reais do webservice antes de usar transporte http.'],
            ],
        );
    }

    private function resolveOperator(Covenant $covenant): TissOperator
    {
        if ($covenant->tiss_operator_id) {
            $existing = $covenant->tissOperator()->first();

            if ($existing) {
                return $existing;
            }
        }

        $ansCode = self::normalizeAnsCode($covenant->ans_registry);

        if ($ansCode === '') {
            throw ValidationException::withMessages([
                'covenant_id' => sprintf(
                    'Convênio "%s" não possui registro ANS cadastrado — obrigatório para faturamento TISS. '
                    . 'Use faturamento particular para este convênio.',
                    $covenant->name,
                ),
            ]);
        }

        $operator = TissOperator::query()->firstOrCreate(
            ['ans_code' => $ansCode],
            [
                'name'       => $covenant->company_name ?: $covenant->name,
                'trade_name' => $covenant->name,
                'tax_id'     => $covenant->national_registry,
                'active'     => true,
            ],
        );

        $covenant->update(['tiss_operator_id' => $operator->id]);

        return $operator;
    }
}
