<?php

declare(strict_types=1);

namespace App\Models\Surgery;

use App\Models\ClinicResource;
use App\Models\Concerns\{BelongsToEntity, BindsRouteToSessionEntity};
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sala cirúrgica de um local. `clinic_resource_id` (opcional) liga a sala da
 * própria clínica a um recurso da Agenda para reaproveitar escala e bloqueios.
 */
class SurgeryRoom extends Model
{
    use Auditable;
    use BelongsToEntity;
    use BindsRouteToSessionEntity;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'entity_id',
        'surgery_facility_id',
        'clinic_resource_id',
        'name',
        'active',
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(SurgeryFacility::class, 'surgery_facility_id');
    }

    public function clinicResource(): BelongsTo
    {
        return $this->belongsTo(ClinicResource::class);
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }
}
