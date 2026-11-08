<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Exceptions;

use RuntimeException;

/**
 * Produção com o transporte TISS simulado (`mock`): o lote NÃO pode ser "enviado", porque o mock grava um
 * protocolo "Aceito" que a operadora nunca viu. Subclasse de RuntimeException para o faturamento devolver a
 * mensagem ao usuário; o job da fila a trata à parte (configuração, não adianta tentar de novo).
 */
class TissSimulatedTransportException extends RuntimeException
{
}
