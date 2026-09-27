<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Enums\{BillingBatchStatus, BillingClaimStatus};
use App\Models\{BillingBatch, BillingClaim};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recebimento em massa: "Registrar recebimento" das guias selecionadas na aba
 * Guias (payClaims, valor por guia) e "Registrar recebimento do lote"
 * (payBatch: todas as guias pagáveis do lote pelo valor a receber).
 *
 * Mesmas regras do recebimento individual, guia a guia: o miolo de
 * BillingService::markClaimPaid (payLockedClaim) — máquina de estados,
 * refaturada/receita já lançada (claimPayError), caixa aberto na data e UM
 * lançamento de caixa por guia (billing_claim_id, referência billing_claim).
 *
 *  - UMA transação; guias travadas FOR UPDATE por id crescente e só depois o
 *    CashPeriodLock compartilhado (dentro de payLockedClaim) — a mesma ordem
 *    do recebimento individual (guia → caixa), então não fecha ciclo com ele
 *    nem com o fechamento de caixa (que não pede lock de guia). Nunca pede o
 *    lote (como markClaimPaid): quem cancela/anexa espera a guia, nunca o contrário.
 *  - Idempotente: guia já paga é ignorada e informada (reenvio não duplica).
 *  - Tudo ou nada: se alguma guia não puder ser paga, 422 com o código e o
 *    motivo de cada uma e nada é gravado.
 *  - Teto de MAX_CLAIMS guias por requisição.
 */
class BillingBulkReceiptService
{
    /** Teto de guias por requisição (seleção da aba Guias e lote inteiro). */
    public const MAX_CLAIMS = 200;

    /** Status das guias que ainda esperam recebimento (com valor a receber). */
    private const OPEN_STATUSES = [
        BillingClaimStatus::Submitted,
        BillingClaimStatus::Denied,
    ];

    public function __construct(
        private readonly BillingService $billing,
    ) {
    }

    /**
     * Regra de "Registrar recebimento do lote" (tela e guard): lote enviado ou
     * cobrado com guia à espera de recebimento. A lista traz
     * `open_claims_count` (withCount com awaitingReceipt); sem ele, uma
     * consulta. Cada guia ainda passa por claimPayError. Estática para
     * BillingService::allowedBatchActions usar sem depender deste service
     * (que depende dele).
     *
     * @return string|null chave em financial_billing.errors, ou null se permitido
     */
    public static function batchReceiveError(BillingBatch $batch): ?string
    {
        if (! in_array($batch->status, [BillingBatchStatus::Submitted, BillingBatchStatus::Processed], true)) {
            return 'batch_receipt_not_submitted';
        }

        $open = $batch->getAttribute('open_claims_count') ?? self::awaitingReceipt($batch->claims())->count();

        return (int) $open > 0 ? null : 'batch_receipt_nothing_to_pay';
    }

    /**
     * Guias à espera de recebimento: enviadas, ou glosadas com restante a
     * receber (valor > glosa) — glosa total não tem o que receber.
     *
     * @template T of Builder|HasMany
     *
     * @param T $query
     *
     * @return T
     */
    public static function awaitingReceipt(Builder|HasMany $query): Builder|HasMany
    {
        return $query
            ->whereIn('billing_claims.status', array_map(fn (BillingClaimStatus $status) => $status->value, self::OPEN_STATUSES))
            ->whereColumn('billing_claims.amount', '>', 'billing_claims.glosa_amount');
    }

    /**
     * @param array<string, float|int|string>                                           $amounts claim_id => valor recebido
     * @param array{paid_at: string, payment_method?: string|null, notes?: string|null} $data
     *
     * @return array{paid: list<BillingClaim>, skipped: list<BillingClaim>, total: float}
     *
     * @throws ValidationException guia de outra clínica/inexistente, não pagável, valor inválido, caixa fechado (tudo ou nada)
     */
    public function payClaims(string $entityId, array $amounts, array $data): array
    {
        $amounts = collect($amounts)->mapWithKeys(fn ($amount, $id): array => [mb_strtolower((string) $id) => $amount])->all();

        $this->assertWithinLimit(count($amounts));

        return DB::transaction(function () use ($entityId, $amounts, $data): array {
            $claims = BillingClaim::query()
                ->where('entity_id', $entityId)
                ->whereIn('id', array_keys($amounts))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($claims->count() !== count($amounts)) {
                throw ValidationException::withMessages([
                    'items' => __('financial_billing.errors.bulk_claims_not_found'),
                ]);
            }

            return $this->payLocked($claims, $amounts, $data);
        });
    }

    /**
     * "Registrar recebimento do lote": todas as guias pagáveis do lote (em
     * aberto, sem bloqueio e com valor a receber), cada uma pelo valor a
     * receber (valor − glosa).
     *
     * @param array{paid_at: string, payment_method?: string|null, notes?: string|null} $data
     *
     * @return array{paid: list<BillingClaim>, skipped: list<BillingClaim>, total: float}
     */
    public function payBatch(BillingBatch $batch, array $data): array
    {
        $this->guardBatch($batch);

        return DB::transaction(function () use ($batch, $data): array {
            $claims = self::awaitingReceipt($this->batchClaims($batch))
                ->orderBy('billing_claims.id')
                ->lockForUpdate()
                ->get();

            $payable = $this->payableOf($claims);

            if ($payable->isEmpty()) {
                $this->throwBatch('batch_receipt_nothing_to_pay', $batch);
            }

            $this->assertWithinLimit($payable->count(), $batch);

            $amounts = $payable->mapWithKeys(fn (BillingClaim $claim): array => [(string) $claim->id => $this->billing->receivableAmount($claim)])->all();

            return $this->payLocked($payable, $amounts, $data);
        });
    }

    /**
     * Prévia de payBatch (sem lock): quantas guias, quanto e quantas à espera
     * de recebimento ficam de fora por bloqueio (refaturada/receita já lançada).
     *
     * @return array{count: int, total: float, blocked: int, max: int, over_limit: bool}
     */
    public function batchPreview(BillingBatch $batch): array
    {
        $this->guardBatch($batch);

        $open = self::awaitingReceipt($this->batchClaims($batch))->get();

        $payable = $this->payableOf($open);

        return [
            'count'      => $payable->count(),
            'total'      => round((float) $payable->sum(fn (BillingClaim $claim): float => $this->billing->receivableAmount($claim)), 2),
            'blocked'    => $open->count() - $payable->count(),
            'max'        => self::MAX_CLAIMS,
            'over_limit' => $payable->count() > self::MAX_CLAIMS,
        ];
    }

    /**
     * Guias já travadas: confere TODAS antes de gravar (tudo ou nada) e paga
     * uma a uma pelo miolo do recebimento individual.
     *
     * @param Collection<int, BillingClaim>   $claims
     * @param array<string, float|int|string> $amounts
     * @param array<string, mixed>            $data
     *
     * @return array{paid: list<BillingClaim>, skipped: list<BillingClaim>, total: float}
     */
    private function payLocked(Collection $claims, array $amounts, array $data): array
    {
        // Bloqueios (refaturada/receita lançada) da lista inteira em 2 consultas.
        $blockers = $this->billing->claimPayBlockers($claims);
        $failures = [];
        $skipped  = [];
        $payable  = [];

        foreach ($claims as $claim) {
            if ($claim->status === BillingClaimStatus::Paid) {
                $skipped[] = $claim;

                continue;
            }

            $amount = round((float) $amounts[(string) $claim->id], 2);
            $error  = $this->billing->claimPayError($claim, $blockers) ?? match (true) {
                $amount <= 0                     => 'bulk_paid_amount_required',
                $amount > (float) $claim->amount => 'bulk_paid_amount_exceeds_claim',
                default                          => null,
            };

            if ($error !== null) {
                $failures["claims.{$claim->id}"] = __("financial_billing.errors.{$error}", ['code' => $claim->code]);

                continue;
            }

            $payable[] = [$claim, $amount];
        }

        if ($failures !== []) {
            throw ValidationException::withMessages($failures);
        }

        $paid  = [];
        $total = 0.0;

        foreach ($payable as [$claim, $amount]) {
            $paid[] = $this->billing->payLockedClaim($claim, [
                'paid_amount'    => $amount,
                'paid_at'        => $data['paid_at'] ?? null,
                'payment_method' => $data['payment_method'] ?? null,
                'notes'          => $data['notes'] ?? null,
            ], $blockers);
            $total += $amount;
        }

        return ['paid' => $paid, 'skipped' => $skipped, 'total' => round($total, 2)];
    }

    /**
     * @param Collection<int, BillingClaim> $claims
     *
     * @return Collection<int, BillingClaim>
     */
    private function payableOf(Collection $claims): Collection
    {
        $blockers = $this->billing->claimPayBlockers($claims);

        return $claims
            ->filter(fn (BillingClaim $claim): bool => $this->billing->claimPayError($claim, $blockers) === null
                && $this->billing->receivableAmount($claim) > 0)
            ->values();
    }

    /** @return Builder<BillingClaim> guias do lote, escopadas pela clínica */
    private function batchClaims(BillingBatch $batch): Builder
    {
        return BillingClaim::query()
            ->where('billing_claims.entity_id', $batch->entity_id)
            ->where('billing_claims.batch_id', $batch->id);
    }

    private function guardBatch(BillingBatch $batch): void
    {
        // Status só avança (rascunho → enviado): conferir sem lock basta; as
        // guias são relidas sob lock por quem paga.
        $error = self::batchReceiveError($batch);

        if ($error !== null) {
            $this->throwBatch($error, $batch);
        }
    }

    private function assertWithinLimit(int $count, ?BillingBatch $batch = null): void
    {
        if ($count <= self::MAX_CLAIMS) {
            return;
        }

        if ($batch !== null) {
            $this->throwBatch('batch_receipt_too_many', $batch, ['count' => $count]);
        }

        throw ValidationException::withMessages([
            'items' => __('financial_billing.validation.claims_max', ['max' => self::MAX_CLAIMS]),
        ]);
    }

    /** @param array<string, int|string> $replace */
    private function throwBatch(string $error, BillingBatch $batch, array $replace = []): never
    {
        throw ValidationException::withMessages([
            'batch' => __("financial_billing.errors.{$error}", $replace + [
                'code'   => $batch->code,
                'status' => mb_strtolower($batch->status->label()),
                'max'    => self::MAX_CLAIMS,
            ]),
        ]);
    }
}
