<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};

class EntityIntegratorEquipment extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $primaryKey = 'id';

    protected $table = 'entity_integrator_equipments';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'integrator_id',
        'code',
        'name',
        'ip',
        'mac',
        'serial_number',
        'active',
    ];

    /**
     * Generated code for the integrator_id field
     */
    protected static function booted(): void
    {
        static::creating(function (self $entityIntegratorEquipment) {
            if (blank($entityIntegratorEquipment->code)) {
                $prefix = 'EIQ';

                $lastEquipment = static::withoutGlobalScopes()
                    ->where('integrator_id', $entityIntegratorEquipment->integrator_id)
                    ->where('code', 'like', $prefix . '-%')
                    ->orderBy('code', 'desc')
                    ->first();

                if ($lastEquipment) {
                    $lastNumber = (int) substr($lastEquipment->code, strlen($prefix) + 1);
                    $newNumber  = $lastNumber + 1;
                } else {
                    $newNumber = 1;
                }

                $entityIntegratorEquipment->code = sprintf('%s-%010d', $prefix, $newNumber);
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
}
