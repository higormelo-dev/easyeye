<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Item da BOM (App\Models\ProcedureProduct) — "procedimento X consome Y
 * unidades do produto Z por padrão". `procedure_id` vem da rota, não do
 * body — ver App\Http\Controllers\Stock\ProcedureProductsController::store().
 */
class ProcedureProductRequest extends FormRequest
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
        $entityId = session('selected_entity_id');

        return [
            'entity_product_id' => [
                'required',
                'uuid',
                Rule::exists('entity_products', 'id')
                    ->where('entity_id', $entityId)
                    ->whereNull('deleted_at'),
            ],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'notes'    => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'entity_product_id.required' => trans('validation.custom.generic.required'),
            'quantity.gt'                => __('stock.quantity_must_be_positive'),
        ];
    }
}
