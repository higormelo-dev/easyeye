<?php

declare(strict_types=1);

namespace App\Models\Surgery;

use App\Models\Concerns\BelongsToEntity;
use App\Models\EntityProduct;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** OPME / material especial solicitado no caso (vai ao anexo de OPME TISS). */
class SurgicalCaseMaterial extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasUuids;

    protected $fillable = [
        'entity_id',
        'surgical_case_id',
        'entity_product_id',
        'description',
        'quantity',
        'manufacturer',
        'anvisa_registration',
        'manufacturer_ref',
        'operating_authorization',
        'manufacturer_option',
        'unit_value',
        'technical_justification',
        'authorized_quantity',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'quantity'            => 'integer',
            'authorized_quantity' => 'integer',
            'position'            => 'integer',
            'unit_value'          => 'decimal:2',
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
}
