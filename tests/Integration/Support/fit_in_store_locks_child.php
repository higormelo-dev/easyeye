<?php

declare(strict_types=1);

/**
 * Processo FILHO do FitInLockOrderTest: os dois primeiros locks do cadastro
 * (SchedulesController::store) de um encaixe já Atendido, na ordem do
 * sistema — cota de encaixes do médico/turno, depois o lock paciente/dia — numa
 * sessão PostgreSQL própria. Toma a cota, avisa o principal, SEGURA por alguns
 * segundos e só então pede o lock paciente/dia; depois comita.
 *
 * É o outro lado do ciclo que a edição (PUT) formava quando tomava os mesmos
 * dois locks na ordem inversa.
 *
 * Uso: php fit_in_store_locks_child.php <doctor_id> <entity_id> <patient_id> <date_time> <arquivo_marcador> <segundos_de_pausa>
 */

use App\Models\Schedule;
use App\Services\ScheduleService;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $doctorId, $entityId, $patientId, $dateTime, $marker, $holdSeconds] = $argv;

try {
    DB::transaction(function () use ($doctorId, $entityId, $patientId, $dateTime, $marker, $holdSeconds): void {
        $at = Carbon::parse($dateTime);

        app(ScheduleService::class)->lockFitInQuota($doctorId, $at);

        file_put_contents($marker, 'holding');
        usleep((int) ((float) $holdSeconds * 1_000_000));

        // Statement timeout curto = se houver deadlock, este lado também falha em vez de pendurar.
        DB::statement("set local statement_timeout = '15s'");
        Schedule::lockPatientDay($entityId, $patientId, $at);
    });

    fwrite(STDOUT, "OK\n");
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");

    exit(1);
}
