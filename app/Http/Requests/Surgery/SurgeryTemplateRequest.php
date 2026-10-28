<?php

declare(strict_types=1);

namespace App\Http\Requests\Surgery;

use App\Enums\Surgery\SurgeryDocumentType;
use App\Models\Surgery\SurgeryConsentTemplate;
use App\Models\SurgeryType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Modelo de TCLE / orientações pré-operatórias (Configurações). O tipo só se define na criação. */
class SurgeryTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $create = $this->isMethod('POST');
        $req    = $create ? 'required' : 'sometimes';

        return [
            'type'     => [$create ? 'required' : 'prohibited', Rule::in(array_map(fn (SurgeryDocumentType $t) => $t->value, SurgeryConsentTemplate::TYPES))],
            'category' => ['nullable', 'integer', Rule::in(array_keys(SurgeryType::$categories))],
            'name'     => [$req, 'string', 'min:2', 'max:150'],
            'body'     => [$req, 'string', 'min:10', 'max:200000'],
            'active'   => ['sometimes', 'boolean'],
        ];
    }
}
