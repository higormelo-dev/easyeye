<?php

namespace App\Http\Requests;

use App\Models\Covenant;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\{Carbon, Str};
use Illuminate\Validation\{Rule, Validator};

class CovenantRequest extends FormRequest
{
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
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        $rules = [
            'name' => [
                'required_without:type_method',
                'string',
                'max:255',
                Rule::unique('covenants', 'name')
                    ->ignore($this->getIgnoredCovenantId(), 'id')
                    ->where(function ($query) {
                        $query->where('entity_id', session('selected_entity_id'))
                            ->whereNull('deleted_at');
                    }),
            ],
            'color' => [
                'required_without:type_method',
                'string',
                'regex:/^#[0-9a-fA-F]{6}$/',
                Rule::unique('covenants', 'color')
                    ->ignore($this->getIgnoredCovenantId(), 'id')
                    ->where(function ($query) {
                        $query->where('entity_id', session('selected_entity_id'))
                            ->whereNull('deleted_at');
                    }),
            ],
            'table'              => 'required_without:type_method|boolean',
            'contract_starts_at' => ['nullable', 'date'],
            // after_or_equal contra um início vazio falharia mesmo com o fim válido.
            'contract_ends_at' => array_values(array_filter([
                'nullable',
                'date',
                $this->filled('contract_starts_at') ? 'after_or_equal:contract_starts_at' : null,
            ])),
        ];

        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $rules['active'] = ['required', 'boolean'];
        }

        return $rules;
    }

    /**
     * Vigência do contrato no update PARCIAL: o campo omitido mantém a data
     * gravada, então o par EFETIVO (enviado + gravado) é que não pode ficar
     * invertido. Null enviado é "limpar" e não compara. Par completo é coberto
     * pela regra after_or_equal de contract_ends_at; nenhum dos dois, nada muda.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [$this->validateEffectiveContractRange(...)];
    }

    private function validateEffectiveContractRange(Validator $validator): void
    {
        $sendsStart = $this->has('contract_starts_at');
        $sendsEnd   = $this->has('contract_ends_at');

        if (! ($this->isMethod('PUT') || $this->isMethod('PATCH'))
            || $sendsStart === $sendsEnd
            || $validator->errors()->hasAny(['contract_starts_at', 'contract_ends_at'])) {
            return;
        }

        $covenant = $this->persistedCovenant();

        if ($covenant === null) {
            return;
        }

        $start = $sendsStart ? $this->input('contract_starts_at') : $covenant->contract_starts_at;
        $end   = $sendsEnd ? $this->input('contract_ends_at') : $covenant->contract_ends_at;

        if (blank($start) || blank($end) || Carbon::parse($start)->startOfDay()->lte(Carbon::parse($end)->startOfDay())) {
            return;
        }

        $startLabel = __('validation.attributes.contract_starts_at');
        $endLabel   = __('validation.attributes.contract_ends_at');

        if ($sendsEnd) {
            $validator->errors()->add('contract_ends_at', __('validation.after_or_equal', ['attribute' => $endLabel, 'date' => $startLabel]));
        } else {
            $validator->errors()->add('contract_starts_at', __('validation.before_or_equal', ['attribute' => $startLabel, 'date' => $endLabel]));
        }
    }

    /** Convênio da rota, só se for da clínica da sessão (id ou código, como o controller resolve). */
    private function persistedCovenant(): ?Covenant
    {
        $key = $this->route('covenant');

        if (! is_string($key) || $key === '') {
            return null;
        }

        return Covenant::query()
            ->withTrashed()
            ->where('entity_id', session('selected_entity_id'))
            ->where(Str::isUuid($key) ? 'id' : 'code', $key)
            ->first();
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required_without'  => trans('validation.custom.generic.required'),
            'color.required_without' => trans('validation.custom.generic.required'),
            'table.required_without' => trans('validation.custom.generic.required'),
        ];
    }

    private function getIgnoredCovenantId()
    {
        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $covenantId = $this->route('covenant');

            $covenant = Covenant::query()
                ->where('covenants.entity_id', session('selected_entity_id'))
                ->where('covenants.id', $covenantId)
                ->first();

            return $covenant->id ?? null;
        }

        return null;
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

        if ($this->has('table')) {
            $merge['table'] = $this->normalizeBoolean($this->input('table'));
        }

        if ($this->has('active')) {
            $merge['active'] = $this->normalizeBoolean($this->input('active'));
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
            '1', 'true', 'on', 'yes'  => true,
            '0', 'false', 'off', 'no' => false,
            default                   => $value,
        };
    }
}
