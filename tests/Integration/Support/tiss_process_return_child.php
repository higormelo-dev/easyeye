<?php

declare(strict_types=1);

/**
 * Processo FILHO do TissReturnDuringResendTest: "outra requisição" que PROCESSA um
 * retorno da operadora (ProcessTissReturnService::process) numa sessão PostgreSQL
 * própria e comita — o que acontece de verdade enquanto um reenvio ainda espera a
 * resposta HTTP da operadora (a requisição ao transporte não segura trava).
 *
 * Uso: php tiss_process_return_child.php <return_id>
 */

use App\Domains\Tiss\Models\TissReturn;
use App\Domains\Tiss\Services\ProcessTissReturnService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__ . '/../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $returnId] = $argv;

try {
    $return = TissReturn::query()->withoutGlobalScopes()->findOrFail($returnId);

    app(ProcessTissReturnService::class)->process($return);

    fwrite(STDOUT, "OK\n");
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");

    exit(1);
}
