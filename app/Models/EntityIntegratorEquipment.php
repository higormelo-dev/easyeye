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
        'name',
        'ip',
        'mac',
        'serial_number',
        'active',
    ];
}
