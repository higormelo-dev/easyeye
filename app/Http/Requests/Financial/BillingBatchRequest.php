<?php

declare(strict_types = 1);

namespace App\Http\Requests\Financial;

use App\Enums\BillingClaimStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BillingBatchRequest extends FormRequest
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
                Rule::exists('covenants', 'id')->where(function ($query) use ($entityId) {
                    $query
                        ->where(function ($q) use ($entityId) {
                            $q->where('entity_id', $entityId)->orWhereNull('entity_id');
                        })
                        ->where('active', true)
                        ->whereNull('deleted_at');
                }),
            ],
            'date_from' => ['required', 'date'],
            'date_until' => ['required', 'date', 'after_or_equal:date_from'],
            'schedule_ids' => ['nullable', 'array'],
            'schedule_ids.*' => ['uuid'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
            'due_date' => ['nullable', 'date', 'after_or_equal:date_from'],
            'status' => ['nullable', Rule::in([
                BillingClaimStatus::Draft->value,
                BillingClaimStatus::Submitted->value,
            ])],
            'tiss_version' => ['nullable', 'string', 'max:16'],
            'tiss_layout_version' => ['nullable', 'string', 'max:16'],
            'tuss_code' => ['nullable', 'string', 'max:32'],
            'procedure_description' => ['nullable', 'string', 'max:255'],
            'authorization_code' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (!$this->has('quantity')) {
            $merge['quantity'] = 1;
        }

        if (!$this->has('status')) {
            $merge['status'] = BillingClaimStatus::Draft->value;
        }

        if (!$this->has('tiss_version')) {
            $merge['tiss_version'] = '202603';
        }

        if (!$this->has('tiss_layout_version')) {
            $merge['tiss_layout_version'] = '04.03.00';
        }

        foreach (['status', 'tiss_layout_version', 'tuss_code', 'authorization_code'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $merge[$field] = mb_strtoupper(trim($this->input($field)));
            }
        }

        if (!empty($merge)) {
            $this->merge($merge);
        }
    }
}

