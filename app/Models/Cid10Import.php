<?php

namespace App\Models;

use App\Enums\ImportStatus;
use App\Traits\BroadcastsImportProgress;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Importação da CID-10 (DATASUS) enviada na tela Manager → CID-10 — ver
 * Cid10ImportService. Histórico: quem, quando, arquivos, lidos/novos/
 * corrigidos/erros. Progresso só por WebSocket (BroadcastsImportProgress).
 */
class Cid10Import extends Model
{
    use BroadcastsImportProgress;
    use HasUuids;

    /** Na fila sem começar por mais que isso = worker parado (o normal é começar em segundos). */
    public const PENDING_STALL_SECONDS = 90;

    /** Sem progresso por mais que o timeout do job (600 s) = processamento morto. */
    public const PROCESSING_STALL_SECONDS = 660;

    protected $fillable = [
        'user_id',
        'status',
        'phase',
        'folder',
        'original_name',
        'files',
        'total_rows',
        'processed_rows',
        'read_count',
        'created_count',
        'corrected_count',
        'official_updated_count',
        'kept_edited_count',
        'skipped_invalid',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status'      => ImportStatus::class,
            'files'       => 'array',
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

    /** Parada além do esperado: o admin pode cancelar para liberar uma nova importação. */
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
            'id'                     => $this->id,
            'status'                 => $this->status->value,
            'status_label'           => $this->status->label(),
            'status_color'           => $this->status->color(),
            'phase'                  => $this->phase,
            'phase_label'            => $this->phase ? __('manager_cid10.phase_' . $this->phase) : null,
            'original_name'          => $this->original_name,
            'total_rows'             => $this->total_rows,
            'processed_rows'         => $this->processed_rows,
            'progress'               => $this->progressPercent(),
            'read_count'             => $this->read_count,
            'created_count'          => $this->created_count,
            'corrected_count'        => $this->corrected_count,
            'official_updated_count' => $this->official_updated_count,
            'kept_edited_count'      => $this->kept_edited_count,
            'skipped_invalid'        => $this->skipped_invalid,
            'error'                  => $this->error,
            'is_done'                => $this->status->isDone(),
            // Tela sem polling: mede "parada há quanto tempo" a partir disto.
            'idle_seconds'        => $this->idleSeconds(),
            'stall_after_seconds' => $this->stallAfterSeconds(),
            'channel'             => $this->broadcastChannelName(),
        ];
    }

    /** Canal privado do progresso (autorização: App\Broadcasting\ManagerCid10ImportChannel). */
    public function broadcastChannelName(): string
    {
        return 'manager.imports.cid10.' . $this->id;
    }
}
