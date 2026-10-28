<?php

declare(strict_types=1);

/**
 * Processo FILHO do ScheduleSeriesRescheduleRaceTest: "outra requisição" que mexe na série numa sessão PostgreSQL
 * própria. Toma a trava da SÉRIE (a mesma de "Cancelar as futuras", do acréscimo de sessão e de "Reagendar a série"),
 * altera a série DENTRO da transação, avisa o principal pelo arquivo marcador DEPOIS de a alteração estar feita e
 * SEGURA a transação por alguns segundos antes de comitar — a janela em que o principal tenta reagendar a série.
 *
 * Uso: php series_reschedule_child.php <entidade_id> <serie_id> <modo> <argumento> <arquivo_marcador> <segundos_de_pausa>
 *   modo `cancel` — argumento: id da sessão a cancelar (a série ENCOLHE);
 *   modo `grow`   — argumento: data/hora da sessão nova e agendada a acrescentar (a série CRESCE);
 *   modo `move`   — argumento: "<id>|<data/hora>" muda a data de uma sessão (uma remanescente MUDA);
 *   modo `batch`  — argumento: "<id_da_ancora>|<data/hora>" roda o "Reagendar a série" de verdade (ScheduleSeriesRescheduler)
 *                   e PARA, já com as travas de cirurgia, cota, série e linhas tomadas, imediatamente antes de tomar a
 *                   trava do código SDL do 1º INSERT — a janela em que outra escrita (store() de série) disputa a cota.
 * Em caso de sucesso imprime "OK <modo>" (no `batch`, "OK batch <sessões movidas>").
 */

use App\Models\Schedule;
use App\Services\Schedules\ScheduleSeriesRescheduler;
use App\Services\ScheduleSeriesService;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $entityId, $seriesId, $mode, $argument, $marker, $holdSeconds] = $argv;

try {
    if ($mode === 'batch') {
        [$anchorId, $newStart] = explode('|', $argument);
        $codeLock              = Schedule::codeLockKey($entityId);
        $paused                = false;

        DB::beforeExecuting(function (string $sql, array $bindings) use (&$paused, $codeLock, $marker, $holdSeconds): void {
            if (! $paused && str_contains($sql, 'pg_advisory_xact_lock') && ($bindings[0] ?? null) === $codeLock) {
                $paused = true;
                file_put_contents($marker, 'holding');
                usleep((int) ((float) $holdSeconds * 1_000_000));
            }
        });

        $anchor = Schedule::query()->withoutGlobalScopes()->findOrFail($anchorId);
        $result = app(ScheduleSeriesRescheduler::class)->reschedule($anchor, Carbon::parse($newStart), null, null, null, null);

        fwrite(STDOUT, "OK batch {$result['updated']}\n");

        exit(0);
    }

    DB::transaction(function () use ($entityId, $seriesId, $mode, $argument, $marker, $holdSeconds): void {
        ScheduleSeriesService::lockSeries($entityId, $seriesId);

        match ($mode) {
            'cancel' => DB::table('schedules')->where('id', $argument)->update(['situation' => 9, 'updated_at' => now()]),
            'grow'   => (function () use ($entityId, $seriesId, $argument): void {
                $model = Schedule::query()->withoutGlobalScopes()->where('entity_id', $entityId)->where('series_id', $seriesId)->orderBy('date_time')->firstOrFail();
                Schedule::query()->withoutGlobalScopes()->create([
                    'entity_id' => $entityId, 'doctor_id' => $model->doctor_id, 'full_name' => $model->full_name,
                    'date_time' => $argument, 'situation' => 1, 'active' => true,
                    'series_id' => $seriesId, 'session_number' => 6, 'session_total' => $model->session_total,
                ]);
            })(),
            'move' => (function () use ($argument): void {
                [$id, $at] = explode('|', $argument);
                DB::table('schedules')->where('id', $id)->update(['date_time' => $at, 'updated_at' => now()]);
            })(),
            default => throw new InvalidArgumentException("modo desconhecido: {$mode}"),
        };

        file_put_contents($marker, 'holding');
        usleep((int) ((float) $holdSeconds * 1_000_000));
    });

    fwrite(STDOUT, "OK {$mode}\n");
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");

    exit(1);
}
