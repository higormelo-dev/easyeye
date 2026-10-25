<?php

namespace App\Models;

use App\Enums\ImportStatus;
use App\Traits\BroadcastsImportProgress;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, Relations\HasMany};

class PatientImport extends Model
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
        'warning_rows',
        'errors_file_path',
        'abort_reason',
        'started_at',
        'confirmed_at',
        'finished_at',
        'reprocess_of_id',
        'reprocess_lines',
        'covenant_error_lines',
        'covenant_mapping_snapshot',
        'files_expire_at',
        'files_purged_at',
    ];

    protected function casts(): array
    {
        return [
            'status'                    => ImportStatus::class,
            'preview'                   => 'array',
            'reprocess_lines'           => 'array',
            'covenant_error_lines'      => 'array',
            'covenant_mapping_snapshot' => 'array',
            'started_at'                => 'datetime',
            'confirmed_at'              => 'datetime',
            'finished_at'               => 'datetime',
            'files_expire_at'           => 'datetime',
            'files_purged_at'           => 'datetime',
            'created_at'                => 'datetime',
            'updated_at'                => 'datetime',
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

    /** Import original de que este foi criado para reprocessar as linhas com erro de convênio. */
    public function reprocessOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reprocess_of_id');
    }

    /** Reprocessamentos criados a partir deste import. */
    public function reprocesses(): HasMany
    {
        return $this->hasMany(self::class, 'reprocess_of_id');
    }

    public function isReprocess(): bool
    {
        return $this->reprocess_of_id !== null;
    }

    /** Retenção LGPD vencida: o prazo passou ou a limpeza agendada já apagou o CSV original e o de erros. */
    public function filesExpired(): bool
    {
        return $this->files_purged_at !== null
            || ($this->files_expire_at !== null && $this->files_expire_at->isPast());
    }

    /** `null` (import anterior ao recurso) e `[]` (sem erro de convênio) dão false; imports antigos usam o CSV de erros. */
    public function hasCovenantErrors(): bool
    {
        return ! empty($this->covenant_error_lines);
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
        return 'imports.patients.' . $this->id;
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
            'warning_rows'    => (int) $this->warning_rows,
            'progress'        => $this->progressPercent(),
            'is_done'         => $this->status->isDone(),
            'abort_reason'    => $this->abort_reason,
            'has_errors_file' => $this->errors_file_path !== null,
            'errors_url'      => $this->errors_file_path
                ? route('panel.patients.import.errors', $this->id)
                : null,
            'reprocess_of_id'     => $this->reprocess_of_id ? (string) $this->reprocess_of_id : null,
            'is_reprocess'        => $this->isReprocess(),
            'has_covenant_errors' => $this->hasCovenantErrors(),
            'files_expire_at'     => $this->files_expire_at?->toIso8601String(),
            'files_expired'       => $this->filesExpired(),
            'channel'             => $this->broadcastChannelName(),
        ];
    }
}
