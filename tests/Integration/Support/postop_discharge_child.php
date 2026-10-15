<?php

declare(strict_types=1);

/**
 * Processo FILHO do PostOpConcurrencyTest: "outra requisição" dando a alta do
 * MESMO caso. Entra como o cirurgião (usuário + clínica da sessão) e espera o
 * arquivo de largada para competir pela trava da linha do caso.
 *
 * Uso: php postop_discharge_child.php <case_id> <user_id> <entity_id> <arquivo_largada>
 */

use App\Models\Surgery\SurgicalCase;
use App\Models\User;
use App\Services\Surgery\PostOp\SurgeryDischargeService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;

require __DIR__ . '/../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $caseId, $userId, $entityId, $go] = $argv;

try {
    Auth::setUser(User::query()->findOrFail($userId));
    session(['selected_entity_id' => $entityId]);

    $case = SurgicalCase::query()->withoutGlobalScopes()->findOrFail($caseId);

    $deadline = microtime(true) + 30;

    while (! is_file($go) && microtime(true) < $deadline) {
        usleep(2_000);
    }

    $result = app(SurgeryDischargeService::class)->discharge($case, [
        'discharge_text' => 'Paciente evoluiu bem, sem intercorrências.',
        'follow_up_plan' => 'Retorno anual.',
    ]);

    echo $result->status->value;
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage());

    exit(1);
}
