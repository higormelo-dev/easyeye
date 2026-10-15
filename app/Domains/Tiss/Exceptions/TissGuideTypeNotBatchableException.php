<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Exceptions;

use App\Domains\Tiss\Enums\TissGuideType;
use RuntimeException;

/**
 * Tipo de guia que o builder de LOTE não sabe emitir. Antes, tudo que não era
 * consulta caía em `appendSadtGuide` e saía como SP-SADT sem erro nenhum — uma
 * solicitação de internação num lote virava uma guia SP-SADT inventada. Agora o
 * lote recusa explicitamente (a solicitação de internação e o anexo de OPME são
 * mensagens avulsas, não entram em `loteGuias`).
 */
class TissGuideTypeNotBatchableException extends RuntimeException
{
    public static function for(TissGuideType $type, string $guideNumber): self
    {
        return new self(sprintf(
            'A guia %s é do tipo "%s", que não viaja em lote TISS (mensagem avulsa).',
            $guideNumber,
            $type->value,
        ));
    }
}
