<?php

declare(strict_types=1);

namespace App\Http\Requests\Manager;

use App\Enums\PartnerType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class PartnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $partnerId = $this->route('partner')?->id;

        return [
            'name'            => ['required', 'string', 'max:255'],
            'email'           => ['required', 'email', 'max:255', 'unique:partners,email' . ($partnerId ? ',' . $partnerId : '')],
            'type'            => ['required', new Enum(PartnerType::class)],
            'document'        => ['nullable', 'string', 'max:18'],
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes'           => ['nullable', 'string', 'max:1000'],
            'status'          => ['sometimes', 'in:active,inactive,suspended'],
        ];
    }
}
