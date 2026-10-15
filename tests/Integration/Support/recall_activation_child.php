<?php

declare(strict_types=1);

/**
 * Processo FILHO do RecallActivationConcurrencyTest: "outra requisição" da ativação de protocolo numa sessão
 * PostgreSQL própria. Faz a operação DENTRO de uma transação, avisa o principal pelo arquivo marcador e SEGURA a
 * transação por alguns segundos antes de comitar — a janela em que o principal tenta a operação concorrente.
 *
 * Uso: php recall_activation_child.php <modo> <id> <arquivo_marcador> <segundos_de_pausa>
 *   modos (id = protocolo; RAC_ENTITY, RAC_VERSION, RAC_USER, RAC_DOCTOR no ambiente): activate · deactivate
 *         (id = versão): suspend
 * Em caso de sucesso imprime "OK".
 */

use App\Domains\Recall\Models\RecallProtocolVersion;
use App\Domains\Recall\Services\{ProtocolActivationService, ProtocolGovernanceService};
use App\Models\{Doctor, Entity, User};
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $mode, $id, $marker, $holdSeconds] = $argv;

try {
    DB::transaction(function () use ($mode, $id, $marker, $holdSeconds): void {
        $activation = app(ProtocolActivationService::class);

        switch ($mode) {
            case 'activate':
                $activation->activate(
                    Entity::query()->findOrFail((string) getenv('RAC_ENTITY')),
                    $id,
                    (string) getenv('RAC_VERSION'),
                    ['tasks' => ['campimetria' => ['interval' => 7]]],
                    true,
                    User::query()->findOrFail((string) getenv('RAC_USER')),
                    Doctor::query()->findOrFail((string) getenv('RAC_DOCTOR')),
                );

                break;
            case 'deactivate':
                $activation->deactivate(
                    Entity::query()->findOrFail((string) getenv('RAC_ENTITY')),
                    $id,
                    'Desativado pelo processo filho do teste.',
                    User::query()->findOrFail((string) getenv('RAC_USER')),
                );

                break;
            case 'suspend':
                app(ProtocolGovernanceService::class)->suspend(RecallProtocolVersion::query()->findOrFail($id), 'Suspensa pelo processo filho do teste, com motivo longo.');

                break;
            default:
                throw new InvalidArgumentException("modo desconhecido: {$mode}");
        }

        file_put_contents($marker, 'holding');
        usleep((int) ((float) $holdSeconds * 1_000_000));
    });

    fwrite(STDOUT, "OK\n");
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");

    exit(1);
}
