<?php

declare(strict_types=1);

namespace App\Http\Requests\Financial;

use App\Enums\{BillingClaimStatus, ScheduleSituation};
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BillingIndividualRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $entityId = (string) session('selected_entity_id');

        return [
            'schedule_id' => [
                'required',
                'uuid',
                Rule::exists('schedules', 'id')->where(function ($query) use ($entityId) {
                    $query
                        ->where('entity_id', $entityId)
                        ->where('situation', ScheduleSituation::Attended->value)
                        ->whereNull('deleted_at');
                }),
            ],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'quantity'   => ['nullable', 'integer', 'min:1', 'max:99'],
            'due_date'   => ['nullable', 'date'],
            'status'     => ['nullable', Rule::in([
                BillingClaimStatus::Draft->value,
                BillingClaimStatus::Submitted->value,
            ])],
            'tuss_code'             => ['nullable', 'string', 'max:32'],
            'procedure_description' => ['nullable', 'string', 'max:255'],
            'authorization_code'    => ['nullable', 'string', 'max:64'],
            'notes'                 => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (! $this->has('quantity')) {
            $merge['quantity'] = 1;
        }

        if (! $this->has('status')) {
            $merge['status'] = BillingClaimStatus::Draft->value;
        }

        foreach (['status', 'tuss_code', 'authorization_code'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $merge[$field] = mb_strtoupper(trim($this->input($field)));
            }
        }

        if (! empty($merge)) {
            $this->merge($merge);
        }
    }
}
