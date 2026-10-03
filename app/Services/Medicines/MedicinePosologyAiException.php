<?php

declare(strict_types=1);

namespace App\Services\Medicines;

use RuntimeException;

/** Falha da sugestão de posologia por IA com mensagem já traduzida para a tela. */
class MedicinePosologyAiException extends RuntimeException
{
}
