<?php

declare(strict_types=1);

/**
 * Processo FILHO do SurgeryCaseTransitionRaceTest: faz uma transição do caso
 * cirúrgico de verdade, numa sessão PostgreSQL própria, e SEGURA a trava da
 * linha do caso (FOR UPDATE) por alguns segundos logo depois de tomá-la — a
 * janela em que o processo principal tenta a transição contrária com uma
 * instância/tela carregada ANTES. Passado o intervalo, a transição segue e comita.
 *
 * Modos: request | authorize | authorize_procedures (resposta da guia de internação: só os procedimentos) | cancel | suspend.
 *
 * Uso: php surgery_case_child.php <modo> <case_id> <arquivo_marcador> <segundos_de_pausa>
 */

use App\Models\Surgery\SurgicalCase;
use App\Services\Surgery\{SurgeryAuthorizationService, SurgicalCaseService};
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $mode, $caseId, $marker, $holdSeconds] = $argv;

$held = false;

DB::listen(function (QueryExecuted $query) use (&$held, $marker, $holdSeconds): void {
    $sql = strtolower($query->sql);

    if (! $held && str_contains($sql, 'for update') && str_contains($sql, 'surgical_cases')) {
        $held = true;
        file_put_contents($marker, 'holding');
        usleep((int) ((float) $holdSeconds * 1_000_000));
    }
});

try {
    $case = SurgicalCase::query()->withoutGlobalScopes()->findOrFail($caseId);

    match ($mode) {
        'request'              => app(SurgeryAuthorizationService::class)->request($case),
        'authorize'            => app(SurgeryAuthorizationService::class)->record($case, ['type' => 'authorized', 'authorization_number' => 'CHILD-1']),
        'authorize_procedures' => app(SurgeryAuthorizationService::class)->record($case, [
            'type'                 => 'authorized',
            'authorization_number' => 'CHILD-INT',
            'scope'                => $case->procedures()->get()->map(fn ($p) => ['kind' => 'procedure', 'id' => (string) $p->getKey()])->all(),
        ]),
        'cancel'  => app(SurgicalCaseService::class)->cancel($case, 'Cancelado pelo filho'),
        'suspend' => app(SurgicalCaseService::class)->suspend($case, 'Suspenso pelo filho'),
    };

    fwrite(STDOUT, "OK\n");
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");

    exit(1);
}
