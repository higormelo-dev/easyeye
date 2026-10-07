<?php

namespace App\Http\Requests;

use App\Services\PatientDuplicateFinder;
use App\Support\BrazilianFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class QuickStorePatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'      => ['required', 'string', 'max:255'],
            'cellphone' => ['required', 'string', 'max:50'],
            // Opcional (pronto atendimento pede): com ela, nome + nascimento
            // iguais é duplicado real e bloqueia (PatientDuplicateFinder).
            'birth_date'      => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after:1900-01-01'],
            'confirm_homonym' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Mesma regra de duplicidade do cadastro completo (PatientDuplicateFinder).
     * Com data de nascimento: nome + nascimento iguais = duplicado real
     * (bloqueia — use o cadastro existente); mesmo nome com outro nascimento
     * ou sem nascimento = homônimo, só cadastra com confirm_homonym=1 ("é outra
     * pessoa").
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('name') || $validator->errors()->has('birth_date')) {
                return;
            }

            $found = app(PatientDuplicateFinder::class)->find(
                (string) session()->get('selected_entity_id'),
                (string) $this->input('name'),
                $this->filled('birth_date') ? (string) $this->input('birth_date') : null,
            );

            if ($found['duplicates'] !== []) {
                $validator->errors()->add('name', __('validation.custom.patient.duplicate', ['codes' => implode(', ', $found['duplicates'])]));

                return;
            }

            if ($found['homonyms'] !== [] && ! $this->boolean('confirm_homonym')) {
                $validator->errors()->add('confirm_homonym', __('validation.custom.patient.homonym', ['codes' => implode(', ', $found['homonyms'])]));
            }
        }];
    }

    public function attributes(): array
    {
        return [
            'name'       => 'nome completo',
            'cellphone'  => 'celular',
            'birth_date' => 'data de nascimento',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('cellphone') && $this->input('cellphone') !== null) {
            $this->merge([
                'cellphone' => BrazilianFormat::canonicalPhone((string) $this->input('cellphone')),
            ]);
        }
    }
}
