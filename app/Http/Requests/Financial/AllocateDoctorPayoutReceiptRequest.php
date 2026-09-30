<?php

declare(strict_types=1);

namespace App\Http\Requests\Financial;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Recebimento manual do repasse: uma receita avulsa do caixa (cash_entry_id)
 * dividida entre atos (items: chave do ato + valor). A regra de negócio —
 * receita elegível, soma ≤ saldo da receita, ato da clínica — fica no
 * DoctorPayoutReceiptAllocationService, sob lock.
 */
class AllocateDoctorPayoutReceiptRequest extends FormRequest
{
    public const MAX_ITEMS = 200;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $items = $this->input('items');

        if (is_array($items)) {
            $this->merge(['items' => array_map(function ($item) {
                if (is_array($item) && is_string($item['amount'] ?? null)) {
                    $item['amount'] = self::normalizeAmount($item['amount']);
                }

                return $item;
            }, $items)]);
        }

        if (is_string($this->input('notes'))) {
            $this->merge(['notes' => trim($this->input('notes')) ?: null]);
        }
    }

    public function rules(): array
    {
        return [
            'cash_entry_id'  => ['required', 'uuid'],
            'items'          => ['required', 'array', 'min:1', 'max:' . self::MAX_ITEMS],
            'items.*.key'    => ['required', 'string', 'max:200'],
            'items.*.amount' => ['required', 'numeric', 'gt:0', 'max:' . DoctorPayoutRuleRequest::MONEY_MAX, 'decimal:0,2'],
            'notes'          => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return list<array{key: string, amount_cents: int}> */
    public function items(): array
    {
        return array_values(array_map(fn (array $item) => [
            'key'          => (string) $item['key'],
            'amount_cents' => Money::toCents($item['amount']),
        ], $this->validated('items')));
    }

    public function attributes(): array
    {
        return [
            'cash_entry_id'  => __('financial_doctor_payouts.validation.cash_entry'),
            'items'          => __('financial_doctor_payouts.validation.items'),
            'items.*.amount' => __('financial_doctor_payouts.validation.amount'),
            'notes'          => __('financial_doctor_payouts.validation.notes'),
        ];
    }

    /** "R$ 1.234,56" | "1234.56" → "1234.56" (mesma normalização do ajuste manual). */
    private static function normalizeAmount(string $amount): ?string
    {
        $amount = trim(str_replace(['R$', ' ', "\u{00A0}"], '', $amount));

        if (str_contains($amount, ',')) {
            $amount = str_replace(',', '.', str_replace('.', '', $amount));
        }

        return $amount === '' ? null : $amount;
    }
}
