<?php

declare(strict_types=1);

namespace App\Domains\Recall\Services;

use App\Enums\FeatureKey;
use App\Services\FeatureGateService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Drena a outbox (`recall_dirty_patients`): reavalia só os pacientes que um ato clínico marcou. A marcação
 * nasce na MESMA transação do ato (RecallOutbox::mark), então ato desfeito não deixa marca.
 *
 * O dreno REIVINDICA o lote com um DELETE ... RETURNING de transação curta (`FOR UPDATE SKIP LOCKED`: dois
 * drenos nunca pegam o mesmo paciente) e avalia FORA de qualquer trava — quem grava prontuário/agenda nunca
 * espera a avaliação. Marca feita DEPOIS da reivindicação recria a linha e vale na próxima rodada; só entram
 * no lote marcas com `marked_at` até o INÍCIO da rodada (marcação constante não prende o dreno num laço).
 * Falha na avaliação remarca UMA vez (motivo `retry`); depois disso a conciliação noturna cobre — um
 * paciente que sempre falha não vira laço nem enxurrada de relatórios.
 */
final class RecallDirtyDrainer
{
    public const BATCH = 100;

    /** A nova tentativa de quem falhou só entra numa rodada ao menos tantos minutos depois. */
    public const RETRY_AFTER_MINUTES = 5;

    public function __construct(
        private readonly RecallEvaluator $evaluator,
        private readonly FeatureGateService $features,
    ) {
    }

    /**
     * @return array{claimed: int, evaluated: int, skipped: int, failed: int}
     */
    public function drain(int $max = 1000, ?CarbonImmutable $now = null): array
    {
        $startedAt = CarbonImmutable::now();
        $stats     = ['claimed' => 0, 'evaluated' => 0, 'skipped' => 0, 'failed' => 0];
        $allowed   = [];

        while ($stats['claimed'] < $max) {
            $batch = $this->claim($startedAt, min(self::BATCH, $max - $stats['claimed']));

            if ($batch === []) {
                break;
            }

            foreach ($batch as $row) {
                $stats['claimed']++;
                $entityId  = (string) $row->entity_id;
                $patientId = (string) $row->patient_id;

                // A clínica sem a feature no plano não tem recall: a marca some (o plano que sobe é coberto
                // pela conciliação, que avalia a clínica toda).
                $allowed[$entityId] ??= $this->features->can($entityId, FeatureKey::HasPatientRecall);

                if (! $allowed[$entityId]) {
                    $stats['skipped']++;

                    continue;
                }

                try {
                    $this->evaluator->evaluatePatient($entityId, $patientId, $now);
                    $stats['evaluated']++;
                } catch (Throwable $e) {
                    report($e);
                    $stats['failed']++;

                    if ((string) $row->reason !== 'retry') {
                        RecallOutbox::mark($entityId, $patientId, 'retry', CarbonImmutable::now()->addMinutes(self::RETRY_AFTER_MINUTES));
                    }
                }
            }
        }

        return $stats;
    }

    /**
     * @return list<object{entity_id: string, patient_id: string, reason: string}>
     */
    private function claim(CarbonImmutable $startedAt, int $limit): array
    {
        if (DB::getDriverName() === 'pgsql') {
            return DB::select(
                'DELETE FROM recall_dirty_patients WHERE (entity_id, patient_id) IN ('
                . 'SELECT entity_id, patient_id FROM recall_dirty_patients WHERE marked_at <= ? ORDER BY marked_at LIMIT ? FOR UPDATE SKIP LOCKED'
                . ') RETURNING entity_id, patient_id, reason',
                [$startedAt, $limit],
            );
        }

        return DB::transaction(static function () use ($startedAt, $limit): array {
            $rows = DB::table('recall_dirty_patients')->where('marked_at', '<=', $startedAt)->orderBy('marked_at')->limit($limit)->lockForUpdate()->get(['entity_id', 'patient_id', 'reason'])->all();

            foreach ($rows as $row) {
                DB::table('recall_dirty_patients')->where('entity_id', $row->entity_id)->where('patient_id', $row->patient_id)->delete();
            }

            return $rows;
        });
    }
}
