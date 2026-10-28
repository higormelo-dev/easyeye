<?php

declare(strict_types=1);

/**
 * Processo FILHO do TissReturnResendLockOrderTest: o reenvio forçado de uma
 * transmissão confirmada (SendTissBatchService::finalizeConfirmedResend) numa
 * sessão PostgreSQL própria. Na ordem do envio — lote PRIMEIRO, depois o protocolo
 * que ele atualiza:
 *
 *   1. abre a transação e TRAVA a linha do lote (FOR UPDATE);
 *   2. avisa o principal pelo arquivo marcador "locked";
 *   3. espera o sinal "go" do principal (que ele dá quando o processamento do
 *      retorno já escreveu no protocolo) — ou desiste depois de <espera> segundos;
 *   4. ATUALIZA o protocolo (acrescenta resend_marker ao details) e comita.
 *
 * Com o processamento do retorno na ordem INVERSA (protocolo primeiro, lote
 * depois) este filho e o principal ficam esperando um ao outro — deadlock, e o
 * PostgreSQL aborta um dos dois. Com a mesma ordem (lote primeiro) o principal
 * espera a trava do lote, o "go" nunca vem, e este filho conclui sozinho.
 *
 * Uso: php tiss_resend_protocol_child.php <batch_id> <protocol_id> <marcador_locked> <marcador_go> <espera_segundos>
 */

use App\Domains\Tiss\Models\TissBatch;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $batchId, $protocolId, $lockedMarker, $goMarker, $waitSeconds] = $argv;

try {
    DB::transaction(function () use ($batchId, $protocolId, $lockedMarker, $goMarker, $waitSeconds): void {
        // Statement timeout curto = se houver deadlock, este lado também falha em vez de pendurar.
        DB::statement("set local statement_timeout = '20s'");

        TissBatch::query()->withoutGlobalScopes()->whereKey($batchId)->lockForUpdate()->firstOrFail();

        file_put_contents($lockedMarker, 'locked');

        $deadline = microtime(true) + (float) $waitSeconds;

        while (! is_file($goMarker) && microtime(true) < $deadline) {
            usleep(50_000);
        }

        $details = json_decode((string) DB::table('tiss_protocols')->where('id', $protocolId)->value('details'), true) ?: [];

        DB::table('tiss_protocols')->where('id', $protocolId)->update([
            'details' => json_encode(array_merge($details, ['resend_marker' => 'filho'])),
        ]);
    });

    fwrite(STDOUT, "OK\n");
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");

    exit(1);
}
