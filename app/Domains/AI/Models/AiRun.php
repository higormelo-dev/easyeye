<?php

declare(strict_types=1);

namespace App\Domains\AI\Models;

use App\Enums\AI\{AiRiskLevel, AiRunMode, AiRunStatus};
use App\Models\{Entity, MedicalRecord, MedicalRecordDocumentation, Patient, PatientExam, User};
use App\Traits\Auditable;
use Database\Factories\AI\AiRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, BelongsToMany, HasMany};

class AiRun extends Model
{
    use Auditable;
    use HasFactory;
    use HasUuids;

    protected $table = 'ai_runs';

    protected $fillable = [
        'entity_id',
        'patient_id',
        'medical_record_id',
        'requested_by',
        'approved_by',
        'workflow',
        'mode',
        'risk_level',
        'status',
        'estimated_credits',
        'reserved_credits',
        'consumed_credits',
        'input_summary',
        'final_output',
        'safety_notes',
        'approved_at',
        'rejected_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'mode'              => AiRunMode::class,
            'risk_level'        => AiRiskLevel::class,
            'status'            => AiRunStatus::class,
            'estimated_credits' => 'integer',
            'reserved_credits'  => 'integer',
            'consumed_credits'  => 'integer',
            'input_summary'     => 'array',
            'safety_notes'      => 'array',
            'approved_at'       => 'datetime',
            'rejected_at'       => 'datetime',
            'created_at'        => 'datetime',
            'updated_at'        => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class, 'medical_record_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function providerCalls(): HasMany
    {
        return $this->hasMany(AiRunProviderCall::class, 'ai_run_id');
    }

    /**
     * Exames de imagem ocular analisados por esta execução (módulo Eye Image).
     */
    public function exams(): BelongsToMany
    {
        return $this->belongsToMany(PatientExam::class, 'ai_run_patient_exam', 'ai_run_id', 'patient_exam_id')
            ->withPivot('entity_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(AiCreditLedgerEntry::class, 'ai_run_id');
    }

    /**
     * Documentações no prontuário originadas por esta execução de IA
     * (uma execução pode gerar mais de um documento — ex.: laudo + sugestão).
     */
    public function documentations(): HasMany
    {
        return $this->hasMany(MedicalRecordDocumentation::class, 'ai_run_id');
    }

    protected static function newFactory(): AiRunFactory
    {
        return AiRunFactory::new();
    }
}
