<?php

declare(strict_types=1);

namespace App\Services\Medicines;

use RuntimeException;

/** Falha ao baixar/validar um arquivo oficial — mensagem já traduzida para a tela. */
final class CmedDownloadException extends RuntimeException
{
}
