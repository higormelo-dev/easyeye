<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicalRecordFile extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'medical_record_id',
        'patient_id',
        'file_path',
        'original_name',
        'mime_type',
        'file_size',
        'created_by',
    ];

    protected function casts(): array
    {
        return ['file_size' => 'integer'];
    }

    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type ?? '', 'image/');
    }
}
