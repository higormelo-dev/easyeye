<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToEntity;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evolução clínica em texto livre — registro append-only dentro do prontuário.
 *
 * Cada evolução carimba data/hora (created_at), médico responsável (doctor_id)
 * e o texto livre digitado. Sem update/destroy na UI: CFM trata registro
 * clínico como imutável após criação — correções entram como NOVA evolução.
 *
 * `patient_id` é gravado junto do `medical_record_id` para a listagem
 * cronológica atravessar todos os prontuários do paciente (mesmo desenho de
 * MedicalRecordDocumentation).
 */
class MedicalRecordEvolution extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'entity_id',
        'patient_id',
        'medical_record_id',
        'doctor_id',
        'content',
    ];

    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }
}
