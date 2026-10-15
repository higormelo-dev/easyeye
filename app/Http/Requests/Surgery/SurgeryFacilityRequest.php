<?php

declare(strict_types=1);

namespace App\Http\Requests\Surgery;

use App\Enums\Surgery\SurgeryFacilityType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SurgeryFacilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $req = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name'           => [$req, 'string', 'min:2', 'max:150'],
            'type'           => [$req, Rule::enum(SurgeryFacilityType::class)],
            'cnes'           => ['nullable', 'digits:7'],
            'operator_code'  => ['nullable', 'string', 'max:20'],
            'phone'          => ['nullable', 'string', 'max:30'],
            'zip_code'       => ['nullable', 'string', 'max:9'],
            'address'        => ['nullable', 'string', 'max:150'],
            'address_number' => ['nullable', 'string', 'max:20'],
            'district'       => ['nullable', 'string', 'max:80'],
            'city'           => ['nullable', 'string', 'max:80'],
            'state'          => ['nullable', 'string', 'size:2'],
            'notes'          => ['nullable', 'string', 'max:2000'],
            'active'         => ['sometimes', 'boolean'],
        ];
    }
}
