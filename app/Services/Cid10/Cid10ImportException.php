<?php

declare(strict_types=1);

namespace App\Services\Cid10;

use RuntimeException;

/**
 * Falha esperada da importação da CID-10 (arquivo sem códigos válidos,
 * arquivo sumiu do disco…) — a mensagem já é traduzida e vai para a tela.
 */
class Cid10ImportException extends RuntimeException
{
}
