<?php

namespace App\Models;

use App\Enums\ImportStatus;
use App\Traits\BroadcastsImportProgress;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo};

class ScheduleImport extends Model
{
    use BroadcastsImportProgress;
    use HasUuids;

    protected $fillable = [
        'entity_id',
        'user_id',
        'status',
        'file_path',
        'original_name',
        'preview',
        'total_rows',
        'processed_rows',
        'imported_rows',
        'skipped_rows',
        'error_rows',
        'errors_file_path',
        'abort_reason',
        'started_at',
        'confirmed_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status'       => ImportStatus::class,
            'preview'      => 'array',
            'started_at'   => 'datetime',
            'confirmed_at' => 'datetime',
            'finished_at'  => 'datetime',
            'created_at'   => 'datetime',
            'updated_at'   => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function progressPercent(): int
    {
        // total_rows pode não estar carregado (registro recém-criado) → null.
        $total = (int) $this->total_rows;

        if ($total <= 0) {
            return 0;
        }

        return (int) min(100, round((int) $this->processed_rows / $total * 100));
    }

    /** Canal privado do progresso (autorização: App\Broadcasting\ClinicImportChannel). */
    public function broadcastChannelName(): string
    {
        return 'imports.schedules.' . $this->id;
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
            'id'              => (string) $this->id,
            'status'          => $this->status->value,
            'status_label'    => $this->status->label(),
            'status_color'    => $this->status->color(),
            'total_rows'      => (int) $this->total_rows,
            'processed_rows'  => (int) $this->processed_rows,
            'imported_rows'   => (int) $this->imported_rows,
            'skipped_rows'    => (int) $this->skipped_rows,
            'error_rows'      => (int) $this->error_rows,
            'progress'        => $this->progressPercent(),
            'is_done'         => $this->status->isDone(),
            'abort_reason'    => $this->abort_reason,
            'has_errors_file' => $this->errors_file_path !== null,
            'errors_url'      => $this->errors_file_path
                ? route('panel.schedules.import.errors', $this->id)
                : null,
            'channel' => $this->broadcastChannelName(),
        ];
    }
}
