<?php

namespace App\Models;

use App\Enums\ImportStatus;
use App\Traits\BroadcastsImportProgress;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Importação do catálogo global de medicamentos (CMED/Anvisa) feita no
 * manager — ver AnvisaMedicineImportService.
 */
class MedicineImport extends Model
{
    use BroadcastsImportProgress;
    use HasUuids;

    protected $fillable = [
        'user_id',
        'status',
        'cmed_file_path',
        'cmed_original_name',
        'open_data_file_path',
        'open_data_original_name',
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
            'status'      => ImportStatus::class,
            'started_at'  => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
            'cmed_original_name'            => $this->cmed_original_name,
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
            'channel'                       => $this->broadcastChannelName(),
        ];
    }

    /** Canal privado do progresso (autorização: App\Broadcasting\ManagerMedicineImportChannel). */
    public function broadcastChannelName(): string
    {
        return 'manager.imports.medicines.' . $this->id;
    }
}
