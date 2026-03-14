<?php

namespace App\Http\Requests\Manager;

use App\Enums\SubscriptionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'plan_id'       => ['required', 'uuid', 'exists:plans,id'],
            'status'        => ['required', Rule::enum(SubscriptionStatus::class)],
            'starts_at'     => ['required', 'date'],
            'ends_at'       => ['nullable', 'date'],
            'trial_ends_at' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'plan_id.required'   => 'O plano é obrigatório.',
            'status.required'    => 'O status é obrigatório.',
            'starts_at.required' => 'A data de início é obrigatória.',
        ];
    }
}
