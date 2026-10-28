<?php

declare(strict_types=1);

/**
 * Processo FILHO do TissFailureHandlerRaceTest: "outra requisição" que mexe no lote
 * TISS (a reivindicação ou o fechamento do envio, a geração do XML...) numa sessão
 * PostgreSQL própria.
 *
 * Abre a transação, TRAVA a linha do lote (FOR UPDATE — a mesma trava que o envio,
 * a geração do XML e as edições do faturamento tomam), grava o status pedido
 * (ainda sem comitar), avisa o principal pelo arquivo marcador e SEGURA a
 * transação por alguns segundos; só então comita. É a janela em que o principal
 * (o handler de falha de um job) lê o lote e decide.
 *
 * Uso: php tiss_batch_lock_child.php <batch_id> <arquivo_marcador> <segundos_de_pausa> <status>
 *      (<status> "-" = só segura a trava, sem mudar o status)
 */

use App\Domains\Tiss\Models\TissBatch;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $batchId, $marker, $holdSeconds, $status] = $argv;

try {
    DB::transaction(function () use ($batchId, $marker, $holdSeconds, $status): void {
        TissBatch::query()->withoutGlobalScopes()->whereKey($batchId)->lockForUpdate()->firstOrFail();

        if ($status !== '-') {
            DB::table('tiss_batches')->where('id', $batchId)->update(['status' => $status]);
        }

        file_put_contents($marker, 'holding');
        usleep((int) ((float) $holdSeconds * 1_000_000));
    });

    fwrite(STDOUT, 'STATUS:' . DB::table('tiss_batches')->where('id', $batchId)->value('status') . "\n");
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");

    exit(1);
}
