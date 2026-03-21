<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\{Model, Relations\BelongsTo, Relations\HasMany, SoftDeletes};

class EntityIntegratorEquipment extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'entity_integrator_equipments';

    protected $primaryKey = 'id';

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
     * Get the mac attribute (uppercase) - PostgreSQL macaddr type stores in lowercase.
     */
    protected function mac(): Attribute
    {
        return Attribute::make(
            get: static fn (?string $value) => $value !== null ? mb_strtoupper($value, 'UTF-8') : null,
        );
    }

    /**
     * Boot the model and register events.
     */
    protected static function booted(): void
    {
        // Convert fields to uppercase before saving
        static::saving(static function (self $model) {
            foreach (self::UPPERCASE_FIELDS as $field) {
                $value = $model->getAttribute($field);

                if (is_string($value)) {
                    $model->{$field} = mb_strtoupper($value, 'UTF-8');
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

    public function patientExams(): HasMany
    {
        return $this->hasMany(PatientExam::class, 'entity_integrator_equipment_id');
    }
}
