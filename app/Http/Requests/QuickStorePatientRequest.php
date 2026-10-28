<?php

namespace App\Http\Requests;

use App\Services\ClinicalSafety\PatientClinicalSafetyService;
use App\Services\PatientDuplicateFinder;
use App\Support\BrazilianFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class QuickStorePatientRequest extends FormRequest
{
    /** Alergias no cadastro rápido (as demais, no cadastro completo). */
    public const MAX_ALLERGIES = 10;

    /** Tamanho de cada substância (mesmo limite do cadastro de alergias). */
    public const ALLERGY_MIN_LENGTH = 2;

    public const ALLERGY_MAX_LENGTH = 160;

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
            // Fase 2 (G2): alergias informadas pelo paciente (substâncias em
            // texto curto) OU "nega alergias". Nenhum dos dois = sem registro.
            'allergies'        => ['nullable', 'string', 'max:500'],
            'allergies_denied' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Substâncias digitadas (sem vazias/repetidas).
     *
     * @return list<string>
     */
    public function allergySubstances(): array
    {
        return PatientClinicalSafetyService::splitSubstances((string) $this->input('allergies', ''));
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
            $this->validateAllergies($validator);
        }, function (Validator $validator): void {
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

    /**
     * "Nega alergias" junto com alergias é contraditório (422); limite de
     * itens e de tamanho de cada substância.
     */
    private function validateAllergies(Validator $validator): void
    {
        if ($validator->errors()->has('allergies') || $validator->errors()->has('allergies_denied')) {
            return;
        }

        $substances = $this->allergySubstances();

        if ($substances !== [] && $this->boolean('allergies_denied')) {
            $validator->errors()->add('allergies_denied', __('clinical_safety.allergies.quick_conflict'));

            return;
        }

        if (count($substances) > self::MAX_ALLERGIES) {
            $validator->errors()->add('allergies', __('clinical_safety.allergies.quick_too_many', ['max' => self::MAX_ALLERGIES]));

            return;
        }

        foreach ($substances as $substance) {
            $length = mb_strlen($substance);

            if ($length < self::ALLERGY_MIN_LENGTH || $length > self::ALLERGY_MAX_LENGTH) {
                $validator->errors()->add('allergies', __('clinical_safety.allergies.quick_item_length', [
                    'min'  => self::ALLERGY_MIN_LENGTH,
                    'max'  => self::ALLERGY_MAX_LENGTH,
                    'item' => mb_strimwidth($substance, 0, 40, '…'),
                ]));

                return;
            }
        }
    }

    public function attributes(): array
    {
        return [
            'name'             => 'nome completo',
            'cellphone'        => 'celular',
            'birth_date'       => 'data de nascimento',
            'allergies'        => __('clinical_safety.allergies.quick_label'),
            'allergies_denied' => __('clinical_safety.allergies.quick_deny'),
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
