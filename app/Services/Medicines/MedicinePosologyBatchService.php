<?php

declare(strict_types=1);

namespace App\Services\Medicines;

use App\Domains\AI\Services\{AiPricingService, AiSystemPromptResolver, AiUsdBrlRate};
use App\Enums\AI\{AiProvider, AiRunMode};
use App\Enums\{ImportStatus, MedicinePosologySource};
use App\Models\{Medicine, MedicinePosologyBatch, MedicinePosologyBatchGroup, MedicinePresentation, User};
use App\Support\{AuditContext, TenantContext};
use Illuminate\Support\Facades\{Cache, DB, Log};
use Illuminate\Support\Sleep;
use Throwable;

/**
 * "Gerar posologia com IA (lote)" — Manager → Medicamentos.
 *
 * Decisões do dono:
 * - Alcance: só os itens do filtro atual SEM posologia (dose, frequência,
 *   duração e orientações vazias). Nunca sobrescreve: confere de novo, com
 *   trava de linha, na hora de gravar.
 * - Custo: itens iguais (princípio ativo + concentração + forma, sem caixa,
 *   acento nem espaço) = UMA chamada de IA aplicada a todos do grupo. Teto
 *   de chamadas por lote (config medicines.posology_batch.max_groups): o
 *   restante fica para um próximo lote.
 * - Gravação direta, marcada "gerada por IA — revisar" (posology_source=ai);
 *   ao salvar pelo modal de edição vira manual/revisada.
 * - Fila + progresso por WebSocket, um lote por vez, cancelável; falha de um
 *   grupo não para o lote (transitório: novas tentativas limitadas).
 *
 * Cada chamada é um run de plataforma (MedicinePosologyAiService::suggest):
 * prompt do servidor, saída sanitizada, custo real em ai_run_provider_calls
 * (Manager → Uso de IA) — o lote soma esse custo real.
 */
class MedicinePosologyBatchService
{
    /** Trava curta do "Iniciar" (dois cliques/abas não criam dois lotes). */
    private const START_LOCK = 'medicine-posology-batch:start';

    public function __construct(
        private readonly MedicineCatalogFilters $filters,
        private readonly MedicinePosologyAiService $posologyAi,
        private readonly AiPricingService $pricing,
        private readonly AiSystemPromptResolver $prompts,
        private readonly AiUsdBrlRate $usdBrl,
        private readonly TenantContext $tenant,
    ) {
    }

    public function maxGroups(): int
    {
        return max(1, (int) config('medicines.posology_batch.max_groups', 200));
    }

    public function running(): ?MedicinePosologyBatch
    {
        return MedicinePosologyBatch::query()
            ->whereIn('status', [ImportStatus::Pending->value, ImportStatus::Processing->value])
            ->latest()
            ->first();
    }

    /**
     * Itens SEM posologia do filtro, agrupados (ordem do filtro: o grupo
     * entra na posição do seu primeiro item).
     *
     * @param array<string, mixed> $filters normalizados (MedicineCatalogFilters::normalize)
     *
     * @return array{medicines: int, groups: list<array{key: string, label: string, ids: list<string>, representative: Medicine}>}
     */
    public function plan(array $filters): array
    {
        $query = $this->filters->query($filters)
            ->leftJoin('medicine_presentations as mp', 'mp.id', '=', 'medicines.medicine_presentation_id')
            ->select([
                'medicines.id', 'medicines.name', 'medicines.active_ingredient', 'medicines.concentration',
                'medicines.pharmaceutical_form', 'medicines.medicine_presentation_id', 'medicines.presentation_detail',
                'medicines.therapeutic_class', 'medicines.is_ophthalmic', 'mp.name as presentation_name',
            ]);

        $this->filters->whereWithoutPosology($query);

        $groups = [];
        $count  = 0;

        foreach ($query->cursor() as $medicine) {
            $count++;
            $key = $this->groupKey($medicine);

            if (! isset($groups[$key])) {
                // Representante já com a apresentação (sem consulta por item).
                $medicine->setRelation('presentation', $medicine->presentation_name
                    ? (new MedicinePresentation())->forceFill(['name' => $medicine->presentation_name])
                    : null);

                $groups[$key] = ['key' => $key, 'label' => $this->groupLabel($medicine), 'ids' => [], 'representative' => $medicine];
            }

            $groups[$key]['ids'][] = (string) $medicine->id;
        }

        return ['medicines' => $count, 'groups' => array_values($groups)];
    }

    /**
     * Prévia do modal de confirmação: contagens, teto e custo estimado por IA.
     *
     * @param array<string, mixed> $filters normalizados
     *
     * @return array<string, mixed>
     */
    public function preview(array $filters): array
    {
        $plan      = $this->plan($filters);
        $limit     = $this->maxGroups();
        $batch     = array_slice($plan['groups'], 0, $limit);
        $contexts  = array_map(fn (array $g) => $this->posologyAi->catalogContext($g['representative']), $batch);
        $rate      = $this->usdBrl->current();
        $estimates = [];

        foreach ($this->posologyAi->providers() as $provider) {
            $usd = $this->estimateCostUsd($provider['code'], $provider['model'], $contexts);

            $estimates[$provider['code']] = [
                'usd' => $usd,
                'brl' => $usd === null ? null : round($usd * $rate['rate'], 4),
            ];
        }

        return [
            'medicines'        => $plan['medicines'],
            'groups'           => count($plan['groups']),
            'batch_groups'     => count($batch),
            'batch_medicines'  => array_sum(array_map(fn (array $g) => count($g['ids']), $batch)),
            'remaining_groups' => max(0, count($plan['groups']) - $limit),
            'cap'              => $limit,
            'estimates'        => $estimates,
            'usd_brl'          => ['rate' => $rate['rate'], 'is_fallback' => $rate['is_fallback']],
        ];
    }

    /**
     * Custo estimado (US$) das chamadas na IA escolhida — mesma heurística de
     * tokens do AiRunEstimateService (texto/4) e a tabela de preços do
     * modelo. Null quando o modelo não tem preço cadastrado.
     *
     * @param list<array<string, mixed>> $contexts um por chamada
     */
    public function estimateCostUsd(string $providerCode, ?string $model, array $contexts): ?float
    {
        $provider = AiProvider::tryFrom($providerCode);

        if ($provider === null || blank($model) || $contexts === [] || ! $this->pricing->hasPriceFor($provider, (string) $model)) {
            return $contexts === [] ? 0.0 : null;
        }

        $prompt = mb_strlen($this->prompts->resolve(MedicinePosologyAiService::WORKFLOW)) + mb_strlen((string) __('ai.medicine_posology_user_prompt'));
        $input  = 0;
        $output = 0;

        foreach ($contexts as $context) {
            $json   = json_encode(['medicamento' => array_filter($context, fn ($v) => $v !== null && $v !== '')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $tokens = max(120, (int) ceil(($prompt + mb_strlen($json !== false ? $json : '')) / 4));
            $input += $tokens;
            $output += max(100, min(600, intdiv($tokens, 2) + 120));
        }

        $estimate = $this->pricing->estimateCredits(MedicinePosologyAiService::WORKFLOW, AiRunMode::Economy, [[
            'provider'         => $provider,
            'model'            => (string) $model,
            'input_tokens'     => $input,
            'output_tokens'    => $output,
            'reasoning_tokens' => 12 * count($contexts),
        ]]);

        return round($estimate->rawCostUsd, 6);
    }

    /**
     * Cria o lote (plano gravado = o que o admin confirmou) — o controller
     * coloca na fila. Um lote por vez.
     *
     * @param array<string, mixed> $filters normalizados
     *
     * @throws MedicinePosologyAiException    IA indisponível/escolha inválida
     * @throws MedicinePosologyBatchException já há lote rodando / nada a fazer
     */
    public function start(array $filters, ?string $provider, User $user, string $saasEntityId): MedicinePosologyBatch
    {
        $chosen = $this->posologyAi->resolveProvider($provider);

        $batch = Cache::lock(self::START_LOCK, 60)->get(function () use ($filters, $chosen, $user, $saasEntityId) {
            if ($this->running() !== null) {
                throw MedicinePosologyBatchException::running();
            }

            $plan = $this->plan($filters);

            if ($plan['groups'] === []) {
                throw MedicinePosologyBatchException::nothingToDo();
            }

            $limit  = $this->maxGroups();
            $groups = array_slice($plan['groups'], 0, $limit);

            $estimate = $this->estimateCostUsd(
                $chosen['code'],
                $chosen['model'],
                array_map(fn (array $g) => $this->posologyAi->catalogContext($g['representative']), $groups),
            );

            return DB::transaction(function () use ($filters, $chosen, $user, $saasEntityId, $plan, $groups, $limit, $estimate) {
                $batch = MedicinePosologyBatch::query()->create([
                    'user_id'            => $user->id,
                    'entity_id'          => $saasEntityId,
                    'status'             => ImportStatus::Pending,
                    'provider'           => $chosen['code'],
                    'filters'            => $filters,
                    'group_limit'        => $limit,
                    'total_medicines'    => array_sum(array_map(fn (array $g) => count($g['ids']), $groups)),
                    'total_groups'       => count($groups),
                    'remaining_groups'   => max(0, count($plan['groups']) - $limit),
                    'estimated_cost_usd' => $estimate,
                ]);

                foreach ($groups as $position => $group) {
                    MedicinePosologyBatchGroup::query()->create([
                        'batch_id'     => $batch->id,
                        'position'     => $position,
                        'group_key'    => mb_substr($group['key'], 0, 1000),
                        'label'        => mb_substr($group['label'], 0, 500),
                        'medicine_ids' => $group['ids'],
                        'status'       => MedicinePosologyBatchGroup::STATUS_PENDING,
                    ]);
                }

                return $batch;
            });
        });

        // Trava ocupada = outro "Iniciar" criando um lote neste instante.
        if ($batch === false) {
            throw MedicinePosologyBatchException::running();
        }

        return $batch;
    }

    /** Cancelado pelo admin: o job para antes do próximo grupo e nada mais é gravado. */
    public function cancel(MedicinePosologyBatch $batch): void
    {
        $batch->update([
            'status'      => ImportStatus::Cancelled,
            'phase'       => null,
            'error'       => __('manager_medicines.batch_cancelled_reason'),
            'finished_at' => now(),
        ]);
    }

    /**
     * Processa grupos pendentes por até $budgetSeconds (pelo menos um).
     *
     * @return bool true = ainda há grupos (o job agenda a continuação)
     */
    public function process(MedicinePosologyBatch $batch, int $budgetSeconds): bool
    {
        $startedAt = microtime(true);
        $processed = 0;
        $delayMs   = max(0, (int) config('medicines.posology_batch.delay_ms', 500));

        if ($batch->status === ImportStatus::Pending) {
            $batch->update([
                'status'     => ImportStatus::Processing,
                'phase'      => MedicinePosologyBatch::PHASE_GENERATING,
                'started_at' => $batch->started_at ?? now(),
            ]);
        }

        // Auditoria: alterações nos itens em nome de quem disparou o lote.
        $previousActor = AuditContext::userId();
        AuditContext::setUserId($batch->user_id ? (string) $batch->user_id : null);

        try {
            while (true) {
                $batch->refresh();

                if ($batch->status !== ImportStatus::Processing) {
                    return false; // cancelado (ou encerrado por outro worker)
                }

                $group = $batch->groups()
                    ->where('status', MedicinePosologyBatchGroup::STATUS_PENDING)
                    ->orderBy('position')
                    ->first();

                if ($group === null) {
                    $this->finish($batch);

                    return false;
                }

                if ($processed > 0 && microtime(true) - $startedAt >= $budgetSeconds) {
                    return true;
                }

                $called = $this->processGroup($batch, $group);
                $processed++;

                if ($this->tooManyConsecutiveFailures($batch)) {
                    $batch->update([
                        'status' => ImportStatus::Failed,
                        'phase'  => null,
                        'error'  => __('manager_medicines.batch_aborted_failures', [
                            'count'    => (int) config('medicines.posology_batch.max_consecutive_failures', 5),
                            'provider' => $batch->providerLabel(),
                        ]),
                        'finished_at' => now(),
                    ]);

                    return false;
                }

                // Throttle simples entre chamadas (limite de requisições do provedor).
                if ($called && $delayMs > 0) {
                    Sleep::for($delayMs)->milliseconds();
                }
            }
        } finally {
            AuditContext::setUserId($previousActor);
        }
    }

    /** Job morreu fora do serviço (timeout, worker): encerra para liberar um novo lote. */
    public function markFailed(MedicinePosologyBatch $batch): void
    {
        $batch->refresh();

        if (! $batch->isRunning()) {
            return;
        }

        $batch->update([
            'status'      => ImportStatus::Failed,
            'phase'       => null,
            'error'       => __('manager_medicines.batch_failed_generic'),
            'finished_at' => now(),
        ]);
    }

    /**
     * Um grupo: UMA chamada de IA (com novas tentativas em erro transitório)
     * aplicada a todos os itens do grupo ainda sem posologia.
     *
     * @return bool se chamou a IA
     */
    private function processGroup(MedicinePosologyBatch $batch, MedicinePosologyBatchGroup $group): bool
    {
        $ids     = array_values(array_map('strval', (array) $group->medicine_ids));
        $current = $this->filters->globalCatalog()->with('presentation:id,name')->whereIn('medicines.id', $ids)->get()->keyBy('id');
        $empty   = array_values(array_filter(
            array_map(fn (string $id) => $current->get($id), $ids),
            fn (?Medicine $m) => $m !== null && ! $m->hasPosology(),
        ));

        // Todos ganharam posologia (ou saíram do catálogo) desde a confirmação: nenhuma chamada.
        if ($empty === []) {
            $group->update(['status' => MedicinePosologyBatchGroup::STATUS_SKIPPED, 'skipped_count' => count($ids), 'processed_at' => now()]);
            $batch->update([
                'processed_groups' => $batch->processed_groups + 1,
                'skipped_count'    => $batch->skipped_count + count($ids),
            ]);

            return false;
        }

        $retries  = max(0, (int) config('medicines.posology_batch.retries', 2));
        $backoff  = array_values((array) config('medicines.posology_batch.retry_backoff_seconds', [5, 15]));
        $attempts = 0;
        $cost     = 0.0;

        while (true) {
            $attempts++;

            try {
                $suggestion = $this->posologyAi->suggest(
                    $this->posologyAi->catalogContext($empty[0]),
                    (string) $batch->entity_id,
                    (string) $batch->user_id,
                    $batch->provider,
                    ['source' => 'manager_medicines_batch', 'batch_id' => (string) $batch->id],
                );
                $cost += $this->runCostUsd($suggestion['ai_run_id']);

                break;
            } catch (MedicinePosologyAiException $e) {
                $cost += $this->runCostUsd($e->aiRunId);

                if ($e->reason === MedicinePosologyAiException::TRANSIENT && $attempts <= $retries) {
                    $wait = (int) ($backoff[$attempts - 1] ?? end($backoff));
                    Sleep::for($wait > 0 ? $wait : 5)->seconds();

                    continue;
                }

                $this->failGroup($batch, $group, $attempts, $cost, $e->getMessage());

                return true;
            } catch (Throwable $e) {
                // Inesperado (ex.: provedor removido no meio do lote): registra e segue.
                Log::warning('Lote de posologia por IA: grupo falhou', ['batch_id' => $batch->id, 'group_id' => $group->id, 'error' => $e->getMessage()]);
                $this->failGroup($batch, $group, $attempts, $cost, __('manager_medicines.ai_failed'));

                return true;
            }
        }

        // Cancelado enquanto a IA respondia: nada mais é gravado.
        if ($batch->fresh()?->status !== ImportStatus::Processing) {
            $group->update(['attempts' => $attempts]);
            $batch->update(['ai_calls' => $batch->ai_calls + $attempts, 'cost_usd' => $batch->cost_usd + $cost]);

            return true;
        }

        [$updated, $skipped] = $this->apply($batch, $ids, $suggestion);

        $group->update([
            'status'        => MedicinePosologyBatchGroup::STATUS_DONE,
            'updated_count' => $updated,
            'skipped_count' => $skipped,
            'attempts'      => $attempts,
            'processed_at'  => now(),
        ]);

        $batch->update([
            'processed_groups' => $batch->processed_groups + 1,
            'updated_count'    => $batch->updated_count + $updated,
            'skipped_count'    => $batch->skipped_count + $skipped,
            'ai_calls'         => $batch->ai_calls + $attempts,
            'cost_usd'         => $batch->cost_usd + $cost,
        ]);

        return true;
    }

    /**
     * Grava a sugestão nos itens do grupo que CONTINUAM sem posologia (trava
     * de linha: edição do admin no meio do lote nunca é sobrescrita).
     *
     * @param list<string>          $ids
     * @param array<string, string> $suggestion
     *
     * @return array{0: int, 1: int} atualizados, pulados
     */
    private function apply(MedicinePosologyBatch $batch, array $ids, array $suggestion): array
    {
        return $this->tenant->withoutScope(fn () => DB::transaction(function () use ($batch, $ids, $suggestion) {
            $models  = $this->filters->globalCatalog()->whereIn('medicines.id', $ids)->lockForUpdate()->get();
            $updated = 0;

            foreach ($models as $medicine) {
                if ($medicine->hasPosology()) {
                    continue;
                }

                $medicine->forceFill([
                    'dosage'                   => $this->nullable($suggestion['dosage']),
                    'frequency'                => $this->nullable($suggestion['frequency']),
                    'duration'                 => $this->nullable($suggestion['duration']),
                    'instructions'             => $this->nullable($suggestion['instructions']),
                    'posology_source'          => MedicinePosologySource::Ai,
                    'posology_ai_generated_at' => now(),
                    'posology_ai_batch_id'     => $batch->id,
                    'posology_reviewed_at'     => null,
                    'posology_reviewed_by'     => null,
                ])->save();

                $updated++;
            }

            return [$updated, count($ids) - $updated];
        }));
    }

    private function failGroup(MedicinePosologyBatch $batch, MedicinePosologyBatchGroup $group, int $attempts, float $cost, string $message): void
    {
        $group->update([
            'status'       => MedicinePosologyBatchGroup::STATUS_FAILED,
            'attempts'     => $attempts,
            'error'        => mb_substr($message, 0, 1000),
            'processed_at' => now(),
        ]);

        $batch->update([
            'processed_groups' => $batch->processed_groups + 1,
            'failed_groups'    => $batch->failed_groups + 1,
            'ai_calls'         => $batch->ai_calls + $attempts,
            'cost_usd'         => $batch->cost_usd + $cost,
        ]);
    }

    /** Falhas seguidas (sem nenhum sucesso no meio) = provedor fora: encerra o lote. */
    private function tooManyConsecutiveFailures(MedicinePosologyBatch $batch): bool
    {
        $max = (int) config('medicines.posology_batch.max_consecutive_failures', 5);

        if ($max <= 0) {
            return false;
        }

        $recent = $batch->groups()
            ->whereIn('status', [MedicinePosologyBatchGroup::STATUS_DONE, MedicinePosologyBatchGroup::STATUS_FAILED])
            ->orderByDesc('position')
            ->limit($max)
            ->pluck('status');

        return $recent->count() >= $max && $recent->every(fn (string $s) => $s === MedicinePosologyBatchGroup::STATUS_FAILED);
    }

    private function finish(MedicinePosologyBatch $batch): void
    {
        $batch->update([
            'status'      => ImportStatus::Done,
            'phase'       => null,
            'finished_at' => now(),
        ]);
    }

    /** Custo real (US$) de uma execução — o mesmo que o painel de Uso de IA soma. */
    private function runCostUsd(?string $aiRunId): float
    {
        if ($aiRunId === null) {
            return 0.0;
        }

        return (float) DB::table('ai_run_provider_calls')->where('ai_run_id', $aiRunId)->sum('raw_cost_usd');
    }

    private function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    /**
     * Chave do grupo: princípio ativo (ou o nome, se não houver) +
     * concentração + forma farmacêutica — sem caixa, acento nem espaço.
     */
    private function groupKey(Medicine $medicine): string
    {
        $normalize = static fn (?string $value): string => (string) preg_replace('/\s+/u', '', Medicine::normalizeSearch((string) $value));

        $ingredient = $normalize($medicine->active_ingredient);

        return implode('|', [
            $ingredient !== '' ? $ingredient : 'nome:' . $normalize($medicine->name),
            $normalize($medicine->concentration),
            $normalize($this->formName($medicine)),
        ]);
    }

    private function groupLabel(Medicine $medicine): string
    {
        $base = trim((string) ($medicine->active_ingredient ?: $medicine->name));

        return implode(' · ', array_filter([
            trim($base . ' ' . (string) $medicine->concentration),
            $this->formName($medicine),
        ]));
    }

    private function formName(Medicine $medicine): ?string
    {
        return $medicine->presentation_name ?: $medicine->formLabel();
    }
}
