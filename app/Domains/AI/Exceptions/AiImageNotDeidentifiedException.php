<?php

declare(strict_types=1);

namespace App\Domains\AI\Exceptions;

use RuntimeException;

/**
 * Nenhuma imagem do exame pôde ir para a IA: o layout do equipamento não foi
 * reconhecido para tarjar os dados do paciente (LGPD). Falha de configuração,
 * não do provedor — sem nova tentativa e sem chamada paga. A mensagem é a
 * que o médico vê.
 */
class AiImageNotDeidentifiedException extends RuntimeException
{
}
