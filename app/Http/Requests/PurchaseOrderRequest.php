<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cabeçalho + itens de um pedido de compra (create/updateDraft) —
 * App\Services\Stock\PurchaseOrderService. `items` pode vir vazio no
 * update (rascunho temporariamente sem item) — só `send()` exige >= 1.
 */
class PurchaseOrderRequest extends FormRequest
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
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');

        return [
            'supplier_id' => [
                $isUpdate ? 'sometimes' : 'required',
                'uuid',
                Rule::exists('suppliers', 'id')->where('entity_id', $entityId)->whereNull('deleted_at'),
            ],
            'order_date'             => ['nullable', 'date'],
            'expected_delivery_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'notes'                  => ['nullable', 'string', 'max:2000'],

            'items'                     => ['present', 'array'],
            'items.*.entity_product_id' => [
                'required',
                'uuid',
                Rule::exists('entity_products', 'id')->where('entity_id', $entityId)->whereNull('deleted_at'),
            ],
            'items.*.quantity_ordered' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_cost'        => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'supplier_id.required'               => trans('validation.custom.generic.required'),
            'items.*.entity_product_id.required' => trans('validation.custom.generic.required'),
            'items.*.quantity_ordered.gt'        => __('stock.quantity_must_be_positive'),
        ];
    }

    /**
     * @return array{supplier_id?: string, order_date?: string, expected_delivery_date?: ?string, notes?: ?string}
     */
    public function headerData(): array
    {
        return $this->only(['supplier_id', 'order_date', 'expected_delivery_date', 'notes']);
    }

    /**
     * @return list<array{entity_product_id: string, quantity_ordered: float, unit_cost: float}>
     */
    public function items(): array
    {
        return collect($this->input('items', []))
            ->map(fn (array $item) => [
                'entity_product_id' => $item['entity_product_id'],
                'quantity_ordered'  => (float) $item['quantity_ordered'],
                'unit_cost'         => (float) $item['unit_cost'],
            ])
            ->all();
    }
}
