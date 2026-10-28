<?php

declare(strict_types=1);

namespace App\Http\Requests\Surgery;

use App\Enums\Surgery\SurgeryDocumentType;
use App\Services\Surgery\Documents\SurgeryDocumentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Prévia de TCLE / orientações a partir de um modelo. */
class SurgeryDocumentPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type'        => ['required', Rule::in(array_map(fn (SurgeryDocumentType $t) => $t->value, SurgeryDocumentService::ISSUABLE))],
            'template_id' => ['nullable', 'uuid'],
        ];
    }

    public function documentType(): SurgeryDocumentType
    {
        return SurgeryDocumentType::from((string) $this->validated('type'));
    }
}
