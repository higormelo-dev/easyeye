<?php

declare(strict_types=1);

/**
 * Processo FILHO do AttendedOppositeMovesTest: "outra requisição" que EDITA um
 * agendamento Atendido mudando-o de dia (o PUT da agenda), no fluxo do
 * SchedulesController::update — travas de identidade, UPDATE da linha e
 * reconciliação das capturas (reconcileExamLinks) —, numa sessão PostgreSQL
 * própria. Depois do UPDATE avisa o principal e SEGURA a transação por alguns
 * segundos, só então reconcilia e comita: a janela em que o principal faz o
 * movimento OPOSTO do mesmo paciente.
 *
 * Modos (5º argumento, padrão after-update):
 *  - after-update      → segura a transação depois do UPDATE da linha;
 *  - after-first-lock  → segura logo depois do PRIMEIRO lock paciente/dia (a
 *                        janela em que o principal pede o par de locks na ordem
 *                        inversa, se alguém não ordenar).
 *
 * Uso: php schedule_edit_child.php <schedule_id> <novo_date_time> <arquivo_marcador> <segundos_de_pausa> [<modo>]
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

[, $scheduleId, $newDateTime, $marker, $holdSeconds] = $argv;
$mode                                                = $argv[5] ?? 'after-update';

try {
    $schedule = Schedule::query()->withoutGlobalScopes()->findOrFail($scheduleId);
    $service  = app(ScheduleService::class);

    $attributes = [
        'patient_id' => $schedule->patient_id,
        'doctor_id'  => $schedule->doctor_id,
        'full_name'  => $schedule->full_name,
        'date_time'  => $newDateTime,
    ];

    $hold = static function () use ($marker, $holdSeconds): void {
        file_put_contents($marker, 'holding');
        usleep((int) ((float) $holdSeconds * 1_000_000));
    };

    if ($mode === 'after-first-lock') {
        $paused = false;

        DB::listen(function (QueryExecuted $query) use (&$paused, $hold): void {
            if (! $paused && str_contains(strtolower($query->sql), 'pg_advisory_xact_lock')) {
                $paused = true;
                $hold();
            }
        });
    }

    DB::transaction(function () use ($schedule, $service, $attributes, $mode, $hold): void {
        // Mesma ordem do controller: locks de identidade → UPDATE → reconciliação.
        $service->lockForEdit($schedule, ScheduleSituation::Attended, $attributes);

        $before = $service->examLinkIdentity($schedule);

        $schedule->update($attributes);

        if ($mode === 'after-update') {
            $hold();
        }

        // Statement timeout curto = se houver deadlock, este lado também falha em vez de pendurar.
        DB::statement("set local statement_timeout = '15s'");
        $service->reconcileExamLinks($schedule, $before);
    });

    fwrite(STDOUT, "OK\n");
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");

    exit(1);
}
