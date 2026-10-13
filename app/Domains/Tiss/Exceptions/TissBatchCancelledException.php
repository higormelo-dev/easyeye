<?php

declare(strict_types=1);

namespace App\Domains\Tiss\Exceptions;

use RuntimeException;

/**
 * O lote TISS foi cancelado: não há XML a gerar nem lote a enviar. Subclasse de
 * RuntimeException para que quem já trata falha de envio/geração (o faturamento
 * devolve a mensagem ao usuário) continue funcionando; os jobs da fila a pegam à
 * parte — cancelar é uma ação legítima do usuário, não uma falha a retentar nem a
 * reportar (achado via revisão adversarial Codex, 23ª rodada).
 */
class TissBatchCancelledException extends RuntimeException
{
}
