<?php

declare(strict_types=1);

namespace App\Domains\AI\Models;

use App\Domains\AI\Services\AiAnalyticsService;
use App\DTOs\AI\AiPricingPolicyData;
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
        'estimated_charge_cents',
        'reserved_charge_cents',
        'consumed_charge_cents',
        'estimated_cost_usd',
        'actual_cost_usd',
        'exchange_rate_brl_usd',
        'markup_multiplier',
        'minimum_charge_cents',
        'pricing_source',
        'priced_at',
        'input_summary',
        'final_output',
        'safety_notes',
        'started_at',
        'approved_at',
        'rejected_at',
        'cancelled_at',
        'notified_pending_at',
        'error_message',
        'dispatch_audit',
    ];

    protected function casts(): array
    {
        return [
            'mode'                   => AiRunMode::class,
            'risk_level'             => AiRiskLevel::class,
            'status'                 => AiRunStatus::class,
            'estimated_charge_cents' => 'integer',
            'reserved_charge_cents'  => 'integer',
            'consumed_charge_cents'  => 'integer',
            'estimated_cost_usd'     => 'decimal:8',
            'actual_cost_usd'        => 'decimal:8',
            'exchange_rate_brl_usd'  => 'decimal:6',
            'markup_multiplier'      => 'decimal:3',
            'minimum_charge_cents'   => 'integer',
            'priced_at'              => 'datetime',
            'input_summary'          => 'array',
            'dispatch_audit'         => 'array',
            'safety_notes'           => 'array',
            'started_at'             => 'datetime',
            'approved_at'            => 'datetime',
            'rejected_at'            => 'datetime',
            'cancelled_at'           => 'datetime',
            'notified_pending_at'    => 'datetime',
            'created_at'             => 'datetime',
            'updated_at'             => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    /**
     * Política de preço CONGELADA na execução (câmbio, markup EFETIVO da ação e piso do momento da estimativa): mudar o
     * câmbio/markup no manager só afeta estimativas novas. O markup congelado volta como global com mapa vazio, então
     * `markupFor($this->workflow)` devolve o snapshot, nunca o override atual. Null para execução sem snapshot (legado
     * convertido) — quem liquida usa a política vigente.
     */
    public function pricingPolicy(): ?AiPricingPolicyData
    {
        if ($this->exchange_rate_brl_usd === null || $this->markup_multiplier === null || $this->minimum_charge_cents === null) {
            return null;
        }

        return new AiPricingPolicyData(
            exchangeRateBrlUsd: (float) $this->exchange_rate_brl_usd,
            markupMultiplier: (float) $this->markup_multiplier,
            minimumChargeCentsDefault: (int) $this->minimum_charge_cents,
            source: (string) ($this->pricing_source ?? 'manual'),
        );
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
     * ai_run_id) — usada pra montar o link de PDF do laudo de IA na tela do
     * Gerenciador de exames (ExamManagerController::examAiReport).
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
     * Invalida o cache analítico da entity sempre que um run salva mudanças
     * em status ou consumed_charge_cents — campos que influenciam as métricas do
     * dashboard /panel/ai/usage. O medidor da franquia lê a carteira, sem
     * cache (ver AiQuotaService).
     */
    protected static function booted(): void
    {
        static::saved(function (self $run): void {
            $touchedAggregates = $run->wasRecentlyCreated
                || $run->wasChanged(['status', 'consumed_charge_cents', 'approved_at']);

            if (! $touchedAggregates || empty($run->entity_id)) {
                return;
            }

            app(AiAnalyticsService::class)->invalidate((string) $run->entity_id);
        });
    }
}
