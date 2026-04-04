<?php

declare(strict_types = 1);

namespace App\Http\Requests\Financial;

use App\Enums\{FinancialEntryStatus, FinancialEntryType};
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CashEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $entityId = (string) session('selected_entity_id');

        return [
            'entry_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(FinancialEntryType::class)],
            'status' => ['nullable', Rule::enum(FinancialEntryStatus::class)],
            'amount' => ['required', 'numeric', 'min:0'],
            'category_id' => [
                'nullable',
                'uuid',
                Rule::exists('financial_categories', 'id')->where(function ($query) use ($entityId) {
                    $query->where(function ($q) use ($entityId) {
                        $q->where('entity_id', $entityId)->orWhereNull('entity_id');
                    })->whereNull('deleted_at');
                }),
            ],
            'covenant_id' => [
                'nullable',
                'uuid',
                Rule::exists('covenants', 'id')->where(function ($query) use ($entityId) {
                    $query->where(function ($q) use ($entityId) {
                        $q->where('entity_id', $entityId)->orWhereNull('entity_id');
                    })->whereNull('deleted_at');
                }),
            ],
            'payment_method' => ['nullable', 'string', 'max:40'],
            'reference_type' => ['nullable', 'string', 'max:60'],
            'reference_id' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        foreach (['active'] as $field) {
            if ($this->has($field)) {
                $merge[$field] = $this->normalizeBoolean($this->input($field));
            }
        }

        foreach (['type', 'status'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $merge[$field] = mb_strtolower(trim($this->input($field)));
            }
        }

        if (!empty($merge)) {
            $this->merge($merge);
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

        if (!is_string($value)) {
            return $value;
        }

        return match (mb_strtolower(trim($value))) {
            '1', 'true', 'on', 'yes' => true,
            '0', 'false', 'off', 'no' => false,
            default => $value,
        };
    }
}

