<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, SoftDeletes};

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
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected array $uppercaseFields = [
        'name',
        'mac',
        'serial_number',
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

    public function integrator(): BelongsTo
    {
        return $this->belongsTo(EntityIntegrator::class, 'integrator_id', 'id');
    }

    /**
     * Override setAttribute to automatically uppercase specified fields.
     */
    public function setAttribute($key, $value): mixed
    {
        if (is_string($value) && isset($this->uppercaseFields) && in_array($key, $this->uppercaseFields, true)) {
            $value = mb_convert_case($value, MB_CASE_UPPER, 'UTF-8');
        }

        return parent::setAttribute($key, $value);
    }
}
