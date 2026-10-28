<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Período de caixa fechado. Soft-delete = período reaberto; a reabertura grava
 * motivo, quem e quando (reopen_reason/reopened_by/reopened_at) na mesma linha.
 */
class CashClose extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'entity_id',
        'closed_by',
        'period_start',
        'period_end',
        'closed_at',
        'total_income',
        'total_expense',
        'balance',
        'notes',
        'reopen_reason',
        'reopened_by',
        'reopened_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start'  => 'date',
            'period_end'    => 'date',
            'closed_at'     => 'datetime',
            'reopened_at'   => 'datetime',
            'total_income'  => 'decimal:2',
            'total_expense' => 'decimal:2',
            'balance'       => 'decimal:2',
        ];
    }

    /**
     * {cashClose} só resolve fechamento da clínica da sessão: o de outra
     * clínica vira 404 antes de qualquer validação (o controller ainda confere).
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        $entityId = session('selected_entity_id');

        abort_unless($entityId, 403);

        $field ??= $this->getRouteKeyName();

        // Id que não é UUID nunca chega ao PostgreSQL (22P02 → 500).
        abort_if($field === $this->getKeyName() && ! Str::isUuid((string) $value), 404);

        return static::query()
            ->where($field, $value)
            ->where('entity_id', $entityId)
            ->firstOrFail();
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }
}
