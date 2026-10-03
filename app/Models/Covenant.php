<?php

namespace App\Models;

use App\Concerns\{HasEntityCode, HasUppercaseFields};
use App\Domains\Tiss\Models\TissOperator;
use App\Enums\CovenantSource;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, Relations\HasMany, SoftDeletes};

class Covenant extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasEntityCode;
    use HasFactory;
    use HasUppercaseFields;
    use HasUuids;
    use SoftDeletes;

    protected string $codePrefix = 'CV';

    protected string $codePrefixGlobal = 'CVP';

    protected $fillable = [
        'entity_id', 'code', 'name', 'color', 'table', 'active', 'tiss_operator_id',
        // Dados oficiais da operadora (ANS) — preenchidos pelo manager
        // (Manager\CovenantsController) e pela importação da ANS.
        'company_name', 'trade_name', 'national_registry', 'ans_registry', 'ans_modality', 'city', 'uf',
        'ans_registered_at', 'ans_status', 'ans_cancelled_at', 'ans_cancellation_reason', 'source', 'source_synced_at',
    ];

    protected array $uppercaseFields = ['name', 'color'];

    protected function casts(): array
    {
        return [
            'created_at'        => 'datetime',
            'updated_at'        => 'datetime',
            'deleted_at'        => 'datetime',
            'ans_registered_at' => 'date',
            'ans_cancelled_at'  => 'date',
            'source_synced_at'  => 'datetime',
            'source'            => CovenantSource::class,
        ];
    }

    /**
     * O PARTICULAR global (sem registro ANS) — várias regras dependem dele
     * pelo nome (cadastro de paciente, importações, repasse médico), então o
     * manager não deixa renomear, desativar nem excluir.
     */
    public function isParticular(): bool
    {
        return $this->entity_id === null
            && blank($this->ans_registry)
            && mb_strtoupper(trim((string) $this->name)) === 'PARTICULAR';
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id', 'id');
    }

    public function tissOperator(): BelongsTo
    {
        return $this->belongsTo(TissOperator::class, 'tiss_operator_id');
    }

    /** Planos do convênio (globais da ANS/manager + próprios das clínicas). */
    public function plans(): HasMany
    {
        return $this->hasMany(CovenantPlan::class);
    }
}
