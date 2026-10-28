<?php

declare(strict_types=1);

/**
 * Processo FILHO do RecallEvaluationConcurrencyTest: "outra requisição" do recall numa sessão PostgreSQL própria.
 * Faz a operação DENTRO de uma transação, avisa o principal pelo arquivo marcador e SEGURA a transação por alguns
 * segundos antes de comitar — a janela em que o principal tenta a operação concorrente.
 *
 * Uso: php recall_child.php <modo> <entity_id> <alvo> <arquivo_marcador> <segundos_de_pausa> [<hoje Y-m-d>]
 *   modos: evaluate (alvo = paciente) · dismiss (alvo = linha de retorno) · claim (alvo = quantas marcas reivindicar)
 * Em caso de sucesso imprime "OK" (e, em claim, os pacientes reivindicados: "OK id,id").
 */

use App\Domains\Recall\Services\{RecallActionService, RecallEvaluator};
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $mode, $entityId, $target, $marker, $holdSeconds] = $argv;
$today                                               = CarbonImmutable::parse($argv[6] ?? '2026-02-01');

try {
    $output = DB::transaction(function () use ($mode, $entityId, $target, $marker, $holdSeconds, $today): string {
        $output = 'OK';

        switch ($mode) {
            case 'evaluate':
                app(RecallEvaluator::class)->evaluatePatient($entityId, $target, $today);

                break;
            case 'dismiss':
                app(RecallActionService::class)->dismiss($entityId, $target, 'Dispensado pelo processo filho do teste.');

                break;
            case 'claim':
                $claimed = DB::select(
                    'DELETE FROM recall_dirty_patients WHERE (entity_id, patient_id) IN ('
                    . 'SELECT entity_id, patient_id FROM recall_dirty_patients ORDER BY marked_at LIMIT ? FOR UPDATE SKIP LOCKED'
                    . ') RETURNING patient_id',
                    [(int) $target],
                );
                $output .= ' ' . implode(',', array_map(static fn (object $row): string => (string) $row->patient_id, $claimed));

                break;
            default:
                throw new InvalidArgumentException("modo desconhecido: {$mode}");
        }

        file_put_contents($marker, 'holding');
        usleep((int) ((float) $holdSeconds * 1_000_000));

        return $output;
    });

    fwrite(STDOUT, $output . "\n");
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");

    exit(1);
}
