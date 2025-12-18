<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\{Model, Relations\HasMany, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\HasApiTokens;

class EntityIntegrator extends Model
{
    use HasUuids;
    use HasFactory;
    use HasApiTokens;
    use SoftDeletes;

    protected $primaryKey = 'id';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'entity_id',
        'name',
        'token',
        'ip',
        'mac',
        'token_session',
        'token_session_expires_at',
        'active',
    ];

    protected $casts = [
        'token_session_expires_at' => 'datetime',
        'active'                   => 'boolean',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id', 'id');
    }

    public function equipments(): HasMany
    {
        return $this->hasMany(EntityIntegratorEquipment::class, 'id', 'integrator_id');
    }
}
