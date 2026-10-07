<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rascunho do prontuário (autosave) — o que o médico digitou e ainda não
 * salvou. NÃO é o prontuário: sem Auditable/Versionable/Signable de
 * propósito (gravar a cada ~30 s não pode gerar versão nem trilha a cada
 * tecla). Conteúdo criptografado (cast encrypted) e visível só ao autor na
 * clínica dele — ver App\Services\MedicalRecords\MedicalRecordDraftService.
 *
 * @property array<string, mixed> $payload
 */
class MedicalRecordDraft extends Model
{
    use HasUuids;

    protected $fillable = [
        'entity_id',
        'user_id',
        'patient_id',
        'schedule_id',
        'medical_record_id',
        'context_key',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload'    => 'encrypted:array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class);
    }
}
