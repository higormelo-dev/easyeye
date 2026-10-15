<?php

declare(strict_types=1);

namespace App\Http\Requests\CareGuidance;

use Illuminate\Foundation\Http\FormRequest;

/** PDF anexo do modelo: o tamanho e o cabeçalho `%PDF-` são conferidos de novo no serviço. */
class CareGuidanceAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['file' => ['required', 'file', 'max:5120']];
    }
}
