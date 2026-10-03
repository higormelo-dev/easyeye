<?php

namespace App\Http\Requests;

use App\Models\{CovenantPlan, Patient};
use App\Support\BrazilianFormat;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class PatientRequest extends FormRequest
{
    /** Cache do paciente em edição (várias regras consultam). */
    private ?Patient $currentPatient = null;

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
            'covenant_id' => [
                'required_without:type_method',
                'uuid',
                Rule::exists('covenants', 'id')->where(function ($query) {
                    return $query->where(function ($query) {
                        $query->where('entity_id', session()->get('selected_entity_id'))
                            ->orWhere('entity_id', null);
                    })->whereNull('deleted_at');
                }),
            ],
            'skin_id' => [
                'nullable',
                'uuid',
                Rule::exists('skin_types', 'id')->where(function ($query) {
                    return $query->where(function ($query) {
                        $query->where('entity_id', session()->get('selected_entity_id'))
                            ->orWhere('entity_id', null);
                    })->whereNull('deleted_at');
                }),
            ],
            'iris_id' => [
                'nullable',
                'uuid',
                Rule::exists('iris_types', 'id')->where(function ($query) {
                    return $query->where(function ($query) {
                        $query->where('entity_id', session()->get('selected_entity_id'))
                            ->orWhere('entity_id', null);
                    })->whereNull('deleted_at');
                }),
            ],
            'card_number'      => 'nullable|string|max:255',
            'covenant_plan_id' => ['nullable', 'uuid', $this->covenantPlanRule()],
            'name'             => [
                'required_without:type_method',
                'string',
                'max:255',
                $this->uniqueInClinic('full_name'),
            ],
            'nickname' => [
                'nullable',
                'string',
                'max:255',
            ],
            'birth_date' => [
                'required_without:type_method',
                'date_format:Y-m-d',
            ],
            'gender' => [
                'required_without:type_method',
                Rule::in([0, 1]),
            ],
            'marital_status' => [
                'required_without:type_method',
                Rule::in([1, 2, 3, 4, 5, 6, 7, 8]),
            ],
            'email' => [
                'required_without:type_method',
                'string',
                'max:255',
                // Checagem de MX/DNS só em produção: em development/testing o
                // lookup trava domínios de teste (.test, fixtures do Cypress/
                // Pest) e adiciona latência/flakiness de rede à validação.
                // RFC continua valendo em todos os ambientes.
                app()->environment('production') ? 'email:rfc,dns' : 'email:rfc',
                $this->uniqueInClinic('email'),
            ],
            'mother_name' => [
                'nullable',
                'string',
                'max:255',
            ],
            'father_name' => [
                'nullable',
                'string',
                'max:255',
            ],
            // Profissão (opcional). Maiúsculas pelo People, como os nomes.
            'occupation' => [
                'nullable',
                'string',
                'max:120',
            ],
            'national_registry' => [
                'required_without:type_method',
                'string',
                $this->uniqueInClinic('national_registry'),
            ],
            'state_registry' => [
                'nullable',
                'string',
                'max:255',
            ],
            'state_registry_agency' => [
                'nullable',
                'string',
                'max:255',
            ],
            'state_registry_initial' => [
                'nullable',
                'string',
                Rule::in(['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES',
                    'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE',
                    'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE',
                    'TO']),
            ],
            'state_registry_date' => [
                'nullable',
                'date_format:Y-m-d',
            ],
            'telephone' => [
                'nullable',
                'string',
                'max:255',
            ],
            'cellphone' => [
                'required_without:type_method',
                'string',
                'max:255',
            ],
            'whatsapp' => [
                'required_without:type_method',
                'boolean',
            ],
            'zipcode' => [
                'nullable',
                'string',
                'max:255',
            ],
            'address' => [
                'nullable',
                'string',
                'max:255',
            ],
            'number' => [
                'nullable',
                'string',
                'max:255',
            ],
            'complement' => [
                'nullable',
                'string',
                'max:255',
            ],
            'district' => [
                'nullable',
                'string',
                'max:255',
            ],
            'city' => [
                'nullable',
                'string',
                'max:255',
            ],
            'state' => [
                'nullable',
                'string',
                'max:255',
            ],
            'country' => [
                'nullable',
                'string',
                'max:255',
            ],
        ];

        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $rules['active'] = ['required', 'boolean'];
        }

        return $rules;
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'covenant_id.required_without'       => __('validation.custom.generic.required'),
            'full_name.required_without'         => __('validation.custom.generic.required'),
            'birth_date.required_without'        => __('validation.custom.generic.required'),
            'gender.required_without'            => __('validation.custom.generic.required'),
            'marital_status.required_without'    => __('validation.custom.generic.required'),
            'email.required_without'             => __('validation.custom.generic.required'),
            'national_registry.required_without' => __('validation.custom.generic.required'),
            'cellphone.required_without'         => __('validation.custom.generic.required'),
            'whatsapp.required_without'          => __('validation.custom.generic.required'),
        ];
    }

    /**
     * Único entre os pacientes (não excluídos) DESTA clínica. O mesmo paciente
     * pode ter cadastro em outras clínicas — cada uma com o SEU People —, então
     * a regra nunca olha cadastros de outra clínica (e o erro não revela que o
     * CPF/nome/e-mail existe em outro lugar).
     */
    private function uniqueInClinic(string $column): Unique
    {
        $entityId = (string) session()->get('selected_entity_id');

        return Rule::unique('people', $column)
            ->ignore($this->getIgnoredPersonId(), 'id')
            ->where(fn ($query) => $query
                ->whereNull('deleted_at')
                ->whereIn('id', fn ($patients) => $patients
                    ->select('person_id')
                    ->from('patients')
                    ->where('entity_id', $entityId)
                    ->whereNull('deleted_at')));
    }

    private function getIgnoredPersonId()
    {
        return $this->currentPatient()?->person_id;
    }

    /** Paciente em edição (PUT/PATCH); null no cadastro. */
    private function currentPatient(): ?Patient
    {
        if (! $this->isMethod('PUT') && ! $this->isMethod('PATCH')) {
            return null;
        }

        return $this->currentPatient ??= Patient::query()->where('id', $this->route('patient'))->first();
    }

    /**
     * Plano: visível para a clínica (global ou dela), do MESMO convênio e
     * disponível. O plano que o paciente já tem passa mesmo que tenha sido
     * cancelado na ANS/desativado — reabrir e salvar o cadastro não pode
     * travar por causa dele.
     */
    private function covenantPlanRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (blank($value) || ! Str::isUuid((string) $value)) {
                return; // vazio = sem plano; formato inválido já cai na regra uuid
            }

            $current  = $this->currentPatient();
            $entityId = (string) session()->get('selected_entity_id');
            $covenant = $this->input('covenant_id') ?: $current?->covenant_id;

            $plan = CovenantPlan::withoutGlobalScopes()
                ->whereKey($value)
                ->where(fn ($q) => $q->whereNull('entity_id')->orWhere('entity_id', $entityId))
                ->first();

            if (! $plan) {
                $fail(__('covenant_plans.invalid'));

                return;
            }

            if ((string) $plan->covenant_id !== (string) $covenant) {
                $fail(__('covenant_plans.wrong_covenant'));

                return;
            }

            $unchanged = $current !== null && (string) $current->covenant_plan_id === (string) $value;

            if (! $unchanged && ($plan->deleted_at !== null || ! $plan->active)) {
                $fail(__('covenant_plans.unavailable'));
            }
        };
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('name')) {
            $merge['name'] = mb_strtoupper($this->input('name'));
        }

        if ($this->has('nickname')) {
            $merge['nickname'] = mb_strtoupper($this->input('nickname'));
        }

        if ($this->has('mother_name')) {
            $merge['mother_name'] = mb_strtoupper($this->input('mother_name'));
        }

        if ($this->has('father_name')) {
            $merge['father_name'] = mb_strtoupper($this->input('father_name'));
        }

        // CPF chega mascarado (v-mask 'cpf'); grava só dígitos. Null (campo
        // limpo → ConvertEmptyStringsToNull) segue null — preg_replace(null) é
        // deprecated e virava '' silenciosamente.
        if ($this->has('national_registry') && $this->input('national_registry') !== null) {
            $merge['national_registry'] = preg_replace('/\D/', '', (string) $this->input('national_registry')) ?: null;
        }

        // Telefones chegam mascarados (v-mask 'phone'): grava só dígitos, sem DDI 55.
        foreach (['telephone', 'cellphone'] as $field) {
            if ($this->has($field) && $this->input($field) !== null) {
                $merge[$field] = BrazilianFormat::canonicalPhone((string) $this->input($field));
            }
        }

        if ($this->has('zipcode') && $this->input('zipcode') !== null) {
            $merge['zipcode'] = preg_replace('/\D/', '', (string) $this->input('zipcode'));
        }

        foreach (['whatsapp', 'active'] as $booleanField) {
            if ($this->has($booleanField)) {
                $merge[$booleanField] = $this->normalizeBoolean($this->input($booleanField));
            }
        }

        if (! empty($merge)) {
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

        if (! is_string($value)) {
            return $value;
        }

        return match (mb_strtolower(trim($value))) {
            '1', 'true', 'on', 'yes' => true,
            '0', 'false', 'off', 'no' => false,
            default => $value,
        };
    }
}
