<?php

declare(strict_types=1);

namespace App\Domains\Recall\Services;

use App\Enums\FeatureKey;
use App\Models\Entity;
use App\Services\FeatureGateService;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * A conciliação (rede de segurança da outbox): reavalia, clínica por clínica, todo paciente que PODE ter
 * retorno (RecallCandidates). O evento é a otimização; isto é a correção — pega o que o evento não vê
 * (gravação em massa, importação, `saveQuietly`, relógio: um adiamento que venceu, uma tarefa que passou da
 * data) e conserta qualquer linha que tenha divergido.
 *
 * Pagina por id (keyset) em blocos pequenos: memória constante, e cada paciente é a SUA transação (um paciente
 * com problema não desfaz os outros). Sempre filtra `entity_id` explicitamente: o escopo de tenant não existe
 * em comando.
 */
final class RecallReconciler
{
    public const CHUNK = 200;

    public function __construct(
        private readonly RecallEvaluator $evaluator,
        private readonly FeatureGateService $features,
    ) {
    }

    /**
     * @return array{entities: int, patients: int, created: int, updated: int, closed: int, unchanged: int, failed: int}
     */
    public function reconcileAll(?CarbonImmutable $now = null): array
    {
        $total = $this->blank() + ['entities' => 0];

        Entity::query()->where('is_client', true)->orderBy('id')->chunkById(100, function ($entities) use (&$total, $now): void {
            foreach ($entities as $entity) {
                if (! $this->features->can((string) $entity->id, FeatureKey::HasPatientRecall)) {
                    continue;
                }

                $total['entities']++;

                foreach ($this->reconcileEntity((string) $entity->id, $now) as $key => $value) {
                    $total[$key] += $value;
                }
            }
        });

        return $total;
    }

    /**
     * @return array{patients: int, created: int, updated: int, closed: int, unchanged: int, failed: int}
     */
    public function reconcileEntity(string $entityId, ?CarbonImmutable $now = null, int $chunk = self::CHUNK): array
    {
        $stats = $this->blank();
        $today = $now ?? CarbonImmutable::now();
        $last  = null;

        // O que os protocolos adotados reconhecem: uma vez por clínica, não por bloco.
        $tracked = RecallCandidates::tracked($entityId);

        do {
            $ids = RecallCandidates::patients($entityId, $today, $tracked)
                ->when($last !== null, static fn ($query) => $query->where('p.id', '>', $last))
                ->orderBy('p.id')
                ->limit($chunk)
                ->pluck('p.id')
                ->all();

            foreach ($ids as $id) {
                $last = (string) $id;
                $stats['patients']++;

                try {
                    foreach ($this->evaluator->evaluatePatient($entityId, (string) $id, $now) as $key => $value) {
                        $stats[$key] += $value;
                    }
                } catch (Throwable $e) {
                    report($e);
                    $stats['failed']++;
                }
            }
        } while (count($ids) === $chunk);

        return $stats;
    }

    /** @return array{patients: int, created: int, updated: int, closed: int, unchanged: int, failed: int} */
    private function blank(): array
    {
        return ['patients' => 0, 'created' => 0, 'updated' => 0, 'closed' => 0, 'unchanged' => 0, 'failed' => 0];
    }
}
