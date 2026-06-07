<?php

declare(strict_types=1);

namespace App\Http\Requests\Financial;

use App\Enums\{FinancialEntryStatus, PaymentMethod};
use App\Http\Requests\Concerns\ValidatesPaymentBreakdown;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\{Rule, Validator};

/**
 * Validação do lançamento de caixa disparado pela chegada do paciente
 * na agenda. Espelha os campos do formulário CashierBook do smart_oftal.
 */
class ScheduleCashEntryRequest extends FormRequest
{
    use ValidatesPaymentBreakdown;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $entityId = (string) session('selected_entity_id');

        return [
            'entry_date'   => ['required', 'date'],
            'description'  => ['required', 'string', 'max:255'],
            'doctor_id'    => ['nullable', 'uuid'],
            'procedure_id' => [
                'nullable',
                'uuid',
                Rule::exists('procedures', 'id')->where(fn ($q) => $q
                    ->where(fn ($sub) => $sub->where('entity_id', $entityId)->orWhereNull('entity_id'))
                    ->whereNull('deleted_at')),
            ],
            'covenant_id' => [
                'nullable',
                'uuid',
                Rule::exists('covenants', 'id')->where(fn ($q) => $q
                    ->where(fn ($sub) => $sub->where('entity_id', $entityId)->orWhereNull('entity_id'))
                    ->whereNull('deleted_at')),
            ],
            'category_id' => [
                'nullable',
                'uuid',
                Rule::exists('financial_categories', 'id')->where(fn ($q) => $q
                    ->where(fn ($sub) => $sub->where('entity_id', $entityId)->orWhereNull('entity_id'))
                    ->whereNull('deleted_at')),
            ],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'amount'         => ['required', 'numeric', 'min:0'],
            'amount_cash'    => ['nullable', 'numeric', 'min:0'],
            'amount_credit'  => ['nullable', 'numeric', 'min:0'],
            'amount_debit'   => ['nullable', 'numeric', 'min:0'],
            'installments'   => ['nullable', 'integer', 'min:0', 'max:12'],
            'status'         => ['nullable', Rule::enum(FinancialEntryStatus::class)],
            'notes'          => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function () use ($validator): void {
            $this->validatePaymentBreakdown($validator);
        });
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        // Normaliza enums recebidos como string (lowercase/trim).
        foreach (['payment_method', 'status'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $merge[$field] = mb_strtolower(trim((string) $this->input($field)));
            }
        }

        // Converte valores com máscara monetária BR (1.234,56 -> 1234.56),
        // espelhando os mutators setPrice*Attribute do CashierBook.
        foreach (['amount', 'amount_cash', 'amount_credit', 'amount_debit'] as $field) {
            if ($this->has($field)) {
                $merge[$field] = $this->normalizeMoney($this->input($field));
            }
        }

        if (! empty($merge)) {
            $this->merge($merge);
        }
    }

    private function normalizeMoney(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return $value;
        }

        // Remove separador de milhar "." e troca a vírgula decimal por ".".
        return str_replace(',', '.', str_replace('.', '', $value));
    }
}
