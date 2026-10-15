<?php

declare(strict_types=1);

/**
 * Processo FILHO do SurgeryScheduleConcurrencyTest: "outra secretária" que
 * AGENDA (ou REAGENDA) uma cirurgia pelo SurgeryScheduleService numa sessão
 * PostgreSQL própria. Depois de tomar TODOS os advisory locks dos recursos e a
 * trava da linha do caso, avisa o principal e SEGURA a transação por alguns
 * segundos — a janela em que o principal tenta ocupar a mesma sala/horário.
 *
 * Uso: php surgery_schedule_child.php <case_id> <schedule|reschedule> <starts_at> <facility_id> <room_id|-> <duration> <marker> <hold_seconds>
 */

use App\Exceptions\Surgery\SurgeryRuleException;
use App\Models\Surgery\SurgicalCase;
use App\Services\Surgery\SurgeryScheduleService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $caseId, $action, $startsAt, $facilityId, $roomId, $duration, $marker, $holdSeconds] = $argv;

try {
    $case = SurgicalCase::query()->withoutGlobalScopes()->findOrFail($caseId);

    $paused = false;

    // A linha do caso é a ÚLTIMA trava do serviço (advisory locks vêm antes): pausar aqui
    // = o filho já segura sala, médicos, paciente e o caso.
    DB::listen(function (QueryExecuted $query) use (&$paused, $marker, $holdSeconds): void {
        $sql = strtolower($query->sql);

        if (! $paused && str_contains($sql, 'from "surgical_cases"') && str_contains($sql, 'for update')) {
            $paused = true;
            file_put_contents($marker, 'holding');
            usleep((int) ((float) $holdSeconds * 1_000_000));
        }
    });

    $data = [
        'starts_at'           => $startsAt,
        'duration_minutes'    => (int) $duration,
        'surgery_facility_id' => $facilityId,
        'surgery_room_id'     => $roomId === '-' ? null : $roomId,
    ];

    $service = app(SurgeryScheduleService::class);

    // Statement timeout curto: deadlock ou espera eterna falha este lado em vez de pendurar a suíte.
    DB::statement("set statement_timeout = '25s'");

    $action === 'reschedule' ? $service->reschedule($case, $data) : $service->schedule($case, $data);

    fwrite(STDOUT, "OK\n");
} catch (SurgeryRuleException $e) {
    fwrite(STDOUT, 'RULE:' . $e->status . ':' . ($e->context['conflicts'][0]['kind'] ?? '') . "\n");

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");

    exit(1);
}
