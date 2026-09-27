<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Enums\BillingClaimStatus;
use App\Models\BillingClaim;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fonte ÚNICA do faturamento por convênio: o Dashboard gerencial
 * (ClinicBiService) e o relatório de convênios (CovenantReportService) leem
 * daqui, então Faturado, Recebido e Glosado por convênio e nos totais são os
 * mesmos nas duas telas por construção.
 *
 * Regras (decisão do produto):
 *  - período pela data de atendimento; guias em rascunho e canceladas não
 *    contam como faturadas (NOT_BILLED_STATUSES);
 *  - "Recebido" = paid_amount só das guias com status pago;
 *  - agrupado pelo id do convênio (homônimos não se fundem; convênio excluído
 *    mantém a própria linha, marcado inativo); sem convênio → chave ''.
 *
 * Agregação no banco (GROUP BY covenant_id) com valores exatos em centavos, e
 * SEM texto traduzido: o BI guarda o resultado em cache e cada tela aplica os
 * rótulos no idioma de quem abriu ("Sem convênio", "(inativo)").
 */
class BillingReportService
{
    /** Guias que não contam como faturadas. */
    public const NOT_BILLED_STATUSES = [
        BillingClaimStatus::Draft->value,
        BillingClaimStatus::Cancelled->value,
    ];

    /**
     * Guias faturadas da clínica no período (colunas qualificadas: a consulta
     * agregada faz JOIN com covenants, que também tem entity_id/deleted_at).
     *
     * @return Builder<BillingClaim>
     */
    public function billedClaimsQuery(string $entityId, string $from, string $to): Builder
    {
        return BillingClaim::query()
            ->where('billing_claims.entity_id', $entityId)
            ->whereBetween('billing_claims.attendance_date', [$from, $to])
            ->whereNotIn('billing_claims.status', self::NOT_BILLED_STATUSES)
            ->whereNull('billing_claims.deleted_at');
    }

    /**
     * Faturamento por convênio, agregado no banco. Ordem: maior faturado,
     * depois nome (sem nome por último) e id — a mesma nas duas telas, então o
     * "top 6" do BI são as 6 primeiras linhas do relatório.
     *
     * `covenant_name` null = guia sem convênio (ou convênio removido do banco).
     * `open` = valor das guias enviadas aguardando pagamento.
     *
     * @return list<array{covenant_id: string, covenant_name: ?string, inactive: bool, claims: int, paid_claims: int, amount: float, paid: float, denied: float, open: float}>
     */
    public function byCovenant(string $entityId, string $from, string $to): array
    {
        $paid      = BillingClaimStatus::Paid->value;
        $submitted = BillingClaimStatus::Submitted->value;

        // LEFT JOIN sem filtrar covenants.deleted_at: convênio excluído (soft
        // delete) continua dono do seu faturamento histórico.
        $rows = $this->billedClaimsQuery($entityId, $from, $to)
            ->toBase()
            ->leftJoin('covenants', 'covenants.id', '=', 'billing_claims.covenant_id')
            ->groupBy('billing_claims.covenant_id', 'covenants.name', 'covenants.deleted_at')
            ->select([
                'billing_claims.covenant_id AS covenant_id',
                'covenants.name AS covenant_name',
                'covenants.deleted_at AS covenant_deleted_at',
            ])
            ->selectRaw('COUNT(*) AS claims_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN billing_claims.status = ? THEN 1 ELSE 0 END), 0) AS paid_count', [$paid])
            ->selectRaw('COALESCE(SUM(billing_claims.amount), 0) AS billed_amount')
            ->selectRaw('COALESCE(SUM(CASE WHEN billing_claims.status = ? THEN billing_claims.paid_amount ELSE 0 END), 0) AS received_amount', [$paid])
            ->selectRaw('COALESCE(SUM(billing_claims.glosa_amount), 0) AS denied_amount')
            ->selectRaw('COALESCE(SUM(CASE WHEN billing_claims.status = ? THEN billing_claims.amount ELSE 0 END), 0) AS open_amount', [$submitted])
            ->get();

        $rows = $rows->map(fn (object $row): array => [
            // '' = sem convênio (a mesma chave que o BI sempre usou: (string) null).
            'covenant_id'   => (string) ($row->covenant_id ?? ''),
            'covenant_name' => $row->covenant_name !== null ? (string) $row->covenant_name : null,
            'inactive'      => $row->covenant_deleted_at !== null,
            'claims'        => (int) $row->claims_count,
            'paid_claims'   => (int) $row->paid_count,
            'amount'        => round((float) $row->billed_amount, 2),
            'paid'          => round((float) $row->received_amount, 2),
            'denied'        => round((float) $row->denied_amount, 2),
            'open'          => round((float) $row->open_amount, 2),
        ])->all();

        usort($rows, fn (array $a, array $b): int => [$b['amount'], $a['covenant_name'] === null, $a['covenant_name'] ?? '', $a['covenant_id']]
            <=> [$a['amount'], $b['covenant_name'] === null, $b['covenant_name'] ?? '', $b['covenant_id']]);

        return array_values($rows);
    }

    /**
     * Totais a partir das linhas de byCovenant() (rodapé e KPIs = soma da
     * tabela, arredondada em centavos). Linhas sem `paid_claims` contam 0.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array{claims: int, paid_claims: int, amount: float, paid: float, denied: float, open: float}
     */
    public function totals(array $rows): array
    {
        return [
            'claims'      => (int) array_sum(array_column($rows, 'claims')),
            'paid_claims' => (int) array_sum(array_column($rows, 'paid_claims')),
            'amount'      => round((float) array_sum(array_column($rows, 'amount')), 2),
            'paid'        => round((float) array_sum(array_column($rows, 'paid')), 2),
            'denied'      => round((float) array_sum(array_column($rows, 'denied')), 2),
            'open'        => round((float) array_sum(array_column($rows, 'open')), 2),
        ];
    }

    /** "Recebido" de uma guia: paid_amount só quando a guia está paga (mesma regra do agregado). */
    public function receivedAmount(BillingClaim $claim): float
    {
        return $claim->status === BillingClaimStatus::Paid ? round((float) $claim->paid_amount, 2) : 0.0;
    }
}
