<?php

declare(strict_types=1);

namespace App\Http\Requests\Manager;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Salvar o rascunho de uma versão de protocolo de retorno. O conteúdo é validado a
 * fundo por ProtocolContentSchema (aqui só o envelope): `lock_version` é o que a tela
 * leu — outra sessão que salvou antes torna este envio obsoleto.
 */
class SaveRecallProtocolDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'content'      => ['required', 'array'],
            'lock_version' => ['required', 'integer', 'min:0'],
        ];
    }
}
