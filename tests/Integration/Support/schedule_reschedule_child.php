<?php

declare(strict_types=1);

/**
 * Processo FILHO do AttendanceIdentityRaceTest: "outra requisição" que remarca
 * o agendamento. Numa sessão PostgreSQL própria, grava o novo dia/horário
 * (o UPDATE trava a linha), avisa o principal e SEGURA a trava por alguns
 * segundos antes de comitar — a janela em que o principal, com a instância
 * carregada ANTES, tenta marcar o agendamento como Atendido.
 *
 * Uso: php schedule_reschedule_child.php <schedule_id> <novo_date_time> <arquivo_marcador> <segundos_de_pausa>
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $scheduleId, $newDateTime, $marker, $holdSeconds] = $argv;

try {
    DB::transaction(function () use ($scheduleId, $newDateTime, $marker, $holdSeconds): void {
        DB::table('schedules')->where('id', $scheduleId)->update(['date_time' => $newDateTime]);

        file_put_contents($marker, 'holding');
        usleep((int) ((float) $holdSeconds * 1_000_000));
    });

    fwrite(STDOUT, "OK\n");
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");

    exit(1);
}
