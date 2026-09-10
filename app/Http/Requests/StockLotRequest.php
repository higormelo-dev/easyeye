<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edição de METADADO de um lote (número/validade/ativo) —
 * App\Http\Controllers\Stock\ProductLotsController::update().
 *
 * NÃO valida/aceita qty_on_hand/cost_avg — saldo de lote só muda via
 * App\Services\Stock\StockService (ver doc de StockLot).
 */
class StockLotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $lot = $this->route('stockLot');

        return [
            'lot_number' => [
                'required',
                'string',
                'max:100',
                Rule::unique('stock_lots', 'lot_number')
                    ->ignore($lot?->id)
                    ->where(fn ($query) => $query
                        ->where('entity_product_id', $lot?->entity_product_id)
                        ->whereNull('deleted_at')),
            ],
            'expiry_date' => ['nullable', 'date'],
            'active'      => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lot_number.required' => trans('validation.custom.generic.required'),
            'lot_number.unique'   => __('stock.lot_number_duplicate'),
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('active')) {
            $this->merge(['active' => $this->normalizeBoolean($this->input('active'))]);
        }
    }

    private function normalizeBoolean(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && in_array($value, [0, 1], true)) {
            return (bool) $value;
        }

        if (! is_string($value)) {
            return $value;
        }

        return match (mb_strtolower(trim($value))) {
            '1', 'true', 'on', 'yes' => true,
            '0', 'false', 'off', 'no' => false,
            default => $value,
        };
    }
}
