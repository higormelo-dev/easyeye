<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToEntity;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo};

/**
 * De-para de convênio da importação de pacientes (por clínica): o nome como veio na planilha, normalizado por
 * App\Support\ImportNameNormalizer, aponta para um convênio do catálogo e, opcionalmente, para um plano (que prevalece
 * sobre a coluna "plano" da planilha). Sem SoftDeletes: remover o de-para apaga a linha e o Auditable registra a exclusão.
 */
class PatientImportCovenantMapping extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasUuids;

    protected $fillable = ['entity_id', 'source_name', 'source_name_normalized', 'covenant_id', 'covenant_plan_id'];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function covenant(): BelongsTo
    {
        // Sem escopos (inclusive soft delete): o alvo removido/inativo é detectado pelo resolvedor, não escondido aqui.
        return $this->belongsTo(Covenant::class)->withoutGlobalScopes();
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(CovenantPlan::class, 'covenant_plan_id')->withoutGlobalScopes();
    }
}
