<?php

declare(strict_types=1);

namespace App\Domains\AI\Models;

use App\Domains\AI\Services\{AiAnalyticsService, AiQuotaService};
use App\Enums\AI\{AiRiskLevel, AiRunMode, AiRunStatus};
use App\Models\Concerns\BelongsToEntity;
use App\Models\{Entity, MedicalRecord, MedicalRecordDocumentation, Patient, PatientExam, User};
use App\Traits\Auditable;
use Database\Factories\AI\AiRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, BelongsToMany, HasMany, HasOne};

class AiRun extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasFactory;
    use HasUuids;

    protected $table = 'ai_runs';

    protected $fillable = [
        'entity_id',
        'patient_id',
        'medical_record_id',
        'requested_by',
        'approved_by',
        'cancelled_by',
        'parent_run_id',
        'conversation_id',
        'workflow',
        'mode',
        'risk_level',
        'status',
        'current_role',
        'current_provider',
        'estimated_credits',
        'reserved_credits',
        'consumed_credits',
        'input_summary',
        'final_output',
        'safety_notes',
        'started_at',
        'approved_at',
        'rejected_at',
        'cancelled_at',
        'notified_pending_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'mode'                => AiRunMode::class,
            'risk_level'          => AiRiskLevel::class,
            'status'              => AiRunStatus::class,
            'estimated_credits'   => 'integer',
            'reserved_credits'    => 'integer',
            'consumed_credits'    => 'integer',
            'input_summary'       => 'array',
            'safety_notes'        => 'array',
            'started_at'          => 'datetime',
            'approved_at'         => 'datetime',
            'rejected_at'         => 'datetime',
            'cancelled_at'        => 'datetime',
            'notified_pending_at' => 'datetime',
            'created_at'          => 'datetime',
            'updated_at'          => 'datetime',
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

    /**
     * Documentação gravada no prontuário quando o laudo é aprovado (ver
     * AiRunDocumentationService::writeRunDocumentation, idempotente por
     * ai_run_id) — usada pra montar o link de PDF do laudo de IA na tela de
     * Imagens Oftálmicas (EyeImagesController::examAiReport).
     */
    public function documentation(): HasOne
    {
        return $this->hasOne(MedicalRecordDocumentation::class, 'ai_run_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Run que originou esta execução via "Reanalisar com modo superior" (Onda 3, P2).
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_run_id');
    }

    /**
     * Reanálises feitas a partir desta execução.
     */
    public function escalations(): HasMany
    {
        return $this->hasMany(self::class, 'parent_run_id');
    }

    public function feedback(): HasOne
    {
        return $this->hasOne(AiRunFeedback::class, 'ai_run_id');
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

    /**
     * Invalida o cache de cota da entity sempre que um run salva mudanças em
     * status ou consumed_credits — campos que influenciam o snapshot mensal
     * exibido no painel da IA e no dashboard /panel/usage.
     */
    protected static function booted(): void
    {
        static::saved(function (self $run): void {
            $touchedAggregates = $run->wasRecentlyCreated
                || $run->wasChanged(['status', 'consumed_credits', 'approved_at']);

            if (! $touchedAggregates || empty($run->entity_id)) {
                return;
            }

            $entityId = (string) $run->entity_id;
            app(AiQuotaService::class)->invalidate($entityId);
            app(AiAnalyticsService::class)->invalidate($entityId);
        });
    }
}
