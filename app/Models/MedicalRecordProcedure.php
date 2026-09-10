<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MedicalRecordProcedureStatus;
use App\Models\Concerns\BelongsToEntity;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\{Builder, Model, SoftDeletes};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

/**
 * Solicitação/execução ESTRUTURADA de procedimento — ver doc da migration
 * `create_medical_record_procedures_table`. Complementa (não substitui) o
 * texto livre de App\Services\ProcedureSolicitationService.
 *
 * Ciclo de vida: requested → done|cancelled (App\Enums\
 * MedicalRecordProcedureStatus::canTransitionTo()). Escrita restrita a
 * médico via Gate IssueReport — ver
 * App\Http\Controllers\MedicalRecordProceduresController (mesmo padrão de
 * MedicalRecordEvolutionsController).
 */
class MedicalRecordProcedure extends Model
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
        'procedure_id',
        'doctor_id',
        'eye',
        'solicitation_type',
        'status',
        'notes',
        'executed_at',
        'executed_by',
    ];

    /**
     * Default EM PHP (não só no banco): `status` tem `->default('requested')`
     * na migration, mas isso só se aplica quando a coluna fica de fora do
     * INSERT — um `create([...])` sem 'status' explícito deixaria o atributo
     * ausente NO OBJETO EM MEMÓRIA (Eloquent não re-busca defaults de banco
     * depois de criar), e `$model->status->canTransitionTo(...)` logo em
     * seguida quebraria com "member function on null". Sem `->fresh()` no
     * meio, esse era exatamente o caminho de MedicalRecordProceduresController
     * ::store() → serialize() em produção — pego pelo teste de unidade do
     * service, não por acaso.
     */
    protected $attributes = [
        'status' => 'requested',
    ];

    protected function casts(): array
    {
        return [
            'status'      => MedicalRecordProcedureStatus::class,
            'executed_at' => 'datetime',
        ];
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

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }

    public function executedBy(): BelongsTo
    {
        return $this->belongsTo(EntityUser::class, 'executed_by');
    }

    /** Movimentos de estoque gerados ao confirmar o consumo — ver StockMovement.reference_*. */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'reference_id')
            ->where('reference_type', self::class);
    }

    public function scopeRequested(Builder $query): Builder
    {
        return $query->where('status', MedicalRecordProcedureStatus::Requested);
    }

    public function isDone(): bool
    {
        return $this->status === MedicalRecordProcedureStatus::Done;
    }
}
