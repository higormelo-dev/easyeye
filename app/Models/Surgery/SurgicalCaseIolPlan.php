<?php

declare(strict_types=1);

namespace App\Models\Surgery;

use App\Enums\Surgery\SurgeryEye;
use App\Models\Concerns\BelongsToEntity;
use App\Models\{Doctor, EntityProduct};
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LIO planejada por olho. O sistema NÃO calcula a potência: registra o que o
 * cirurgião informou (modelo, potência, fórmula, refração-alvo e a refração
 * que a fórmula previu) — a previsão alimenta o indicador de erro refracional.
 *
 * @property SurgeryEye $eye
 */
class SurgicalCaseIolPlan extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasUuids;

    protected $fillable = [
        'entity_id',
        'surgical_case_id',
        'eye',
        'entity_product_id',
        'model_label',
        'power',
        'cylinder',
        'axis',
        'formula',
        'a_constant',
        'target_refraction',
        'predicted_refraction',
        'planned_by_doctor_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'eye'                  => SurgeryEye::class,
            'power'                => 'decimal:2',
            'cylinder'             => 'decimal:2',
            'axis'                 => 'integer',
            'a_constant'           => 'decimal:2',
            'target_refraction'    => 'decimal:2',
            'predicted_refraction' => 'decimal:2',
        ];
    }

    public function surgicalCase(): BelongsTo
    {
        return $this->belongsTo(SurgicalCase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(EntityProduct::class, 'entity_product_id');
    }

    public function plannedBy(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'planned_by_doctor_id');
    }
}
