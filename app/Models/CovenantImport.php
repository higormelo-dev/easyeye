<?php

namespace App\Models;

use App\Enums\ImportStatus;
use App\Traits\BroadcastsImportProgress;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sincronização do catálogo global de convênios com o Cadastro de
 * Operadoras da ANS (manager → Convênios) — ver AnsOperatorImportService.
 */
class CovenantImport extends Model
{
    use BroadcastsImportProgress;
    use HasUuids;

    /** Baixado da ANS a pedido do admin. */
    public const SOURCE_ANS = 'ans';

    /** Arquivos enviados pelo admin (ANS fora do ar). */
    public const SOURCE_UPLOAD = 'upload';

    /** Tarefa semanal (covenants:sync-ans). */
    public const SOURCE_SCHEDULED = 'scheduled';

    /** Na fila sem começar por mais que isso = worker parado (o normal é começar em segundos). */
    public const PENDING_STALL_SECONDS = 90;

    /** Sem progresso por mais que o timeout do job (900 s) = processamento morto. */
    public const PROCESSING_STALL_SECONDS = 960;

    protected $fillable = [
        'user_id',
        'source',
        'status',
        'phase',
        'modalities',
        'active_file_path',
        'active_original_name',
        'cancelled_file_path',
        'cancelled_original_name',
        'total_rows',
        'processed_rows',
        'created_count',
        'updated_count',
        'unchanged_count',
        'deactivated_count',
        'skipped_modality',
        'skipped_invalid',
        'plans_created_count',
        'plans_updated_count',
        'plans_unchanged_count',
        'plans_deactivated_count',
        'plans_skipped_count',
        'plans_error',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status'      => ImportStatus::class,
            'modalities'  => 'array',
            'started_at'  => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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

    /** Parado além do esperado: o admin pode cancelar para liberar uma nova sincronização. */
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

        $total = (int) $this->total_rows;

        if ($total <= 0) {
            return 0;
        }

        return (int) min(100, round((int) $this->processed_rows / $total * 100));
    }

    /**
     * Estado da barra de progresso — 1º render da tela (Inertia) e cada
     * evento WebSocket (ImportProgressUpdated). Sem endpoint HTTP de status.
     *
     * @return array<string, mixed>
     */
    public function progressPayload(): array
    {
        return [
            'id'                => $this->id,
            'source'            => $this->source,
            'source_label'      => __('manager_covenants.import_source_' . $this->source),
            'status'            => $this->status->value,
            'status_label'      => $this->status->label(),
            'status_color'      => $this->status->color(),
            'phase'             => $this->phase,
            'phase_label'       => $this->phase ? __('manager_covenants.phase_' . $this->phase) : null,
            'total_rows'        => $this->total_rows,
            'processed_rows'    => $this->processed_rows,
            'progress'          => $this->progressPercent(),
            'created_count'     => $this->created_count,
            'updated_count'     => $this->updated_count,
            'unchanged_count'   => $this->unchanged_count,
            'deactivated_count' => $this->deactivated_count,
            'skipped_modality'  => $this->skipped_modality,
            'skipped_invalid'   => $this->skipped_invalid,
            // Etapa de planos (produtos da ANS).
            'plans_created_count'     => $this->plans_created_count,
            'plans_updated_count'     => $this->plans_updated_count,
            'plans_unchanged_count'   => $this->plans_unchanged_count,
            'plans_deactivated_count' => $this->plans_deactivated_count,
            'plans_skipped_count'     => $this->plans_skipped_count,
            'plans_error'             => $this->plans_error,
            'error'                   => $this->error,
            'is_done'                 => $this->status->isDone(),
            // Tela sem polling: mede "parado há quanto tempo" a partir disto.
            'idle_seconds'        => $this->idleSeconds(),
            'stall_after_seconds' => $this->stallAfterSeconds(),
            'channel'             => $this->broadcastChannelName(),
        ];
    }

    /** Canal privado do progresso (autorização: App\Broadcasting\ManagerCovenantImportChannel). */
    public function broadcastChannelName(): string
    {
        return 'manager.imports.covenants.' . $this->id;
    }
}
