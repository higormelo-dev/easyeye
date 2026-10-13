<?php

declare(strict_types=1);

/**
 * Processo FILHO do TissReturnImportRaceTest: "outra requisição" que IMPORTA o mesmo
 * arquivo de retorno TISS (ReceiveTissResponseService::receive) numa sessão
 * PostgreSQL própria, sem sessão HTTP nem tenant — como um job ou o comando.
 *
 * Usa a fila "sync" do ambiente de teste, então o despacho automático do job
 * (config tiss.returns.auto_process) é desligado aqui: quem processa é o
 * `processSynchronously` pedido no 4º argumento, e só ele.
 *
 * Uso: php tiss_receive_return_child.php <entity_id> <operator_id> <caminho_do_xml> <sync|async>
 * Saída: RETURN:<id>|DUP:<0|1>|STATUS:<status do retorno>
 */

use App\Domains\Tiss\Services\ReceiveTissResponseService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $entityId, $operatorId, $xmlPath, $mode] = $argv;

try {
    // Rede de segurança contra pendurar a suíte se a trava nunca vier.
    DB::statement("set statement_timeout = '30s'");
    config(['tiss.returns.auto_process' => false]);

    $received = app(ReceiveTissResponseService::class)->receive(
        entityId: $entityId,
        operatorId: $operatorId,
        xmlContent: (string) file_get_contents($xmlPath),
        processSynchronously: $mode === 'sync',
    );

    fwrite(STDOUT, sprintf(
        "RETURN:%s|DUP:%d|STATUS:%s\n",
        $received->return->id,
        $received->alreadyImported ? 1 : 0,
        $received->return->status->value,
    ));
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");

    exit(1);
}
