<?php

namespace App\Models;

use App\Enums\ImportStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo};

class PatientImport extends Model
{
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
        if ($this->total_rows === 0) {
            return 0;
        }

        return (int) min(100, round($this->processed_rows / $this->total_rows * 100));
    }
}
