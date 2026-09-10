<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\StockUnit;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validação de create/update de App\Models\EntityProduct.
 *
 * NÃO valida/aceita `qty_on_hand`/`cost_avg` — de propósito (ver doc do
 * model): saldo e custo médio só mudam via App\Services\Stock\StockService,
 * nunca por este formulário de cadastro do produto.
 */
class EntityProductRequest extends FormRequest
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
        $entityId  = session('selected_entity_id');
        $productId = $this->route('entityProduct')?->id;

        return [
            'product_category_id' => [
                'nullable',
                'uuid',
                Rule::exists('product_categories', 'id')
                    ->where('entity_id', $entityId)
                    ->whereNull('deleted_at'),
            ],
            'sku' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('entity_products', 'sku')
                    ->ignore($productId)
                    ->where(fn ($query) => $query->where('entity_id', $entityId)),
            ],
            // GAP fechado (revisão pós-Fase 4 — "melhorar o módulo de
            // estoque"): código de barras (EAN/UPC ou etiqueta interna),
            // único por clínica — ver migration 2026_09_10_090000.
            'barcode' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique('entity_products', 'barcode')
                    ->ignore($productId)
                    ->where(fn ($query) => $query->where('entity_id', $entityId)),
            ],
            'name'         => ['required', 'string', 'max:255'],
            'description'  => ['nullable', 'string', 'max:2000'],
            'unit'         => ['required', Rule::enum(StockUnit::class)],
            'is_opm'       => ['boolean'],
            'requires_lot' => ['boolean'],
            'sale_price'   => ['nullable', 'numeric', 'min:0'],
            'min_qty'      => ['nullable', 'numeric', 'min:0'],
            'max_qty'      => ['nullable', 'numeric', 'min:0', 'gte:min_qty'],
            'active'       => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => trans('validation.custom.generic.required'),
            'unit.required' => trans('validation.custom.generic.required'),
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['active', 'is_opm', 'requires_lot'] as $field) {
            if ($this->has($field)) {
                $this->merge([$field => $this->normalizeBoolean($this->input($field))]);
            }
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
