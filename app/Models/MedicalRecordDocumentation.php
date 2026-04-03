<?php

namespace App\Models;

use App\Enums\DocumentationType;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicalRecordDocumentation extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'medical_record_id',
        'patient_id',
        'doctor_id',
        'report_setting_id',
        'report_setting_content_id',
        'template_version',
        'type',
        'title',
        'content',
    ];

    protected function casts(): array
    {
        return ['type' => DocumentationType::class];
    }

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

    public function reportSetting(): BelongsTo
    {
        return $this->belongsTo(ReportSetting::class);
    }

    public function reportSettingContent(): BelongsTo
    {
        return $this->belongsTo(ReportSettingContent::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getTypeLabel(): string
    {
        return $this->type instanceof DocumentationType
            ? $this->type->label()
            : (string) $this->type;
    }

    /**
     * Retorna dados da tonometria decodificados do campo content (JSON).
     *
     * @return array{od: string|null, oe: string|null, time: string|null}
     */
    public function tonometryData(): array
    {
        if ($this->type !== DocumentationType::Tonometry) {
            return ['od' => null, 'oe' => null, 'time' => null];
        }
        $data = json_decode($this->content ?? '{}', true);

        return ['od' => $data['od'] ?? null, 'oe' => $data['oe'] ?? null, 'time' => $data['time'] ?? null];
    }
}
