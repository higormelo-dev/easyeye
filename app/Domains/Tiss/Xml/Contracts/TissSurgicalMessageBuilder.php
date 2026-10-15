<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Xml\Contracts;

use App\Domains\Tiss\Models\TissGuide;
use InvalidArgumentException;

/**
 * Builder das mensagens AVULSAS do módulo cirúrgico — uma guia por mensagem, sem
 * lote: solicitação de internação (`SOLICITACAO_PROCEDIMENTOS`) e anexo de OPME
 * (`ENVIO_ANEXO`). O conteúdo vem do retrato gravado na guia, nunca do caso vivo.
 */
interface TissSurgicalMessageBuilder
{
    /** @throws InvalidArgumentException tipo de guia que não é mensagem avulsa */
    public function build(TissGuide $guide): string;
}
