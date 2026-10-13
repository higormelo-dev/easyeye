<?php

declare(strict_types=1);

/**
 * Processo FILHO do MedicalRecordClinicalChildrenConcurrencyTest (corrida entre
 * execução e cancelamento de procedimento): faz a transição de verdade, numa
 * sessão PostgreSQL própria, e SEGURA a trava por alguns segundos logo depois
 * de tomá-la — a janela em que o processo principal tenta a transição
 * contrária com uma instância carregada ANTES. Terminado o intervalo, a
 * transição segue e comita.
 *
 * Modos:
 *  - done    → MedicalRecordProcedureExecutionService::markDone() (com 1 unidade
 *              do produto, se informado). Segura logo após a trava do PRONTUÁRIO.
 *  - cancel  → MedicalRecordProcedureExecutionService::cancel(). Idem.
 *  - foreign → UPDATE cru do procedimento para "executado", SEM passar pelo
 *              service nem pela trava do prontuário (só a do próprio registro).
 *
 * Uso: php procedure_transition_child.php <modo> <procedure_id> <entity_user_id> <texto> <arquivo_marcador> <segundos_de_pausa> [<product_id>]
 */

use App\Enums\MedicalRecordProcedureStatus;
use App\Models\{EntityUser, MedicalRecordProcedure};
use App\Services\MedicalRecordProcedureExecutionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $mode, $procedureId, $entityUserId, $text, $marker, $holdSeconds] = $argv;
$productId                                                           = $argv[7] ?? null;

$hold = static function () use ($marker, $holdSeconds): void {
    file_put_contents($marker, 'holding');
    usleep((int) ((float) $holdSeconds * 1_000_000));
};

try {
    if ($mode === 'foreign') {
        DB::transaction(function () use ($procedureId, $entityUserId, $text, $hold): void {
            DB::table('medical_record_procedures')->where('id', $procedureId)->update([
                'status'      => MedicalRecordProcedureStatus::Done->value,
                'executed_at' => now(),
                'executed_by' => $entityUserId,
                'notes'       => $text,
            ]);

            $hold();
        });

        fwrite(STDOUT, "OK\n");

        exit(0);
    }

    // A 1ª trava FOR UPDATE em medical_records é a do service (prontuário de origem).
    $held = false;

    DB::listen(function (QueryExecuted $query) use (&$held, $hold): void {
        $sql = strtolower($query->sql);

        if (! $held && str_contains($sql, 'for update') && str_contains($sql, 'medical_records')) {
            $held = true;
            $hold();
        }
    });

    $procedure = MedicalRecordProcedure::query()->withoutGlobalScopes()->findOrFail($procedureId);
    $service   = app(MedicalRecordProcedureExecutionService::class);

    if ($mode === 'done') {
        $items = $productId === null
            ? []
            : [['entity_product_id' => $productId, 'quantity' => 1.0, 'stock_lot_id' => null]];

        $service->markDone($procedure, EntityUser::query()->withoutGlobalScopes()->findOrFail($entityUserId), $items, $text);
    } else {
        $service->cancel($procedure, $text);
    }

    fwrite(STDOUT, "OK\n");
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");

    exit(1);
}
