<?php

declare(strict_types=1);

namespace App\Domains\Tiss\DTOs;

use App\Domains\Tiss\Models\TissReturn;

/**
 * Resultado de ReceiveTissResponseService::receive().
 *
 * `alreadyImported` = o arquivo repetido de um retorno que JÁ foi processado: nada
 * foi gravado, travado nem despachado, e `$return` é o retorno da importação
 * original. Retorno novo, ou retomado (existia `received`/`parsed`/`error` e foi
 * processado agora), vem com `false` — nos dois casos o efeito é único.
 *
 * `wasRecentlyCreated` não serve de sinal: ProcessTissReturnService::process()
 * devolve `$return->fresh()`.
 */
final readonly class ReceivedTissReturn
{
    public function __construct(
        public TissReturn $return,
        public bool $alreadyImported = false,
    ) {
    }
}
