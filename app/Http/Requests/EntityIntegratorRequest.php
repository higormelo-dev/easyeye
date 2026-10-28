<?php

namespace App\Http\Requests;

use App\Services\IntegratorTokenPolicy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EntityIntegratorRequest extends FormRequest
{
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (! $this->hasAny(['update_channel', 'update_cohort'])) {
                return;
            }
            $channel = $this->input('update_channel', 'stable');
            $cohort  = $this->input('update_cohort', 'all');

            if (($channel === 'pilot' && $cohort === 'all') || ($channel === 'stable' && $cohort !== 'all')) {
                $validator->errors()->add('update_cohort', 'Piloto exige coorte específica; estável exige all.');
            }
        });
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'token_profile'  => ['sometimes', Rule::in(array_keys(IntegratorTokenPolicy::PROFILES))],
            'update_channel' => ['sometimes', Rule::in(['pilot', 'stable'])],
            'update_cohort'  => ['sometimes', 'string', 'regex:/^[a-z0-9][a-z0-9_-]{0,63}$/'],
            'name'           => [
                'required',
                'string',
                'max:255',
                Rule::unique('entity_integrators')->where(function ($query) {
                    if ($this->integrator) {
                        $query = $query->where('id', '!=', $this->integrator)
                            ->whereNull('deleted_at');
                    }

                    return $query;
                }),
            ],
            'ip' => [
                'required',
                'ip',
            ],
            'mac' => [
                'required',
                'regex:/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/',
            ],
        ];

        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $rules['active'] = ['required', 'boolean'];
        }

        return $rules;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge([
                'name' => mb_strtoupper($this->input('name')),
            ]);
        }

        if ($this->has('mac')) {
            $this->merge([
                'mac' => mb_strtoupper($this->input('mac')),
            ]);
        }
    }
}
