<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\{UrgentCarePriority, UrgentCareReason, UrgentCareStatus};
use App\Models\Concerns\BelongsToEntity;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Paciente na fila do pronto atendimento (demanda espontânea do dia).
 * Ver App\Services\UrgentCare\UrgentCareService.
 *
 * @property UrgentCareStatus   $status
 * @property UrgentCarePriority $priority
 * @property UrgentCareReason   $reason
 */
class UrgentCareEntry extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasUuids;

    protected $fillable = [
        'entity_id',
        'patient_id',
        'covenant_id',
        'preferred_doctor_id',
        'reason',
        'reason_details',
        'priority',
        'status',
        'arrived_at',
        'called_at',
        'call_count',
        'attended_at',
        'schedule_id',
        'attended_by_doctor_id',
        'closed_at',
        'closed_reason',
    ];

    protected function casts(): array
    {
        return [
            'reason'      => UrgentCareReason::class,
            'priority'    => UrgentCarePriority::class,
            'status'      => UrgentCareStatus::class,
            'arrived_at'  => 'datetime',
            'called_at'   => 'datetime',
            'attended_at' => 'datetime',
            'closed_at'   => 'datetime',
            'call_count'  => 'integer',
        ];
    }

    /**
     * Binding de rota sempre na clínica da sessão: SubstituteBindings roda
     * antes do tenant.bind (EntityScope ainda inerte) — mesmo guarda de
     * Schedule::resolveRouteBinding().
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        abort_unless(Str::isUuid((string) $value), 404);

        return static::query()
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->where('entity_id', (string) session('selected_entity_id'))
            ->firstOrFail();
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function covenant(): BelongsTo
    {
        return $this->belongsTo(Covenant::class);
    }

    public function preferredDoctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'preferred_doctor_id');
    }

    public function attendedByDoctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'attended_by_doctor_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }
}
