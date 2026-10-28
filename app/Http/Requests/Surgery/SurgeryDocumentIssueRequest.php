<?php

declare(strict_types=1);

namespace App\Http\Requests\Surgery;

use App\Enums\Surgery\SurgeryDocumentType;
use App\Services\Surgery\Documents\SurgeryDocumentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Emissão de TCLE / orientações: o texto enviado (já editado) é o que será congelado. */
class SurgeryDocumentIssueRequest extends FormRequest
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
            'title'       => ['required', 'string', 'min:2', 'max:200'],
            'content'     => ['required', 'string', 'max:200000'],
            'basis_hash'  => ['nullable', 'string', 'size:64'], // retrato do pedido da prévia (SHA-256)
        ];
    }

    public function documentType(): SurgeryDocumentType
    {
        return SurgeryDocumentType::from((string) $this->validated('type'));
    }
}
