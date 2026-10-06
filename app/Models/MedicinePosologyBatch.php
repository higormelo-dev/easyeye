<?php

declare(strict_types=1);

namespace App\Models;

use App\Domains\AI\Services\AiUsdBrlRate;
use App\Enums\AI\AiProvider;
use App\Enums\ImportStatus;
use App\Traits\BroadcastsImportProgress;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

/**
 * Lote de "Gerar posologia com IA" do catálogo global (Manager →
 * Medicamentos) — ver MedicinePosologyBatchService. Progresso por WebSocket
 * (BroadcastsImportProgress), sem endpoint HTTP de status.
 */
class MedicinePosologyBatch extends Model
{
    use BroadcastsImportProgress;
    use HasUuids;

    public const PHASE_PLANNING = 'planning';

    public const PHASE_GENERATING = 'generating';

    /** Na fila sem começar por mais que isso = worker parado (o normal é segundos). */
    public const PENDING_STALL_SECONDS = 90;

    /**
     * Sem progresso por mais que isso = processamento morto. Um grupo leva no
     * pior caso 3 tentativas × tempo limite do provedor + as pausas entre elas.
     */
    public const PROCESSING_STALL_SECONDS = 240;

    protected $fillable = [
        'user_id',
        'entity_id',
        'status',
        'phase',
        'provider',
        'filters',
        'group_limit',
        'total_medicines',
        'total_groups',
        'remaining_groups',
        'processed_groups',
        'updated_count',
        'skipped_count',
        'failed_groups',
        'ai_calls',
        'estimated_cost_usd',
        'cost_usd',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status'             => ImportStatus::class,
            'filters'            => 'array',
            'estimated_cost_usd' => 'float',
            'cost_usd'           => 'float',
            'started_at'         => 'datetime',
            'finished_at'        => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<MedicinePosologyBatchGroup> */
    public function groups(): HasMany
    {
        return $this->hasMany(MedicinePosologyBatchGroup::class, 'batch_id');
    }

    public function isRunning(): bool
    {
        return in_array($this->status, [ImportStatus::Pending, ImportStatus::Processing], true);
    }

    public function providerLabel(): string
    {
        return AiProvider::tryFrom((string) $this->provider)?->label() ?? (string) $this->provider;
    }

    /** Segundos parado: na fila desde a criação; processando desde a última atualização. */
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

    public function progressPercent(): int
    {
        if ($this->status === ImportStatus::Done) {
            return 100;
        }

        $total = (int) $this->total_groups;

        if ($total <= 0) {
            return 0;
        }

        return (int) min(100, round((int) $this->processed_groups / $total * 100));
    }

    /**
     * Estado da barra — 1º render (Inertia) e cada evento WebSocket. Enxuto
     * (o servidor de WebSocket recusa mensagens grandes): as falhas por grupo
     * ficam só no histórico.
     *
     * @return array<string, mixed>
     */
    public function progressPayload(): array
    {
        // Mesma cotação do Uso de IA (última recarga de provedor; senão a de
        // referência): custo e estimativa também em real, ao vivo.
        $rate     = app(AiUsdBrlRate::class)->current();
        $toBrl    = fn ($usd) => $usd === null ? null : round((float) $usd * $rate['rate'], 4);
        $estimate = $this->estimated_cost_usd === null ? null : round((float) $this->estimated_cost_usd, 6);

        return [
            'id'                  => $this->id,
            'status'              => $this->status->value,
            'status_label'        => $this->status->label(),
            'status_color'        => $this->status->color(),
            'phase'               => $this->phase,
            'phase_label'         => $this->phase ? __('manager_medicines.batch_phase_' . $this->phase) : null,
            'provider'            => $this->provider,
            'provider_label'      => $this->providerLabel(),
            'total_medicines'     => (int) $this->total_medicines,
            'total_groups'        => (int) $this->total_groups,
            'remaining_groups'    => (int) $this->remaining_groups,
            'processed_groups'    => (int) $this->processed_groups,
            'progress'            => $this->progressPercent(),
            'updated_count'       => (int) $this->updated_count,
            'skipped_count'       => (int) $this->skipped_count,
            'failed_groups'       => (int) $this->failed_groups,
            'ai_calls'            => (int) $this->ai_calls,
            'cost_usd'            => round((float) $this->cost_usd, 6),
            'cost_brl'            => $toBrl($this->cost_usd),
            'estimated_cost_usd'  => $estimate,
            'estimated_cost_brl'  => $toBrl($estimate),
            'usd_brl_rate'        => $rate['rate'],
            'usd_brl_is_fallback' => (bool) $rate['is_fallback'],
            // Tempo restante estimado é calculado na tela (ritmo desde o início).
            'started_at'          => $this->started_at?->toIso8601String(),
            'error'               => $this->error,
            'is_done'             => $this->status->isDone(),
            'idle_seconds'        => $this->idleSeconds(),
            'stall_after_seconds' => $this->stallAfterSeconds(),
            'channel'             => $this->broadcastChannelName(),
        ];
    }

    /** Canal privado do progresso (autorização: App\Broadcasting\ManagerMedicinePosologyBatchChannel). */
    public function broadcastChannelName(): string
    {
        return 'manager.medicines.posology-batches.' . $this->id;
    }
}
