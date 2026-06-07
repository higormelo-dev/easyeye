<?php

declare(strict_types=1);

namespace App\Http\Requests\Financial;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validação do salvamento em lote da tabela de preço por procedimento × convênio.
 */
class ProcedurePriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $entityId = (string) session('selected_entity_id');

        return [
            'covenant_id' => [
                'required',
                'uuid',
                Rule::exists('covenants', 'id')->where(fn ($q) => $q
                    ->where(fn ($sub) => $sub->where('entity_id', $entityId)->orWhereNull('entity_id'))
                    ->whereNull('deleted_at')),
            ],
            'items'                => ['present', 'array'],
            'items.*.procedure_id' => [
                'required',
                'uuid',
                Rule::exists('procedures', 'id')->where(fn ($q) => $q
                    ->where(fn ($sub) => $sub->where('entity_id', $entityId)->orWhereNull('entity_id'))
                    ->whereNull('deleted_at')),
            ],
            'items.*.price'    => ['nullable', 'numeric', 'min:0'],
            'items.*.charging' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $items = $this->input('items');

        if (! is_array($items)) {
            return;
        }

        foreach ($items as $i => $item) {
            if (array_key_exists('price', $item)) {
                $items[$i]['price'] = $this->normalizeMoney($item['price']);
            }

            if (array_key_exists('charging', $item)) {
                $items[$i]['charging'] = filter_var($item['charging'], FILTER_VALIDATE_BOOLEAN);
            }
        }

        $this->merge(['items' => $items]);
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

        return str_replace(',', '.', str_replace('.', '', $value));
    }
}
