<?php

declare(strict_types=1);

namespace App\Services\Financial\DoctorPayouts;

use App\Enums\DoctorPayout\DoctorPayoutDeductionKind;
use App\Models\DoctorPayoutDeductionRate;
use App\Support\Database\UniqueViolation;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deduções sobre o recebido antes de dividir (E4, decisões de 2026-09-29):
 *  - cartão débito / crédito: % sobre a parte paga com cartão NO BALCÃO
 *    (amount_debit/amount_credit do lançamento; "crédito" sem divisão = tudo);
 *  - imposto: alíquota única sobre todo recebido;
 *  - taxa administrativa: % sobre todo recebido.
 * Cada uma sobre o valor BRUTO do recebimento (sem cascata), com a taxa
 * vigente na DATA DO RECEBIMENTO (maior valid_from ≤ data); meio centavo
 * para cima; a soma nunca passa do recebido.
 */
final class DoctorPayoutDeductionService
{
    private const UNIQUE_INDEX = 'doctor_payout_deduction_rates_active_unique';

    /**
     * Vigências por tipo, da mais recente para a mais antiga.
     *
     * @return array<string, list<array{from: string, percentage: string}>>
     */
    public function rates(string $entityId): array
    {
        $rates = [];

        DB::table('doctor_payout_deduction_rates')
            ->where('entity_id', $entityId)
            ->whereNull('deleted_at')
            ->orderByDesc('valid_from')
            ->get(['kind', 'percentage', 'valid_from'])
            ->each(function (object $row) use (&$rates): void {
                $rates[$row->kind][] = ['from' => substr((string) $row->valid_from, 0, 10), 'percentage' => (string) $row->percentage];
            });

        return $rates;
    }

    /** @param array<string, list<array{from: string, percentage: string}>> $rates */
    public static function rateAt(array $rates, DoctorPayoutDeductionKind $kind, string $date): ?string
    {
        foreach ($rates[$kind->value] ?? [] as $rate) {
            if ($rate['from'] <= $date) {
                return $rate['percentage'];
            }
        }

        return null;
    }

    /**
     * Deduções de um recebimento, em centavos.
     *
     * @param array<string, list<array{from: string, percentage: string}>>                                $rates
     * @param array{date: string, amount_cents: int, kind: string, credit_cents?: int, debit_cents?: int} $receipt
     *
     * @return array{card: int, tax: int, admin: int, total: int}
     */
    public static function forReceipt(array $rates, array $receipt): array
    {
        $amount = (int) $receipt['amount_cents'];
        $date   = $receipt['date'];
        $rate   = fn (DoctorPayoutDeductionKind $kind): string => self::rateAt($rates, $kind, $date) ?? '0';

        $card = $receipt['kind'] === 'desk'
            ? Money::percentageOf((int) ($receipt['credit_cents'] ?? 0), $rate(DoctorPayoutDeductionKind::CardCredit))
                + Money::percentageOf((int) ($receipt['debit_cents'] ?? 0), $rate(DoctorPayoutDeductionKind::CardDebit))
            : 0;

        $tax   = Money::percentageOf($amount, $rate(DoctorPayoutDeductionKind::Tax));
        $admin = Money::percentageOf($amount, $rate(DoctorPayoutDeductionKind::Admin));

        return ['card' => $card, 'tax' => $tax, 'admin' => $admin, 'total' => min(max($amount, 0), $card + $tax + $admin)];
    }

    /**
     * Nova vigência de uma taxa (editar = excluir + criar: o histórico fica).
     *
     * @param array{kind: string, percentage: string, valid_from: string, notes?: ?string} $data
     */
    public function create(string $entityId, array $data): DoctorPayoutDeductionRate
    {
        try {
            // Transação (savepoint quando aninhada): a violação do índice único
            // não deixa a transação externa abortada no PostgreSQL. O lock da
            // configuração espera fechamentos em andamento.
            return DB::transaction(function () use ($entityId, $data): DoctorPayoutDeductionRate {
                DoctorPayoutRuleService::lockConfig($entityId);

                return DoctorPayoutDeductionRate::query()->create([
                    'entity_id'  => $entityId,
                    'kind'       => $data['kind'],
                    'percentage' => $data['percentage'],
                    'valid_from' => $data['valid_from'],
                    'notes'      => $data['notes'] ?? null,
                ]);
            });
        } catch (UniqueConstraintViolationException $e) {
            if (UniqueViolation::violates($e, self::UNIQUE_INDEX)) {
                throw ValidationException::withMessages(['valid_from' => __('financial_doctor_payouts.errors.deduction_rate_duplicate')]);
            }

            throw $e;
        }
    }

    /** Excluir (soft): volta a valer a vigência anterior; fechamentos guardam o retrato. */
    public function delete(DoctorPayoutDeductionRate $rate): void
    {
        DB::transaction(function () use ($rate): void {
            DoctorPayoutRuleService::lockConfig((string) $rate->entity_id);

            $rate->delete();
        });
    }
}
