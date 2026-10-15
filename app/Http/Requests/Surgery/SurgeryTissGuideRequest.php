<?php

declare(strict_types=1);

namespace App\Http\Requests\Surgery;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Gerar a guia (type) ou completar à mão os campos que o caso não carrega. */
class SurgeryTissGuideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type'                    => [$this->isMethod('POST') && $this->routeIs('*guides.store') ? 'required' : 'prohibited', Rule::in(['inpatient', 'opme'])],
            'suggested_date'          => ['nullable', 'date_format:Y-m-d'],
            'referenced_guide_number' => ['nullable', 'string', 'max:20'],
            'professional_phone'      => ['nullable', 'string', 'max:20'],
            'justification'           => ['nullable', 'string', 'max:2000'],
            'specification'           => ['nullable', 'string', 'max:1000'],
            'observation'             => ['nullable', 'string', 'max:1000'],

            'items'                           => ['sometimes', 'array', 'max:60'],
            'items.*.id'                      => ['required', 'uuid'],
            'items.*.tuss_code'               => ['nullable', 'string', 'max:10', 'regex:/^[0-9A-Za-z.\-]+$/'],
            'items.*.table_code'              => ['nullable', Rule::in(['18', '19', '20', '22', '90', '98', '00'])],
            'items.*.manufacturer_option'     => ['nullable', Rule::in(['1', '2', '3'])],
            'items.*.anvisa_registration'     => ['nullable', 'string', 'max:15'],
            'items.*.manufacturer_ref'        => ['nullable', 'string', 'max:60'],
            'items.*.operating_authorization' => ['nullable', 'string', 'max:30'],
            'items.*.unit_amount'             => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
        ];
    }

    /** @return array<string, mixed> campos de complemento (sem `type`) */
    public function complement(): array
    {
        return $this->safe()->except('type');
    }
}
