<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Exceptions;

use RuntimeException;

/**
 * Transporte real com credencial TISS incompleta (sem endereço do webservice, ou a provisória do faturamento que
 * nenhum administrador revisou): o envio não sai e nada é gravado. Configuração, não falha da operadora — o job da fila não tenta de novo.
 */
class TissCredentialIncompleteException extends RuntimeException
{
}
