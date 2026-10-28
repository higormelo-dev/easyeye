<?php

declare(strict_types=1);

/**
 * Processo FILHO do RecallCatalogConcurrencyTest: "outra requisição" que mexe no catálogo
 * de protocolos de retorno numa sessão PostgreSQL própria. Faz a operação DENTRO de uma
 * transação, avisa o principal pelo arquivo marcador e SEGURA a transação por alguns
 * segundos antes de comitar — a janela em que o principal tenta a mesma operação.
 *
 * Uso: php recall_catalog_child.php <modo> <id> <arquivo_marcador> <segundos_de_pausa>
 *   modos: start-revision (id = protocolo) · freeze (id = versão)
 */

use App\Domains\Recall\Models\{RecallProtocol, RecallProtocolVersion};
use App\Domains\Recall\Services\ProtocolCatalogService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $mode, $id, $marker, $holdSeconds] = $argv;

try {
    DB::transaction(function () use ($mode, $id, $marker, $holdSeconds): void {
        $catalog = app(ProtocolCatalogService::class);

        match ($mode) {
            'start-revision' => $catalog->startRevision(RecallProtocol::query()->findOrFail($id)),
            'freeze'         => $catalog->freeze(RecallProtocolVersion::query()->findOrFail($id)),
        };

        file_put_contents($marker, 'holding');
        usleep((int) ((float) $holdSeconds * 1_000_000));
    });

    fwrite(STDOUT, "OK\n");
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");

    exit(1);
}
