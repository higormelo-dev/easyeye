<?php

declare(strict_types=1);

namespace App\Domains\AI\Models;

use App\Enums\AI\AiProvider;
use App\Enums\ImportStatus;
use App\Models\User;
use App\Traits\BroadcastsImportProgress;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma sincronização do catálogo de modelos/preços de IA — ver
 * AiModelCatalogSyncService. Progresso por WebSocket (BroadcastsImportProgress),
 * sem endpoint HTTP de status.
 */
class AiCatalogSync extends Model
{
    use BroadcastsImportProgress;
    use HasUuids;

    /** Botão "Sincronizar agora". */
    public const SOURCE_MANUAL = 'manual';

    /** Verificação diária (ai:sync-model-catalog). */
    public const SOURCE_SCHEDULED = 'scheduled';

    public const PHASE_PRICES = 'prices';

    public const PHASE_MODELS = 'models';

    /** Resultado por provedor. */
    public const PROVIDER_OK = 'ok';

    public const PROVIDER_SKIPPED = 'skipped';

    public const PROVIDER_FAILED = 'failed';

    /** Na fila sem começar por mais que isso = worker parado (o normal é segundos). */
    public const PENDING_STALL_SECONDS = 90;

    /** Sem progresso por mais que o timeout do job (300 s) = processamento morto. */
    public const PROCESSING_STALL_SECONDS = 360;

    protected $table = 'ai_catalog_syncs';

    protected $fillable = [
        'user_id',
        'source',
        'status',
        'phase',
        'total_providers',
        'processed_providers',
        'prices_version',
        'providers',
        'details',
        'models_listed',
        'created_count',
        'updated_count',
        'unchanged_count',
        'locked_count',
        'unlisted_count',
        'missing_price_count',
        'suspicious_count',
        'notice',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status'      => ImportStatus::class,
            'providers'   => 'array',
            'details'     => 'array',
            'started_at'  => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Segundos parada: na fila desde a criação; processando desde a última atualização. */
    public function idleSeconds(): int
    {
        $reference = $this->status === ImportStatus::Pending ? $this->created_at : $this->updated_at;

        return $reference ? (int) max(0, $reference->diffInSeconds(now(), true)) : 0;
    }

    public function stallAfterSeconds(): ?int
    {
        return match ($this->status) {
            ImportStatus::Pending    => self::PENDING_STALL_SECONDS,
            ImportStatus::Processing => self::PROCESSING_STALL_SECONDS,
            default                  => null,
        };
    }

    /** Parada além do esperado: o admin pode cancelar para liberar uma nova sincronização. */
    public function isStalled(): bool
    {
        $after = $this->stallAfterSeconds();

        return $after !== null && $this->idleSeconds() >= $after;
    }

    public function progressPercent(): int
    {
        if ($this->status === ImportStatus::Done) {
            return 100;
        }

        $total = (int) $this->total_providers;

        if ($total <= 0) {
            return 0;
        }

        // A fase de preços (download do catálogo) conta como o primeiro passo.
        return (int) min(100, round(((int) $this->processed_providers + 1) / ($total + 1) * 100));
    }

    /**
     * Estado da barra — 1º render (Inertia) e cada evento WebSocket. Enxuto:
     * o servidor de WebSocket recusa mensagens grandes (os detalhes do que
     * mudou vão só no histórico).
     *
     * @return array<string, mixed>
     */
    public function progressPayload(): array
    {
        return [
            'id'                  => $this->id,
            'status'              => $this->status->value,
            'status_label'        => $this->status->label(),
            'status_color'        => $this->status->color(),
            'phase'               => $this->phase,
            'phase_label'         => $this->phase ? __('manager_ai.sync_phase_' . $this->phase) : null,
            'source'              => $this->source,
            'source_label'        => __('manager_ai.sync_source_' . $this->source),
            'total_providers'     => (int) $this->total_providers,
            'processed_providers' => (int) $this->processed_providers,
            'progress'            => $this->progressPercent(),
            'providers'           => $this->providerResults(),
            'models_listed'       => (int) $this->models_listed,
            'created_count'       => (int) $this->created_count,
            'updated_count'       => (int) $this->updated_count,
            'unchanged_count'     => (int) $this->unchanged_count,
            'locked_count'        => (int) $this->locked_count,
            'unlisted_count'      => (int) $this->unlisted_count,
            'missing_price_count' => (int) $this->missing_price_count,
            'suspicious_count'    => (int) $this->suspicious_count,
            'notice'              => $this->notice,
            'error'               => $this->error,
            'is_done'             => $this->status->isDone(),
            'idle_seconds'        => $this->idleSeconds(),
            'stall_after_seconds' => $this->stallAfterSeconds(),
            'channel'             => $this->broadcastChannelName(),
        ];
    }

    /**
     * Resultado por provedor com o nome de exibição.
     *
     * @return list<array{code: string, label: string, status: string, listed: int, message: ?string}>
     */
    public function providerResults(): array
    {
        $out     = [];
        $results = (array) $this->providers;

        // Ordem da lista pronta (o jsonb do Postgres reordena as chaves).
        $order = array_flip(array_map(static fn (AiProvider $p) => $p->value, AiProvider::cases()));
        uksort($results, static fn ($a, $b) => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX));

        foreach ($results as $code => $result) {
            $out[] = [
                'code'    => (string) $code,
                'label'   => AiProvider::tryFrom((string) $code)?->label() ?? (string) $code,
                'status'  => (string) ($result['status'] ?? self::PROVIDER_SKIPPED),
                'listed'  => (int) ($result['listed'] ?? 0),
                'message' => isset($result['message']) ? (string) $result['message'] : null,
            ];
        }

        return $out;
    }

    /** Canal privado do progresso (autorização: App\Broadcasting\ManagerAiCatalogSyncChannel). */
    public function broadcastChannelName(): string
    {
        return 'manager.ai-catalog-syncs.' . $this->id;
    }
}
