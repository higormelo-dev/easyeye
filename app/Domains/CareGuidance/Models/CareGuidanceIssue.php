<?php

declare(strict_types=1);

namespace App\Domains\CareGuidance\Models;

use App\Models\Concerns\BelongsToEntity;
use App\Models\{Doctor, MedicalRecordProcedure, Patient};
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Orientação ENTREGUE ao paciente (Fase 6 / S14): retrato congelado do que foi emitido — título, texto, local,
 * colírios, olho e os PDFs copiados — com o hash do conteúdo. IMUTÁVEL: depois de gravada nenhuma coluna muda (o modelo
 * pode ser editado ou apagado sem afetar o que o paciente recebeu). Dado de saúde do paciente: Auditable.
 *
 * @property string                          $id
 * @property string                          $entity_id
 * @property string                          $patient_id
 * @property ?string                         $medical_record_procedure_id
 * @property ?string                         $template_id
 * @property ?string                         $doctor_id
 * @property string                          $title
 * @property ?string                         $location_label
 * @property ?string                         $eye
 * @property string                          $body
 * @property list<array<string, mixed>>|null $eye_drops
 * @property list<array<string, mixed>>|null $attachments
 * @property string                          $content_hash
 */
class CareGuidanceIssue extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasUuids;

    protected $table = 'care_guidance_issues';

    protected $fillable = [
        'entity_id', 'patient_id', 'medical_record_procedure_id', 'template_id', 'doctor_id', 'title', 'location_label', 'eye',
        'body', 'eye_drops', 'attachments', 'content_hash', 'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'eye_drops'   => 'array',
            'attachments' => 'array',
            'issued_at'   => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // A emissão é um fato: não se reescreve. (Nulificar a referência do modelo/procedimento apagado é feito pelo
        // banco — ON DELETE SET NULL —, que não passa por aqui.)
        static::updating(static function (): void {
            throw new LogicException('Orientação emitida é imutável.');
        });
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(MedicalRecordProcedure::class, 'medical_record_procedure_id');
    }
}
