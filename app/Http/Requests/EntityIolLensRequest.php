<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\{Rule, Validator};

/**
 * Validação de create/update de um item de inventário de lente IOL
 * (catarata) DA CLÍNICA — App\Models\EntityIolLens.
 *
 * `iol_lens_model_id` aponta pro catálogo GLOBAL (App\Models\IolLensModel,
 * sem escopo por entity_id) — só valida existência (e que não esteja soft-
 * deletado), nunca escopa por clínica. manufacturer/model_name continuam
 * obrigatórios mesmo quando o model_id é informado: são snapshot local
 * (colunas NOT NULL em entity_iol_lenses — ver doc do model), nunca lidos
 * via join com o catálogo global.
 */
class EntityIolLensRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'iol_lens_model_id' => [
                'nullable',
                'uuid',
                Rule::exists('iol_lens_models', 'id')->withoutTrashed(),
            ],
            'manufacturer' => ['required', 'string', 'max:255'],
            'model_name'   => ['required', 'string', 'max:255'],
            'category'     => ['nullable', 'string', 'max:100'],
            'diopter_min'  => ['nullable', 'numeric'],
            'diopter_max'  => ['nullable', 'numeric'],
            'price'        => ['nullable', 'numeric', 'min:0'],
            'active'       => ['boolean'],
            // `sometimes`: em update o campo pode simplesmente não vir no
            // payload (usuário não trocou a foto) — nesse caso a imagem
            // atual é preservada pelo controller, sem exigir reenvio.
            // `image` valida o mimetype REAL do arquivo (finfo), não confia
            // só na extensão declarada.
            'image' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'manufacturer.required' => trans('validation.custom.generic.required'),
            'model_name.required'   => trans('validation.custom.generic.required'),
        ];
    }

    /**
     * diopter_max >= diopter_min quando ambos presentes.
     *
     * Regra CUSTOM em vez do rule nativo `gte:diopter_min`: o rule nativo do
     * Laravel compara via isSameType()/gettype() e falha (marca inválido)
     * quando o campo comparado está simplesmente AUSENTE do payload —
     * comportamento errado aqui, já que diopter_min é nullable e pode não
     * vir no request mesmo com diopter_max preenchido. A checagem só deve
     * disparar quando AMBOS os valores estão presentes.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $min = $this->input('diopter_min');
            $max = $this->input('diopter_max');

            if ($min === null || $min === '' || $max === null || $max === '') {
                return;
            }

            if (! is_numeric($min) || ! is_numeric($max)) {
                return; // rules 'numeric' já reportam o erro de tipo
            }

            if ((float) $max < (float) $min) {
                $validator->errors()->add(
                    'diopter_max',
                    __('validation.custom.entity_iol_lens.diopter_max_gte'),
                );
            }
        });
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('active')) {
            $this->merge(['active' => $this->normalizeBoolean($this->input('active'))]);
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
