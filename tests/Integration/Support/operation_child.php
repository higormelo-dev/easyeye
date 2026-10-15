<?php

declare(strict_types=1);

/**
 * Processo FILHO dos testes de concorrência do M4 (cirurgia realizada /
 * implante): "outra requisição" que executa o service numa sessão PostgreSQL
 * própria e SEGURA a transação, depois de um ponto combinado, enquanto o
 * principal faz a operação concorrente pelo HTTP de verdade.
 *
 * Ações (1º argumento) e onde a transação é segurada:
 *  - register-after-stock   → registra implante; segura logo depois do INSERT da
 *                             baixa de estoque (travas de produto e lote na mão);
 *  - register-after-serial  → registra implante; segura logo depois do lock
 *                             advisory da série (antes de baixar o estoque);
 *  - perform-after-write    → registra a cirurgia como realizada; segura logo
 *                             depois do INSERT do ato (linha do caso travada).
 *
 * Uso: php operation_child.php <acao> <case_id> <doctor_id> <arquivo_marcador> <segundos> <json_payload>
 * Saída: "OK" (stdout) ou "<Classe>: <mensagem>" (stderr, exit 1).
 */

use App\Models\Doctor;
use App\Models\Surgery\SurgicalCase;
use App\Services\Surgery\{SurgeryImplantService, SurgeryPerformService};
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $action, $caseId, $doctorId, $marker, $holdSeconds, $json] = $argv;

$payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

$needle = match ($action) {
    'register-after-stock'  => 'insert into "stock_movements"',
    'register-after-serial' => 'pg_advisory_xact_lock',
    'perform-after-write'   => 'insert into "surgical_operations"',
    default                 => throw new InvalidArgumentException("ação desconhecida: {$action}"),
};

$paused = false;

DB::listen(function (QueryExecuted $query) use (&$paused, $needle, $marker, $holdSeconds): void {
    if (! $paused && str_contains(strtolower($query->sql), $needle)) {
        $paused = true;
        file_put_contents($marker, 'holding');
        usleep((int) ((float) $holdSeconds * 1_000_000));
    }
});

try {
    $case   = SurgicalCase::query()->withoutGlobalScopes()->findOrFail($caseId);
    $doctor = Doctor::query()->findOrFail($doctorId);

    DB::statement("set statement_timeout = '25s'");

    if ($action === 'perform-after-write') {
        app(SurgeryPerformService::class)->perform($case, $payload, $doctor);
    } else {
        app(SurgeryImplantService::class)->register($case, $payload, $doctor);
    }

    fwrite(STDOUT, "OK\n");
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");

    exit(1);
}
