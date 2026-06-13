<?php

declare(strict_types=1);

namespace App\Domains\AI\Exceptions;

use DomainException;

/**
 * Sinalização cooperativa do cancelamento de um AiRun. Lançada pelo orchestrator
 * quando detecta cancelled_at setado entre etapas (gen/rev/adj), permitindo ao
 * execution service aplicar a compensação correta (status Cancelled + estorno
 * dos créditos não consumidos).
 *
 * Não é uma falha real do workflow — não dispara compensateFailedRun nem deve
 * propagar para o failed() hook do job.
 */
final class AiRunCancelledException extends DomainException
{
    public function __construct(string $message = 'AI run cancelled by user.')
    {
        parent::__construct($message);
    }
}
