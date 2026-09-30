<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DoctorPayout\DoctorPayoutDeductionKind;
use App\Models\Concerns\BelongsToEntity;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Support\Str;

/**
 * Taxa de dedução do repasse com vigência — ver doc da migration
 * `add_doctor_payout_split_and_deductions`. Vale a de maior valid_from ≤ data
 * do recebimento; excluir uma volta a valer a anterior.
 */
class DoctorPayoutDeductionRate extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasAuditColumns;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'entity_id',
        'kind',
        'percentage',
        'valid_from',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'kind'       => DoctorPayoutDeductionKind::class,
            'percentage' => 'decimal:2',
            'valid_from' => 'date',
        ];
    }

    /** Rota só resolve taxa da clínica da sessão; id que não é UUID = 404. */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        $entityId = session('selected_entity_id');

        abort_unless($entityId, 403);

        $field ??= $this->getRouteKeyName();

        abort_if($field === $this->getKeyName() && ! Str::isUuid((string) $value), 404);

        return static::query()
            ->where($field, $value)
            ->where('entity_id', $entityId)
            ->firstOrFail();
    }
}
