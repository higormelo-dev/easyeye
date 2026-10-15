<?php

declare(strict_types=1);

namespace App\Http\Requests\Surgery;

use App\Services\Surgery\SurgeryDocumentIssueService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Cópia digitalizada do documento assinado. O limite de tamanho é conferido
 * aqui; o TIPO é conferido pelo CONTEÚDO do arquivo no serviço (extensão e
 * Content-Type do navegador não valem).
 */
class SurgeryDocumentSignedCopyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:' . SurgeryDocumentIssueService::SIGNED_COPY_MAX_KB],
        ];
    }
}
