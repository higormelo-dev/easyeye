<?php

declare(strict_types=1);

namespace Tests\Unit\Queue;

use App\Jobs\GenerateExamDerivatives;

/**
 * F1: derivados de exame rodam em até 2 tentativas, a 2ª só após 60 s. Uma única tentativa tornava definitiva a falha
 * transitória de rede no S3 (o outbox apaga o intent no dispatch; só o BackfillExamDerivatives manual recupera); 3 repetiam
 * a falha determinística de formato/memória relendo o S3 a cada vez. Fila `default` explícita (nenhum job `ShouldQueue`
 * fica sem fila).
 */
it('gera derivados em 2 tentativas com 60 s de intervalo, na fila default', function () {
    $job = new GenerateExamDerivatives('exam-id');

    expect($job->tries)->toBe(2)
        ->and($job->backoff)->toBe(60)
        ->and($job->queue)->toBe('default');
});
