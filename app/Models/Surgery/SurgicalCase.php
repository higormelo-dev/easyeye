<?php

declare(strict_types=1);

namespace App\Models\Surgery;

use App\Concerns\HasEntityCode;
use App\Enums\Surgery\{AnesthesiaType, AuthorizationStatus, SurgeryEye, SurgeryRegime, SurgeryUrgency, SurgicalCaseStatus};
use App\Models\Concerns\{BelongsToEntity, BindsRouteToSessionEntity};
use App\Models\{Covenant, Doctor, Patient, SurgeryType};
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany, HasOne};

/**
 * Caso cirúrgico (ADR 0003): do pedido à alta. Entidade própria — NUNCA uma
 * linha de `schedules`.
 *
 * Concorrência: toda transição de estado trava a linha (FOR UPDATE), relê e
 * valida o estado ATUAL — ver App\Services\Surgery\SurgicalCaseService.
 * `status`, `authorization_*`, `scheduled_*`, `revision` e os carimbos de
 * data só são escritos por esse service (ficam FORA de $fillable).
 *
 * @property SurgicalCaseStatus  $status
 * @property AuthorizationStatus $authorization_status
 * @property SurgeryEye          $eye
 * @property SurgeryRegime       $regime
 * @property SurgeryUrgency      $urgency
 * @property AnesthesiaType|null $anesthesia
 */
class SurgicalCase extends Model
{
    use Auditable;
    use BelongsToEntity;
    use BindsRouteToSessionEntity;
    use HasAuditColumns;
    use HasEntityCode;
    use HasUuids;
    use SoftDeletes;

    protected string $codePrefix = 'CIR';

    protected string $codePrefixGlobal = 'CIRP';

    /** Trilha de auditoria sem retrato clínico (alergias/medicações) nem credenciais da operadora. */
    protected array $auditExclude = ['clinical_safety_snapshot', 'authorization_password'];

    /** Escrita livre pelo pedido (cadastro/edição do rascunho). */
    protected $fillable = [
        'entity_id',
        'patient_id',
        'surgeon_doctor_id',
        'covenant_id',
        'covenant_card_number',
        'surgery_type_id',
        'title',
        'eye',
        'technique',
        'anesthesia',
        'regime',
        'urgency',
        'estimated_duration_minutes',
        'surgery_facility_id',
        'cid_codes',
        'clinical_indication',
        'days_requested',
        'needs_opme',
        'notes',
    ];

    protected $hidden = ['authorization_password'];

    protected function casts(): array
    {
        return [
            'status'                      => SurgicalCaseStatus::class,
            'authorization_status'        => AuthorizationStatus::class,
            'eye'                         => SurgeryEye::class,
            'regime'                      => SurgeryRegime::class,
            'urgency'                     => SurgeryUrgency::class,
            'anesthesia'                  => AnesthesiaType::class,
            'status_before_suspension'    => SurgicalCaseStatus::class,
            'status_history'              => 'array',
            'cid_codes'                   => 'array',
            'clinical_safety_snapshot'    => 'array',
            'needs_opme'                  => 'boolean',
            'scheduled_start'             => 'datetime',
            'scheduled_end'               => 'datetime',
            'authorization_valid_until'   => 'date',
            'requested_at'                => 'datetime',
            'authorized_at'               => 'datetime',
            'performed_at'                => 'datetime',
            'discharged_at'               => 'datetime',
            'cancelled_at'                => 'datetime',
            'suspended_at'                => 'datetime',
            'clinical_safety_snapshot_at' => 'datetime',
            'estimated_duration_minutes'  => 'integer',
            'days_requested'              => 'integer',
            'revision'                    => 'integer',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function surgeon(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'surgeon_doctor_id');
    }

    public function covenant(): BelongsTo
    {
        return $this->belongsTo(Covenant::class);
    }

    public function surgeryType(): BelongsTo
    {
        return $this->belongsTo(SurgeryType::class);
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(SurgeryFacility::class, 'surgery_facility_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(SurgeryRoom::class, 'surgery_room_id');
    }

    public function procedures(): HasMany
    {
        return $this->hasMany(SurgicalCaseProcedure::class)->orderBy('position');
    }

    public function materials(): HasMany
    {
        return $this->hasMany(SurgicalCaseMaterial::class)->orderBy('position');
    }

    public function iolPlans(): HasMany
    {
        return $this->hasMany(SurgicalCaseIolPlan::class);
    }

    public function exams(): HasMany
    {
        return $this->hasMany(SurgicalCaseExam::class);
    }

    public function team(): HasMany
    {
        return $this->hasMany(SurgicalTeamMember::class)->orderBy('position');
    }

    public function authorizationEvents(): HasMany
    {
        return $this->hasMany(SurgicalAuthorizationEvent::class)->orderBy('occurred_at')->orderBy('created_at')->orderBy('id');
    }

    /** Registro do ato "cirurgia realizada" (M4). */
    public function operation(): HasOne
    {
        return $this->hasOne(SurgicalOperation::class);
    }

    /** Descrição cirúrgica (M4): rascunho ou finalizada. */
    public function description(): HasOne
    {
        return $this->hasOne(SurgicalDescription::class);
    }

    /** Implantes do caso (M4), vigentes e anulados. */
    public function implants(): HasMany
    {
        return $this->hasMany(SurgicalImplant::class)->orderBy('implanted_at')->orderBy('id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(SurgeryDocumentIssue::class)->orderByDesc('issued_at');
    }

    /**
     * O pedido vai a uma operadora (convênio real, com registro na ANS)?
     * Paciente "Particular" não passa por autorização de terceiro: a secretária
     * dispensa a etapa (evento `waived`) e o TUSS deixa de ser obrigatório.
     */
    public function requiresInsurer(): bool
    {
        $covenant = $this->covenant;

        return $covenant !== null && ! $covenant->isParticular() && filled($covenant->ans_registry);
    }

    /** Janela agendada (início e fim definidos). */
    public function hasWindow(): bool
    {
        return $this->scheduled_start !== null && $this->scheduled_end !== null;
    }

    /** Autorização vencida pela validade (mesmo sem o job diário ter gravado o evento). */
    public function authorizationIsExpired(): bool
    {
        return $this->authorization_valid_until !== null
            && $this->authorization_valid_until->endOfDay()->isPast();
    }

    /** Casos que disputam sala/cirurgião (agendados ou já em execução no dia). */
    public function scopeOccupyingWindow($query)
    {
        return $query->whereIn('status', [SurgicalCaseStatus::Scheduled->value, SurgicalCaseStatus::Performed->value])
            ->whereNotNull('scheduled_start')
            ->whereNotNull('scheduled_end');
    }
}
