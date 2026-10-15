<?php

declare(strict_types=1);

namespace App\Models\Surgery;

use App\Enums\Surgery\SurgeryFacilityType;
use App\Models\Concerns\{BelongsToEntity, BindsRouteToSessionEntity};
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Local onde se opera: clínica própria, hospital ou centro cirúrgico.
 * CNES e código na operadora vão para a solicitação de internação TISS.
 *
 * @property SurgeryFacilityType $type
 */
class SurgeryFacility extends Model
{
    use Auditable;
    use BelongsToEntity;
    use BindsRouteToSessionEntity;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'entity_id',
        'name',
        'type',
        'cnes',
        'operator_code',
        'phone',
        'zip_code',
        'address',
        'address_number',
        'district',
        'city',
        'state',
        'notes',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'type'   => SurgeryFacilityType::class,
            'active' => 'boolean',
        ];
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(SurgeryRoom::class);
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }
}
