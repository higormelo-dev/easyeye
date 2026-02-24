<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
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
     * Fields that should be converted to UPPERCASE before saving.
     */
    private const UPPERCASE_FIELDS = ['name', 'mac', 'serial_number'];

    /**
     * Boot the model and register events.
     */
    protected static function booted(): void
    {
        // Convert fields to uppercase before saving
        static::saving(static function (self $model) {
            foreach (self::UPPERCASE_FIELDS as $field) {
                if (isset($model->attributes[$field]) && is_string($model->attributes[$field])) {
                    $model->attributes[$field] = mb_strtoupper($model->attributes[$field], 'UTF-8');
                }
            }
        });

        // Generate code on creating
        static::creating(static function (self $entityIntegratorEquipment) {
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

}
