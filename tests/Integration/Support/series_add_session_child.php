<?php

declare(strict_types=1);

/**
 * Processo FILHO do ScheduleSeriesNumberingRaceTest: "outra requisição" que acrescenta uma sessão à série aberta
 * (ScheduleSeriesService::addSession) numa sessão PostgreSQL própria. Faz a operação DENTRO de uma transação, avisa o
 * principal pelo arquivo marcador DEPOIS de a sessão estar inserida e SEGURA a transação por alguns segundos antes de
 * comitar — a janela em que o principal tenta acrescentar a dele (a linha nova ainda não é visível a ele).
 *
 * Uso: php series_add_session_child.php <schedule_ancora_id> <data_hora> <arquivo_marcador> <segundos_de_pausa>
 * Em caso de sucesso imprime "OK <numero_da_sessao>".
 */

use App\Models\Schedule;
use App\Services\ScheduleSeriesService;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $anchorId, $dateTime, $marker, $holdSeconds] = $argv;

try {
    $number = DB::transaction(function () use ($anchorId, $dateTime, $marker, $holdSeconds): int {
        $anchor  = Schedule::query()->withoutGlobalScopes()->findOrFail($anchorId);
        $session = app(ScheduleSeriesService::class)->addSession($anchor, Carbon::parse($dateTime));

        file_put_contents($marker, 'holding');
        usleep((int) ((float) $holdSeconds * 1_000_000));

        return (int) $session->session_number;
    });

    fwrite(STDOUT, "OK {$number}\n");
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");

    exit(1);
}
