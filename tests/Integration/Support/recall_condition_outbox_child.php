<?php

declare(strict_types=1);

/**
 * Processo FILHO do ScheduleRecallLockOrderTest: o outro lado da ordem de locks do recall — o que PatientConditionService
 * faz ao confirmar/editar uma condição: toma a trava do PACIENTE (RecallLocks::patient), avisa o principal pelo arquivo
 * marcador, SEGURA por alguns segundos e só então grava o ato, que marca o paciente na outbox
 * (RecallOutbox::mark, a linha de recall_dirty_patients fica presa até o commit).
 *
 * Se o principal já segurasse a linha da outbox (INSERT do agendamento) enquanto espera a trava do paciente, os dois se
 * esperariam: deadlock (40P01).
 *
 * Uso: php recall_condition_outbox_child.php <entity_id> <patient_id> <arquivo_marcador> <segundos_de_pausa>
 */

use App\Domains\Recall\Support\RecallLocks;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $entityId, $patientId, $marker, $holdSeconds] = $argv;

try {
    DB::transaction(function () use ($entityId, $patientId, $marker, $holdSeconds): void {
        RecallLocks::patient($patientId);

        file_put_contents($marker, 'holding');
        usleep((int) ((float) $holdSeconds * 1_000_000));

        // Statement timeout curto = se houver deadlock, este lado também falha em vez de pendurar.
        DB::statement("set local statement_timeout = '15s'");

        // RecallOutbox::mark engole a exceção (savepoint + report): o deadlock apareceria só como "nada gravado".
        // Faz o mesmo INSERT direto para o erro subir.
        DB::statement(
            'INSERT INTO recall_dirty_patients (entity_id, patient_id, reason, marked_at) VALUES (?, ?, ?, ?) '
            . 'ON CONFLICT (entity_id, patient_id) DO UPDATE SET reason = EXCLUDED.reason, marked_at = EXCLUDED.marked_at',
            [$entityId, $patientId, 'condition', now()],
        );
    });

    fwrite(STDOUT, "OK\n");
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");

    exit(1);
}
