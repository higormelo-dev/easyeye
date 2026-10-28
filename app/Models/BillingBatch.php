<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasEntityCode;
use App\Domains\Tiss\Models\TissBatch;
use App\Enums\BillingBatchStatus;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, Relations\HasMany, SoftDeletes};

class BillingBatch extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasEntityCode;
    use HasUuids;
    use SoftDeletes;

    protected string $codePrefix = 'LOT';

    protected string $codePrefixGlobal = 'LOP';

    protected $fillable = [
        'entity_id',
        'covenant_id',
        'code',
        'status',
        'tiss_version',
        'tiss_layout_version',
        'period_start',
        'period_end',
        'due_date',
        'issued_at',
        'submitted_at',
        'processed_at',
        'total_claims',
        'total_amount',
        'xml_path',
        'notes',
        'tiss_batch_id',
        // Cancelamento (só rascunho): motivo, quem e quando — ver BillingAdjustmentService::cancelBatch().
        'cancel_reason',
        'cancelled_by',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'status'       => BillingBatchStatus::class,
            'period_start' => 'date',
            'period_end'   => 'date',
            'due_date'     => 'date',
            'issued_at'    => 'datetime',
            'submitted_at' => 'datetime',
            'processed_at' => 'datetime',
            'total_claims' => 'integer',
            'total_amount' => 'decimal:2',
            'cancelled_at' => 'datetime',
            'created_at'   => 'datetime',
            'updated_at'   => 'datetime',
            'deleted_at'   => 'datetime',
        ];
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        // Sessão sem entity_id (ex.: vínculo desativado em sessão já aberta) não
        // pode virar "sem filtro" — isso resolveria qualquer registro do sistema
        // pelo ID, quebrando isolamento entre clínicas.
        $entityId = session('selected_entity_id');

        abort_unless($entityId, 403);

        return static::where($field ?? $this->getRouteKeyName(), $value)
            ->where('entity_id', $entityId)
            ->firstOrFail();
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function covenant(): BelongsTo
    {
        return $this->belongsTo(Covenant::class, 'covenant_id');
    }

    public function claims(): HasMany
    {
        return $this->hasMany(BillingClaim::class, 'batch_id');
    }

    public function tissBatch(): BelongsTo
    {
        return $this->belongsTo(TissBatch::class, 'tiss_batch_id');
    }

    /** Quem cancelou o lote (nulo se não cancelado ou se o usuário foi excluído). */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
