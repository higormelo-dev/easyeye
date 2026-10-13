<?php

declare(strict_types=1);

/**
 * Processo FILHO do AttendanceReconciliationDeadlockTest: roda
 * ScheduleService::changeSituation(<agendamento>, Atendido) de verdade, numa
 * sessão PostgreSQL própria, e segura a trava da linha do agendamento por
 * alguns segundos logo depois dela ser tomada — a janela em que o processo
 * principal faz a transição do OUTRO agendamento do mesmo paciente/dia.
 *
 * Uso: php attended_child.php <schedule_id> <arquivo_marcador> <segundos_de_pausa>
 */

use App\Enums\ScheduleSituation;
use App\Models\Schedule;
use App\Services\ScheduleService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $scheduleId, $marker, $holdSeconds] = $argv;

$held = false;

// A 1ª trava FOR UPDATE em schedules é a da linha deste agendamento
// (refreshSituationUnderLock). Logo depois, avisa o principal e fica segurando.
DB::listen(function (QueryExecuted $query) use (&$held, $marker, $holdSeconds): void {
    $sql = strtolower($query->sql);

    if (! $held && str_contains($sql, 'for update') && str_contains($sql, 'schedules')) {
        $held = true;
        file_put_contents($marker, 'holding');
        usleep((int) ((float) $holdSeconds * 1_000_000));
    }
});

try {
    app(ScheduleService::class)->changeSituation(
        Schedule::query()->withoutGlobalScopes()->findOrFail($scheduleId),
        ScheduleSituation::Attended,
        null,
    );

    fwrite(STDOUT, "OK\n");
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");

    exit(1);
}
