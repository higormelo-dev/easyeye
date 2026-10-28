<?php

namespace App\Models;

use App\Enums\ImportStatus;
use App\Traits\BroadcastsImportProgress;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Carga do catálogo global de medicamentos (CMED/Anvisa) feita no manager:
 * envio de arquivos ou download das fontes oficiais — ver
 * MedicineCatalogSyncService e AnvisaMedicineImportService.
 */
class MedicineImport extends Model
{
    use BroadcastsImportProgress;
    use HasUuids;

    /** Arquivos enviados pelo admin. */
    public const SOURCE_UPLOAD = 'upload';

    /** Baixado da CMED/Anvisa a pedido do admin ("Atualizar agora"). */
    public const SOURCE_CMED = 'cmed';

    /** Verificação semanal (medicines:sync-cmed). */
    public const SOURCE_SCHEDULED = 'scheduled';

    /** Na fila sem começar por mais que isso = worker parado (o normal é começar em segundos). */
    public const PENDING_STALL_SECONDS = 90;

    /** Sem progresso por mais que o timeout do job (900 s) = processamento morto. */
    public const PROCESSING_STALL_SECONDS = 960;

    protected $fillable = [
        'user_id',
        'source',
        'force',
        'status',
        'cmed_file_path',
        'cmed_original_name',
        'open_data_file_path',
        'open_data_original_name',
        'list_version',
        'list_published_at',
        'list_url',
        'open_data_version',
        'notice',
        'total_rows',
        'processed_rows',
        'phase',
        'created_count',
        'updated_count',
        'deactivated_count',
        'skipped_hospital',
        'skipped_inactive_registration',
        'skipped_invalid',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status'            => ImportStatus::class,
            'force'             => 'boolean',
            'list_published_at' => 'date',
            'started_at'        => 'datetime',
            'finished_at'       => 'datetime',
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

    /** Parada além do esperado: o admin pode cancelar para liberar uma nova carga. */
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

        // total_rows pode não estar carregado (registro recém-criado) → null.
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
            'id'                            => $this->id,
            'status'                        => $this->status->value,
            'status_label'                  => $this->status->label(),
            'status_color'                  => $this->status->color(),
            'phase'                         => $this->phase,
            'phase_label'                   => $this->phase ? __('manager_medicines.phase_' . $this->phase) : null,
            'source'                        => $this->source ?? self::SOURCE_UPLOAD,
            'source_label'                  => __('manager_medicines.import_source_' . ($this->source ?? self::SOURCE_UPLOAD)),
            'cmed_original_name'            => $this->cmed_original_name,
            'open_data_original_name'       => $this->open_data_original_name,
            'list_published_at'             => $this->list_published_at?->isoFormat('L'),
            'list_url'                      => $this->list_url,
            'notice'                        => $this->notice,
            'total_rows'                    => $this->total_rows,
            'processed_rows'                => $this->processed_rows,
            'progress'                      => $this->progressPercent(),
            'created_count'                 => $this->created_count,
            'updated_count'                 => $this->updated_count,
            'deactivated_count'             => $this->deactivated_count,
            'skipped_hospital'              => $this->skipped_hospital,
            'skipped_inactive_registration' => $this->skipped_inactive_registration,
            'skipped_invalid'               => $this->skipped_invalid,
            'error'                         => $this->error,
            'is_done'                       => $this->status->isDone(),
            // Tela sem polling: mede "parada há quanto tempo" a partir disto.
            'idle_seconds'        => $this->idleSeconds(),
            'stall_after_seconds' => $this->stallAfterSeconds(),
            'channel'             => $this->broadcastChannelName(),
        ];
    }

    /** Canal privado do progresso (autorização: App\Broadcasting\ManagerMedicineImportChannel). */
    public function broadcastChannelName(): string
    {
        return 'manager.imports.medicines.' . $this->id;
    }
}
