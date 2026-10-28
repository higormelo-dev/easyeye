<?php

declare(strict_types=1);

namespace App\Services\Financial\DoctorPayouts;

use App\DTOs\DoctorPayout\PayoutItemData;
use App\Enums\{CashEntryNature, CashEntryReferenceType, FinancialEntryStatus, FinancialEntryType};
use App\Enums\DoctorPayout\{DoctorPayoutBasis, DoctorPayoutBeneficiaryRole, DoctorPayoutStatus};
use App\Models\{CashClose, DoctorPayout, DoctorPayoutAdjustment, DoctorPayoutPayment, FinancialCashEntry, FinancialCategory};
use App\Services\Financial\CashPeriodLock;
use App\Support\Database\UniqueViolation;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\{Collection, Number, Str};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fechamento, ajustes, pagamento, estorno e reabertura de repasses.
 *
 * Regime por recebimento: o fechamento grava PARCELAS (DoctorPayoutReleaseService)
 * — devido sobre o recebido acumulado até o fim do período, menos o já
 * liberado — com o retrato dos recebimentos considerados e da regra.
 *
 * Integridade:
 *  - Fechar e reabrir tomam o advisory lock `doctor_payout|clínica|médico`
 *    (dois fechamentos do mesmo médico nunca se cruzam); o índice único
 *    parcial (ato + beneficiário + parcela) dos itens é a garantia final contra
 *    liberar a mesma parcela duas vezes — a violação vira 422 amigável, não 500.
 *  - Fechar toma também o lock COMPARTILHADO da configuração (regras,
 *    participantes, taxas): quem edita espera o fechamento terminar, e
 *    fechamentos de médicos diferentes do mesmo ato leem a mesma divisão.
 *  - Parcelas são acumuladas até o fim do período: um fechamento não pode
 *    terminar antes do último válido do médico, e o total não pode ser
 *    negativo (estorno maior que o novo recebido espera o próximo).
 *  - A prévia conferida na tela (quantidade e total em centavos) é comparada
 *    com o recálculo dentro do lock: se mudou, o fechamento é recusado.
 *  - Pagar segue a ordem do recebimento de guia (BillingService::payLockedClaim):
 *    fechamento FOR UPDATE → CashPeriodLock de escrita → período de caixa aberto
 *    → despesa com entity_id explícito (nunca da sessão).
 *  - Pagamentos parciais (E5): cada pagamento, sob o FOR UPDATE do fechamento,
 *    confere o "já pago" que a tela viu (duplo envio vira 422) e o saldo
 *    (0 < valor ≤ total − já pago); uma despesa por pagamento. Estornar um
 *    pagamento (admin ou financeiro, com motivo) remove a despesa dele. O
 *    status segue os pagamentos válidos: nenhum = closed, parte = partially_paid,
 *    tudo = paid. Reabrir e ajustar só sem pagamento válido.
 *  - Fechamento nunca é apagado: reabrir = status cancelled com motivo; os
 *    itens recebem voided_at e voltam a ficar pendentes.
 */
final class DoctorPayoutClosingService
{
    private const ITEMS_INSERT_CHUNK = 500;

    private const ITEMS_UNIQUE_INDEX = 'doctor_payout_items_active_beneficiary_tranche_unique';

    private const EXPENSE_CATEGORY = 'REPASSE MÉDICO';

    public function __construct(
        private readonly DoctorPayoutCalculator $calculator,
        private readonly DoctorPayoutOptions $options,
    ) {
    }

    /**
     * @param array{period_start: string, period_end: string, expected_count: int|string, expected_charged_cents: int|string, expected_payout_cents: int|string, notes?: ?string} $data
     *
     * @throws ValidationException
     */
    public function close(string $entityId, string $doctorId, array $data, ?string $userId): DoctorPayout
    {
        $doctor = $this->options->doctor($entityId, $doctorId)
            ?? throw ValidationException::withMessages(['doctor_id' => __('financial_doctor_payouts.errors.doctor_required')]);

        $from = CarbonImmutable::parse($data['period_start']);
        $to   = CarbonImmutable::parse($data['period_end']);

        try {
            return DB::transaction(function () use ($entityId, $doctorId, $doctor, $from, $to, $data, $userId): DoctorPayout {
                $this->lockDoctor($entityId, $doctorId);
                DoctorPayoutRuleService::lockConfig($entityId, shared: true);
                $this->assertNotBeforeLastClosing($entityId, $doctorId, $to);

                $items  = $this->calculator->pending($entityId, $doctorId, $from, $to);
                $totals = DoctorPayoutCalculator::totals($items);

                if ($totals['count'] === 0) {
                    throw ValidationException::withMessages(['period_start' => __('financial_doctor_payouts.errors.nothing_to_close')]);
                }

                if ($totals['blocking'] > 0) {
                    throw ValidationException::withMessages([
                        'period_start' => __('financial_doctor_payouts.errors.blocked_no_rule', ['count' => $totals['blocking']]),
                    ]);
                }

                // Quantidade, valor cobrado e repasse precisam bater com a prévia
                // conferida: o demonstrativo é documento permanente (com regra de
                // valor fixo, o cobrado pode mudar sem mudar o repasse).
                if ($totals['count'] !== (int) $data['expected_count']
                    || $totals['charged_cents'] !== (int) $data['expected_charged_cents']
                    || $totals['payout_cents'] !== (int) $data['expected_payout_cents']) {
                    throw ValidationException::withMessages(['period_start' => __('financial_doctor_payouts.errors.preview_changed')]);
                }

                if ($totals['payout_cents'] < 0) {
                    throw ValidationException::withMessages(['period_start' => __('financial_doctor_payouts.errors.release_negative')]);
                }

                $payout = DoctorPayout::query()->create([
                    'entity_id'          => $entityId,
                    'doctor_id'          => $doctorId,
                    'doctor_name'        => $doctor['name'],
                    'doctor_record'      => $doctor['record'],
                    'period_start'       => $from->toDateString(),
                    'period_end'         => $to->toDateString(),
                    'status'             => DoctorPayoutStatus::Closed->value,
                    'basis'              => DoctorPayoutBasis::Receipt->value,
                    'items_count'        => $totals['count'],
                    'gross_amount'       => Money::fromCents($totals['charged_cents']),
                    'items_amount'       => Money::fromCents($totals['payout_cents']),
                    'adjustments_amount' => '0.00',
                    'total_amount'       => Money::fromCents($totals['payout_cents']),
                    'closed_at'          => now(),
                    'closed_by'          => $userId,
                    'notes'              => $data['notes'] ?? null,
                ]);

                $this->insertItems($payout, $items);

                return $payout;
            });
        } catch (UniqueConstraintViolationException $e) {
            if (UniqueViolation::violates($e, self::ITEMS_UNIQUE_INDEX)) {
                throw ValidationException::withMessages(['period_start' => __('financial_doctor_payouts.errors.already_closed')]);
            }

            throw $e;
        }
    }

    /** Reabrir (admin): cancela o fechamento e devolve os atos à produção pendente. */
    public function reopen(DoctorPayout $payout, string $reason, ?string $userId): DoctorPayout
    {
        return DB::transaction(function () use ($payout, $reason, $userId): DoctorPayout {
            $this->lockDoctor((string) $payout->entity_id, (string) $payout->doctor_id);

            $payout = $this->lockPayout($payout);

            if ($payout->status->hasPayments()) {
                throw ValidationException::withMessages(['status' => __('financial_doctor_payouts.errors.reopen_has_payments')]);
            }

            $this->assertTransition($payout, DoctorPayoutStatus::Cancelled);

            $payout->update([
                'status'        => DoctorPayoutStatus::Cancelled->value,
                'cancel_reason' => $reason,
                'cancelled_by'  => $userId,
                'cancelled_at'  => now(),
            ]);

            DB::table('doctor_payout_items')
                ->where('doctor_payout_id', $payout->id)
                ->whereNull('voided_at')
                ->update(['voided_at' => now(), 'updated_at' => now()]);

            return $payout->fresh();
        });
    }

    /** Ajuste manual (centavos com sinal: + acréscimo, − desconto), só antes de pagar. */
    public function addAdjustment(DoctorPayout $payout, string $description, int $amountCents): DoctorPayoutAdjustment
    {
        return DB::transaction(function () use ($payout, $description, $amountCents): DoctorPayoutAdjustment {
            $payout = $this->lockPayout($payout);
            $this->assertStatus($payout, DoctorPayoutStatus::Closed);

            $adjustment = DoctorPayoutAdjustment::query()->create([
                'entity_id'        => $payout->entity_id,
                'doctor_payout_id' => $payout->id,
                'description'      => $description,
                'amount'           => Money::fromCents($amountCents),
            ]);

            $this->recalculateTotals($payout);

            return $adjustment;
        });
    }

    public function removeAdjustment(DoctorPayout $payout, DoctorPayoutAdjustment $adjustment): void
    {
        DB::transaction(function () use ($payout, $adjustment): void {
            $payout = $this->lockPayout($payout);
            $this->assertStatus($payout, DoctorPayoutStatus::Closed);

            abort_unless((string) $adjustment->doctor_payout_id === (string) $payout->id, 404);

            $adjustment->delete();

            $this->recalculateTotals($payout);
        });
    }

    /**
     * Registra UM pagamento do fechamento (parcial ou o saldo) e lança a
     * despesa dele no Fluxo de Caixa. Total zero: um pagamento de 0, sem
     * lançamento, marca como pago.
     *
     * `amount` ausente = o saldo; `expected_paid_cents` (o "já pago" que a tela
     * viu) é conferido quando vem — a requisição HTTP sempre manda.
     *
     * @param array{paid_at: string, payment_method: string, payment_notes?: ?string, amount?: string|int|float, expected_paid_cents?: int|string} $data
     *
     * @throws ValidationException
     */
    public function pay(DoctorPayout $payout, array $data, ?string $userId): DoctorPayout
    {
        return DB::transaction(function () use ($payout, $data): DoctorPayout {
            $payout = $this->lockPayout($payout);

            if (! $payout->status->acceptsPayment()) {
                throw ValidationException::withMessages(['status' => __('financial_doctor_payouts.errors.invalid_status')]);
            }

            $paidCents = $this->activePaidCents($payout);

            if (array_key_exists('expected_paid_cents', $data) && (int) $data['expected_paid_cents'] !== $paidCents) {
                throw ValidationException::withMessages(['amount' => __('financial_doctor_payouts.errors.payment_changed')]);
            }

            $remaining = Money::toCents($payout->total_amount) - $paidCents;
            $amount    = array_key_exists('amount', $data) ? Money::toCents($data['amount']) : $remaining;

            // Saldo zero (total zero): só o pagamento de 0; senão 0 < valor ≤ saldo.
            if ($remaining === 0 && $amount !== 0) {
                throw ValidationException::withMessages(['amount' => __('financial_doctor_payouts.errors.payment_zero_only')]);
            }

            if ($remaining > 0 && ($amount <= 0 || $amount > $remaining)) {
                throw ValidationException::withMessages([
                    'amount' => __('financial_doctor_payouts.errors.payment_exceeds', [
                        'remaining' => Number::currency($remaining / 100, 'BRL', app()->getLocale()),
                    ]),
                ]);
            }

            $paidAt = CarbonImmutable::parse($data['paid_at'])->toDateString();

            if ($amount > 0) {
                $this->assertCashPeriodOpen((string) $payout->entity_id, $paidAt, 'paid_at', 'paid_at_closed_period');
            }

            $payment = DoctorPayoutPayment::query()->create([
                'entity_id'        => $payout->entity_id,
                'doctor_payout_id' => $payout->id,
                'amount'           => Money::fromCents($amount),
                'paid_at'          => $paidAt,
                'payment_method'   => $data['payment_method'],
                'notes'            => $data['payment_notes'] ?? null,
            ]);

            if ($amount > 0) {
                $entry = FinancialCashEntry::query()->create([
                    'entity_id'      => $payout->entity_id,
                    'category_id'    => $this->expenseCategoryId((string) $payout->entity_id),
                    'doctor_id'      => $payout->doctor_id,
                    'entry_date'     => $paidAt,
                    'description'    => Str::limit($this->cashEntryDescription($payout), 250, ''),
                    'type'           => FinancialEntryType::Expense->value,
                    'status'         => FinancialEntryStatus::Paid->value,
                    'amount'         => Money::fromCents($amount),
                    'payment_method' => $data['payment_method'],
                    'nature'         => CashEntryNature::General->value,
                    'reference_type' => CashEntryReferenceType::DoctorPayoutPayment->value,
                    'reference_id'   => $payment->id,
                    'notes'          => $data['payment_notes'] ?? null,
                    'active'         => true,
                ]);

                $payment->update(['cash_entry_id' => $entry->id]);
            }

            $this->syncPaymentStatus($payout);

            return $payout->fresh();
        });
    }

    /**
     * Estorna UM pagamento (admin ou financeiro, com motivo): remove a despesa
     * dele do caixa e o status volta a refletir os pagamentos que restam.
     *
     * @throws ValidationException
     */
    public function reversePayment(DoctorPayout $payout, DoctorPayoutPayment $payment, string $reason, ?string $userId): DoctorPayout
    {
        return DB::transaction(function () use ($payout, $payment, $reason, $userId): DoctorPayout {
            $payout = $this->lockPayout($payout);

            $payment = DoctorPayoutPayment::query()
                ->whereKey($payment->id)
                ->where('entity_id', $payout->entity_id)
                ->where('doctor_payout_id', $payout->id)
                ->lockForUpdate()
                ->first();

            abort_if($payment === null, 404);

            if ($payment->isReversed()) {
                throw ValidationException::withMessages(['reason' => __('financial_doctor_payouts.errors.payment_already_reversed')]);
            }

            if ($payment->cash_entry_id !== null) {
                $entry = FinancialCashEntry::query()
                    ->where('entity_id', $payout->entity_id)
                    ->whereKey($payment->cash_entry_id)
                    ->first();

                if ($entry !== null) {
                    $this->assertCashPeriodOpen((string) $payout->entity_id, $entry->entry_date->toDateString(), 'reason', 'reversal_closed_period');

                    // Caminho de sistema: a trava do CashFlowService bloqueia a
                    // edição manual; o estorno do repasse é a única saída.
                    $entry->delete();
                }
            }

            $payment->update([
                'reversed_at'     => now(),
                'reversed_by'     => $userId,
                'reversal_reason' => $reason,
            ]);

            $this->syncPaymentStatus($payout);

            return $payout->fresh();
        });
    }

    /** Soma dos pagamentos válidos (não estornados), em centavos. */
    private function activePaidCents(DoctorPayout $payout): int
    {
        return Money::toCents(
            DoctorPayoutPayment::query()
                ->where('entity_id', $payout->entity_id)
                ->where('doctor_payout_id', $payout->id)
                ->whereNull('reversed_at')
                ->sum('amount'),
        );
    }

    /**
     * Status e resumo do fechamento a partir dos pagamentos válidos: nenhum =
     * closed, parte = partially_paid, tudo = paid. Os campos de pagamento
     * único do fechamento (forma, observações, despesa, quem pagou) ficam do
     * regime anterior — agora vivem em cada pagamento.
     */
    private function syncPaymentStatus(DoctorPayout $payout): void
    {
        $active = DoctorPayoutPayment::query()
            ->where('entity_id', $payout->entity_id)
            ->where('doctor_payout_id', $payout->id)
            ->whereNull('reversed_at')
            ->selectRaw('COUNT(*) AS payments, COALESCE(SUM(amount), 0) AS paid, MAX(paid_at) AS last_paid_at')
            ->first();

        $count = (int) $active->payments;
        $paid  = Money::toCents($active->paid);

        $status = match (true) {
            $count === 0                                   => DoctorPayoutStatus::Closed,
            $paid >= Money::toCents($payout->total_amount) => DoctorPayoutStatus::Paid,
            default                                        => DoctorPayoutStatus::PartiallyPaid,
        };

        $payout->update([
            'status'         => $status->value,
            'paid_amount'    => $count === 0 ? null : Money::fromCents($paid),
            'paid_at'        => $count === 0 ? null : substr((string) $active->last_paid_at, 0, 10),
            'payment_method' => null,
            'payment_notes'  => null,
            'paid_by'        => null,
            'cash_entry_id'  => null,
        ]);
    }

    /**
     * Retrato dos itens em lote (sem Auditable por linha; a auditoria é do fechamento).
     *
     * @param Collection<int, PayoutItemData> $items
     */
    private function insertItems(DoctorPayout $payout, Collection $items): void
    {
        $now = now();

        $items->chunk(self::ITEMS_INSERT_CHUNK)->each(function (Collection $chunk) use ($payout, $now): void {
            DB::table('doctor_payout_items')->insert($chunk->map(fn (PayoutItemData $item) => [
                'id'               => (string) Str::uuid7(),
                'entity_id'        => $payout->entity_id,
                'doctor_payout_id' => $payout->id,
                // Beneficiário = médico do fechamento (a clínica paga cada participante).
                'doctor_id'              => $payout->doctor_id,
                'beneficiary_role'       => ($item->beneficiaryRole ?? DoctorPayoutBeneficiaryRole::Executor)->value,
                'source_type'            => $item->sourceType->value,
                'source_id'              => $item->sourceId,
                'service_type'           => $item->serviceType->value,
                'performed_at'           => $item->performedAt->format('Y-m-d H:i:s'),
                'patient_id'             => $item->patientId,
                'covenant_id'            => $item->covenantId,
                'is_particular'          => $item->isParticular,
                'covenant_name'          => $item->covenantName,
                'description'            => Str::limit($item->description, 250, ''),
                'visit_type_id'          => $item->visitTypeId,
                'procedure_id'           => $item->procedureId,
                'exam_type_id'           => $item->examTypeId,
                'base_amount'            => Money::fromCents($item->baseCents),
                'base_source'            => $item->baseSource->value,
                'basis'                  => DoctorPayoutBasis::Receipt->value,
                'tranche'                => $item->tranche,
                'received_amount'        => $item->receivedCents === null ? null : Money::fromCents($item->receivedCents),
                'expected_amount'        => $item->expectedCents === null ? null : Money::fromCents($item->expectedCents),
                'released_before_amount' => Money::fromCents($item->releasedBeforeCents),
                'receipts_until'         => $item->receiptsUntil,
                'receipts'               => $item->receipts === [] ? null : json_encode($item->receipts),
                'share_percentage'       => $item->sharePercentage,
                'net_amount'             => $item->netCents === null ? null : Money::fromCents($item->netCents),
                'deductions_amount'      => Money::fromCents($item->deductionsCents),
                'split'                  => $item->split === [] ? null : json_encode($item->split),
                'doctor_payout_rule_id'  => $item->ruleId,
                'rule_calculation'       => $item->ruleCalculation?->value,
                'rule_percentage'        => $item->rulePercentage,
                'rule_fixed_amount'      => $item->ruleFixedCents === null ? null : Money::fromCents($item->ruleFixedCents),
                'payout_amount'          => Money::fromCents($item->payoutCents),
                'warnings'               => $item->warnings === []
                    ? null
                    : json_encode(array_map(fn ($warning) => $warning->value, $item->warnings)),
                'voided_at'  => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])->values()->all());
        });
    }

    /** total = itens + ajustes, nunca negativo. */
    private function recalculateTotals(DoctorPayout $payout): void
    {
        $adjustmentsCents = Money::toCents(
            DoctorPayoutAdjustment::query()->where('doctor_payout_id', $payout->id)->sum('amount'),
        );
        $totalCents = Money::toCents($payout->items_amount) + $adjustmentsCents;

        if ($totalCents < 0) {
            throw ValidationException::withMessages(['amount' => __('financial_doctor_payouts.errors.net_negative')]);
        }

        $payout->update([
            'adjustments_amount' => Money::fromCents($adjustmentsCents),
            'total_amount'       => Money::fromCents($totalCents),
        ]);
    }

    /**
     * Parcelas são calculadas sobre o acumulado até o fim do período: fechar um
     * período que termina antes do último fechamento válido do médico geraria
     * parcelas negativas falsas.
     */
    private function assertNotBeforeLastClosing(string $entityId, string $doctorId, CarbonImmutable $to): void
    {
        $last = $this->calculator->lastClosedUntil($entityId, $doctorId);

        if ($last !== null && $to->toDateString() < $last) {
            throw ValidationException::withMessages([
                'period_end' => __('financial_doctor_payouts.errors.period_before_last', [
                    'date' => CarbonImmutable::parse($last)->isoFormat('L'),
                ]),
            ]);
        }
    }

    private function lockPayout(DoctorPayout $payout): DoctorPayout
    {
        return DoctorPayout::query()
            ->whereKey($payout->id)
            ->where('entity_id', $payout->entity_id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** Serializa fechar/reabrir do mesmo médico até o COMMIT (PostgreSQL). */
    private function lockDoctor(string $entityId, string $doctorId): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $key = (int) hexdec(substr(sha1("doctor_payout|{$entityId}|{$doctorId}"), 0, 15));

        DB::select('select pg_advisory_xact_lock(?)', [$key]);
    }

    private function assertTransition(DoctorPayout $payout, DoctorPayoutStatus $target): void
    {
        if (! $payout->status->canTransitionTo($target)) {
            throw ValidationException::withMessages(['status' => __('financial_doctor_payouts.errors.invalid_status')]);
        }
    }

    private function assertStatus(DoctorPayout $payout, DoctorPayoutStatus $expected): void
    {
        if ($payout->status !== $expected) {
            throw ValidationException::withMessages(['status' => __('financial_doctor_payouts.errors.invalid_status')]);
        }
    }

    /**
     * Mesma regra de CashFlowService/BillingService, sob o lock de escrita do
     * caixa: a despesa (ou o estorno dela) não cai num período fechado.
     */
    private function assertCashPeriodOpen(string $entityId, string $date, string $field, string $messageKey): void
    {
        CashPeriodLock::forWriting($entityId);

        $closed = CashClose::query()
            ->where('entity_id', $entityId)
            ->whereNull('deleted_at')
            ->whereDate('period_start', '<=', $date)
            ->whereDate('period_end', '>=', $date)
            ->exists();

        if ($closed) {
            throw ValidationException::withMessages([
                $field => __("financial_doctor_payouts.errors.{$messageKey}", [
                    'date' => CarbonImmutable::parse($date)->isoFormat('L'),
                ]),
            ]);
        }
    }

    private function expenseCategoryId(string $entityId): ?string
    {
        $id = FinancialCategory::query()
            ->availableForEntity($entityId)
            ->where('type', FinancialEntryType::Expense->value)
            ->where('name', self::EXPENSE_CATEGORY)
            ->orderByRaw('CASE WHEN entity_id IS NULL THEN 1 ELSE 0 END')
            ->value('id');

        return $id === null ? null : (string) $id;
    }

    private function cashEntryDescription(DoctorPayout $payout): string
    {
        return __('financial_doctor_payouts.cash_entry_description', [
            'code'   => $payout->code,
            'doctor' => $payout->doctor_name,
            'period' => $payout->period_start->isoFormat('L') . ' – ' . $payout->period_end->isoFormat('L'),
        ]);
    }
}
