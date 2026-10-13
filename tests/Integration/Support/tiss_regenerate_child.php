<?php

declare(strict_types=1);

/**
 * Processo FILHO do TissSendDocumentRaceTest: "outra requisição" que GERA o XML
 * do lote TISS (GenerateTissXmlService::generateBatch) numa sessão PostgreSQL
 * própria.
 *
 * Modos (4º argumento):
 *  - hold  → abre a transação, TRAVA a linha do lote (FOR UPDATE — a mesma trava
 *            que a geração toma), avisa o principal pelo arquivo marcador e
 *            SEGURA por alguns segundos; só então gera o XML e comita. É a janela
 *            em que o principal tenta reivindicar a transmissão do XML velho;
 *  - plain → gera o XML na hora, sem segurar nada (a geração depois de o envio
 *            já ter reivindicado a transmissão).
 *
 * Imprime "DOC:<id do XML atual do lote>" ao terminar.
 *
 * Uso: php tiss_regenerate_child.php <batch_id> <arquivo_marcador> <segundos_de_pausa> [<modo>]
 */

use App\Domains\Tiss\Models\TissBatch;
use App\Domains\Tiss\Services\GenerateTissXmlService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $batchId, $marker, $holdSeconds] = $argv;
$mode                               = $argv[4] ?? 'hold';

try {
    $batch   = TissBatch::query()->withoutGlobalScopes()->findOrFail($batchId);
    $service = app(GenerateTissXmlService::class);

    if ($mode === 'plain') {
        $service->generateBatch($batch);
    } else {
        DB::transaction(function () use ($batch, $batchId, $marker, $holdSeconds, $service): void {
            TissBatch::query()->withoutGlobalScopes()->whereKey($batchId)->lockForUpdate()->firstOrFail();

            file_put_contents($marker, 'holding');
            usleep((int) ((float) $holdSeconds * 1_000_000));

            // Statement timeout curto = se houver deadlock, este lado também falha em vez de pendurar.
            DB::statement("set local statement_timeout = '15s'");
            $service->generateBatch($batch);
        });
    }

    fwrite(STDOUT, 'DOC:' . TissBatch::query()->withoutGlobalScopes()->whereKey($batchId)->value('xml_document_id') . "\n");
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");

    exit(1);
}
