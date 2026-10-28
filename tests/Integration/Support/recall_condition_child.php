<?php

declare(strict_types=1);

/**
 * Processo FILHO do RecallConditionConcurrencyTest: "outra requisição" sobre as condições crônicas de um paciente
 * numa sessão PostgreSQL própria. Faz a operação DENTRO de uma transação, avisa o principal pelo arquivo marcador e
 * SEGURA a transação por alguns segundos antes de comitar — a janela em que o principal tenta a operação concorrente.
 *
 * Uso: php recall_condition_child.php <modo> <id> <arquivo_marcador> <segundos_de_pausa>
 *   ambiente: RCC_ENTITY, RCC_USER (e RCC_PATIENT nos modos add/evaluate)
 *   modos (id = condição): confirm · reject · discharge
 *         (id = paciente): add (olho direito, protocolo glaucoma) · evaluate ·
 *                          evaluate-late (toma a trava do paciente, SEGURA, e só depois reavalia — o avaliador que
 *                          já está dentro quando o ato do médico chega)
 * Em caso de sucesso imprime "OK".
 */

use App\Domains\Recall\Services\{PatientConditionService, RecallEvaluator};
use App\Domains\Recall\Support\RecallLocks;
use App\Enums\Eye;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $mode, $id, $marker, $holdSeconds] = $argv;

try {
    DB::transaction(function () use ($mode, $id, $marker, $holdSeconds): void {
        $entity  = (string) getenv('RCC_ENTITY');
        $actor   = User::query()->findOrFail((string) getenv('RCC_USER'));
        $service = app(PatientConditionService::class);
        $today   = CarbonImmutable::parse('2026-02-01');
        $held    = false;

        switch ($mode) {
            case 'confirm':
                $service->confirm($entity, $id, $actor, $today);

                break;
            case 'reject':
                $service->reject($entity, $id, $actor, $today);

                break;
            case 'discharge':
                $service->discharge($entity, $id, 'Alta dada pelo processo filho do teste.', $actor, $today);

                break;
            case 'add':
                $service->add($entity, $id, 'glaucoma', Eye::Right, null, $actor, $today);

                break;
            case 'evaluate':
                app(RecallEvaluator::class)->evaluatePatient($entity, $id, $today);

                break;
            case 'evaluate-late':
                RecallLocks::patient($id);
                file_put_contents($marker, 'holding');
                usleep((int) ((float) $holdSeconds * 1_000_000));
                $held = true;

                app(RecallEvaluator::class)->evaluatePatient($entity, $id, $today);

                break;
            default:
                throw new InvalidArgumentException("modo desconhecido: {$mode}");
        }

        if (! $held) {
            file_put_contents($marker, 'holding');
            usleep((int) ((float) $holdSeconds * 1_000_000));
        }
    });

    fwrite(STDOUT, "OK\n");
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");

    exit(1);
}
