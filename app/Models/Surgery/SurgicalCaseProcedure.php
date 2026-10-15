<?php

declare(strict_types=1);

namespace App\Models\Surgery;

use App\Models\Concerns\BelongsToEntity;
use App\Models\Procedure;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Procedimento (TUSS) pedido no caso cirúrgico. */
class SurgicalCaseProcedure extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasUuids;

    /** Vias de acesso TISS (1 única, 2 múltiplas, 3 mesma via). */
    public const ACCESS_ROUTES = ['single', 'multiple', 'same'];

    protected $fillable = [
        'entity_id',
        'surgical_case_id',
        'procedure_id',
        'tuss_code',
        'description',
        'quantity',
        'access_route',
        'authorized_quantity',
        'position',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'authorized_quantity' => 'integer', 'position' => 'integer'];
    }

    public function surgicalCase(): BelongsTo
    {
        return $this->belongsTo(SurgicalCase::class);
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }
}
