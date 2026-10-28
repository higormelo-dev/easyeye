<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Models\{BillingClaim, Patient};
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Leitura do relatório de faturamento por convênio (tela, detalhe e export).
 *
 * Os números vêm do BillingReportService — a MESMA fonte do Dashboard
 * gerencial (ClinicBiService): guias sem rascunho/canceladas, "Recebido" só
 * de guia paga, agrupado pelo id do convênio (convênio excluído mantém a
 * própria linha, marcado inativo; sem convênio → chave ''). Aqui ficam só o
 * que é do relatório: rótulos traduzidos, % de glosa/recebido com alerta,
 * detalhe paginado das guias e a identificação mínima do paciente (LGPD).
 */
class CovenantReportService
{
    /** % de glosa sobre o faturado ACIMA do qual a linha ganha o selo de alerta. */
    public const GLOSA_ALERT_THRESHOLD = 10.0;

    /** Guias por página no detalhe do convênio. */
    public const CLAIMS_PER_PAGE = 10;

    /** Guias que não contam como faturadas (regra única: BillingReportService). */
    public const NOT_BILLED_STATUSES = BillingReportService::NOT_BILLED_STATUSES;

    /** Partículas ignoradas nas iniciais ("JOÃO DA SILVA" → "J. S."). */
    private const NAME_PARTICLES = ['D', 'DA', 'DAS', 'DE', 'DI', 'DO', 'DOS', 'DU', 'E'];

    public function __construct(
        private readonly BillingReportService $billing,
    ) {
    }

    /**
     * Guias faturadas da clínica no período (mesma base do BI).
     *
     * @return Builder<BillingClaim>
     */
    public function billedClaimsQuery(string $entityId, string $from, string $to): Builder
    {
        return $this->billing->billedClaimsQuery($entityId, $from, $to);
    }

    /**
     * Consolidado por convênio (agregado no banco pelo BillingReportService),
     * com o rótulo no idioma do usuário e os percentuais do relatório.
     *
     * `open` (Em aberto) = valor das guias enviadas aguardando pagamento — a
     * mesma definição do KPI "Em aberto" da tela de Faturamento.
     *
     * @return list<array{covenant_id: string, covenant: string, inactive: bool, claims: int, amount: float, paid: float, denied: float, open: float, glosa_rate: ?float, received_rate: ?float, glosa_alert: bool}>
     */
    public function byCovenant(string $entityId, string $from, string $to): array
    {
        return array_map(
            fn (array $row): array => $this->covenantRow($row),
            $this->billing->byCovenant($entityId, $from, $to),
        );
    }

    /**
     * Totais do relatório a partir das linhas agregadas (os KPIs e o rodapé
     * batem com a tabela por construção; mesma soma que o BI usa).
     *
     * @param list<array<string, mixed>> $rows linhas de byCovenant()
     *
     * @return array{total_claims: int, total_amount: float, total_paid: float, total_denied: float, total_open: float, glosa_rate: ?float, received_rate: ?float, glosa_alert: bool}
     */
    public function totals(array $rows): array
    {
        $totals    = $this->billing->totals($rows);
        $glosaRate = $this->rate($totals['denied'], $totals['amount']);

        return [
            'total_claims'  => $totals['claims'],
            'total_amount'  => $totals['amount'],
            'total_paid'    => $totals['paid'],
            'total_denied'  => $totals['denied'],
            'total_open'    => $totals['open'],
            'glosa_rate'    => $glosaRate,
            'received_rate' => $this->rate($totals['paid'], $totals['amount']),
            'glosa_alert'   => $this->isGlosaAlert($glosaRate),
        ];
    }

    /**
     * Guias de um convênio no período (detalhe da linha), paginadas. `null` =
     * linha "Sem convênio". LGPD: do paciente só código + iniciais — o nome
     * completo é lido aqui para as iniciais e nunca sai do servidor.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function claims(string $entityId, string $from, string $to, ?string $covenantId): LengthAwarePaginator
    {
        return $this->billedClaimsQuery($entityId, $from, $to)
            ->when(
                $covenantId === null,
                fn (Builder $query) => $query->whereNull('billing_claims.covenant_id'),
                fn (Builder $query) => $query->where('billing_claims.covenant_id', $covenantId),
            )
            ->with($this->patientRelations($entityId))
            ->orderByDesc('billing_claims.attendance_date')
            ->orderByDesc('billing_claims.code')
            ->orderByDesc('billing_claims.id')
            ->paginate(self::CLAIMS_PER_PAGE, [
                'billing_claims.id',
                'billing_claims.patient_id',
                'billing_claims.code',
                'billing_claims.status',
                'billing_claims.attendance_date',
                'billing_claims.amount',
                'billing_claims.paid_amount',
                'billing_claims.glosa_amount',
            ])
            ->through(fn (BillingClaim $claim): array => [
                'id'              => (string) $claim->id,
                'code'            => $claim->code,
                'attendance_date' => $claim->attendance_date?->toDateString(),
                'status'          => $claim->status->value,
                'patient'         => $this->patientReference($claim->patient),
                'amount'          => round((float) $claim->amount, 2),
                'received'        => $this->receivedAmount($claim),
                'glosa'           => round((float) $claim->glosa_amount, 2),
            ]);
    }

    /**
     * Relações do paciente para código + iniciais: só paciente da MESMA
     * clínica e só as colunas necessárias.
     *
     * @return array<int|string, Closure|string>
     */
    public function patientRelations(string $entityId): array
    {
        return [
            'patient' => fn ($query) => $query
                ->select(['id', 'entity_id', 'person_id', 'code'])
                ->where('entity_id', $entityId),
            'patient.person:id,full_name',
        ];
    }

    /** "Recebido" da guia: paid_amount só quando a guia está paga (regra do BI). */
    public function receivedAmount(BillingClaim $claim): float
    {
        return $this->billing->receivedAmount($claim);
    }

    /**
     * Identificação mínima do paciente (LGPD, decisão do usuário): código +
     * iniciais, ex.: "PAC-0000000123 · J. S." — nunca o nome completo.
     */
    public function patientReference(?Patient $patient): string
    {
        if ($patient === null) {
            return __('financial_reports.no_patient');
        }

        $code     = trim((string) $patient->code);
        $initials = $this->initials($patient->person?->full_name);

        return match (true) {
            $code !== '' && $initials !== '' => __('financial_reports.patient_ref', ['code' => $code, 'initials' => $initials]),
            $code !== ''                     => $code,
            $initials !== ''                 => $initials,
            default                          => __('financial_reports.no_patient'),
        };
    }

    /**
     * Iniciais do primeiro e do último nome, sem partículas (da, de, dos…) e
     * sem caracteres que não são letras: "JOÃO DA SILVA" → "J. S.";
     * "MARIA" → "M."; vazio → ''.
     */
    public function initials(?string $name): string
    {
        $letters = [];

        foreach (preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $clean = (string) preg_replace('/[^\p{L}]+/u', '', $word);

            if ($clean === '' || in_array(mb_strtoupper($clean), self::NAME_PARTICLES, true)) {
                continue;
            }

            $letters[] = mb_strtoupper(mb_substr($clean, 0, 1));
        }

        if ($letters === []) {
            return '';
        }

        $picked = count($letters) > 1 ? [$letters[0], $letters[count($letters) - 1]] : [$letters[0]];

        return implode(' ', array_map(fn (string $letter): string => "{$letter}.", $picked));
    }

    /** Percentual (1 casa) de `part` sobre `whole`; sem faturado → null ("—" na tela). */
    public function rate(float $part, float $whole): ?float
    {
        return $whole > 0 ? round(($part / $whole) * 100, 1) : null;
    }

    /** Alerta pelo percentual JÁ arredondado (o mesmo que a tela mostra). */
    public function isGlosaAlert(?float $glosaRate): bool
    {
        return $glosaRate !== null && $glosaRate > self::GLOSA_ALERT_THRESHOLD;
    }

    /**
     * Linha do relatório a partir da linha única (BillingReportService):
     * valores idênticos, mais rótulo traduzido e percentuais.
     *
     * @param array{covenant_id: string, covenant_name: ?string, inactive: bool, claims: int, amount: float, paid: float, denied: float, open: float} $row
     *
     * @return array<string, mixed>
     */
    private function covenantRow(array $row): array
    {
        $glosaRate = $this->rate($row['denied'], $row['amount']);

        return [
            // '' = sem convênio (mesma chave do BI).
            'covenant_id'   => $row['covenant_id'],
            'covenant'      => $row['covenant_name'] ?? __('financial_reports.no_covenant'),
            'inactive'      => $row['inactive'],
            'claims'        => $row['claims'],
            'amount'        => $row['amount'],
            'paid'          => $row['paid'],
            'denied'        => $row['denied'],
            'open'          => $row['open'],
            'glosa_rate'    => $glosaRate,
            'received_rate' => $this->rate($row['paid'], $row['amount']),
            'glosa_alert'   => $this->isGlosaAlert($glosaRate),
        ];
    }
}
