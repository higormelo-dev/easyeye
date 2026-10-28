<?php

declare(strict_types=1);

/**
 * Processo FILHO do AttendanceIdentityRaceTest: uma importação de captura
 * (ExternalExamImportService::resolveAttendedScheduleId) no dia NOVO do
 * agendamento. Na ordem do sistema — lock paciente/dia primeiro, linha do
 * agendamento depois —, numa sessão PostgreSQL própria: toma o lock
 * paciente/dia, avisa o principal, SEGURA por alguns segundos e só então trava
 * a linha do agendamento (FOR UPDATE) e comita.
 *
 * É o outro lado do deadlock que a releitura de identidade do Atendido poderia
 * causar: se o principal, ao reiniciar, ainda segurasse a linha enquanto espera
 * este lock, os dois esperariam um ao outro.
 *
 * Uso: php schedule_day_lock_child.php <entity_id> <patient_id> <dia> <schedule_id> <arquivo_marcador> <segundos_de_pausa>
 */

use App\Models\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $entityId, $patientId, $day, $scheduleId, $marker, $holdSeconds] = $argv;

try {
    DB::transaction(function () use ($entityId, $patientId, $day, $scheduleId, $marker, $holdSeconds): void {
        Schedule::lockPatientDay($entityId, $patientId, $day);

        file_put_contents($marker, 'holding');
        usleep((int) ((float) $holdSeconds * 1_000_000));

        // Statement timeout curto = se houver deadlock, este lado também falha em vez de pendurar.
        DB::statement("set local statement_timeout = '15s'");
        Schedule::query()->withoutGlobalScopes()->whereKey($scheduleId)->lockForUpdate()->first();
    });

    fwrite(STDOUT, "OK\n");
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");

    exit(1);
}
