<?php

declare(strict_types=1);

/**
 * Processo FILHO do PostOpConcurrencyTest: "um worker" que processa o evento
 * SurgeryPerformed de um caso (PostOpReturnService::plan), numa sessão
 * PostgreSQL própria. Espera o arquivo de largada existir para que os workers
 * comecem no mesmo instante (reentrega/duplo despacho do evento).
 *
 * Uso: php postop_returns_child.php <case_id> <arquivo_largada>
 */

use App\Services\Surgery\PostOp\PostOpReturnService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__ . '/../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $caseId, $go] = $argv;

try {
    $deadline = microtime(true) + 30;

    while (! is_file($go) && microtime(true) < $deadline) {
        usleep(2_000);
    }

    $returns = app(PostOpReturnService::class)->plan($caseId);

    echo json_encode(array_map(fn ($r) => $r->status->value, $returns));
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage());

    exit(1);
}
