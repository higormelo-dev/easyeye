<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\{Model, Relations\HasMany, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

class EntityIntegrator extends Model
{
    use HasApiTokens;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $primaryKey = 'id';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'entity_user_integrator_id',
        'code',
        'name',
        'token',
        'ip',
        'mac',
        'token_session',
        'token_session_expires_at',
        'active',
    ];

    /**
     * Generated code for the entity_user_integrator_id field
     */
    protected static function booted(): void
    {
        static::creating(static function (self $entityIntegrator) {
            if (blank($entityIntegrator->code)) {
                $entityId = $entityIntegrator->user->entity_id;

                DB::transaction(static function () use ($entityIntegrator, $entityId) {
                    $prefix   = 'EI';
                    $lastCode = static::withoutGlobalScopes()
                        ->whereHas('user', fn ($q) => $q->where('entity_id', $entityId))
                        ->where('code', 'like', $prefix . '-%')
                        ->lockForUpdate() // trava as linhas
                        ->orderByDesc('code')
                        ->value('code');

                    $lastNumber = $lastCode
                        ? (int) substr($lastCode, strlen($prefix) + 1)
                        : 0;

                    $entityIntegrator->code = sprintf('%s-%010d', $prefix, $lastNumber + 1);
                });
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
            'token_session_expires_at' => 'datetime',
            'active'                   => 'boolean',
            'created_at'               => 'datetime',
            'updated_at'               => 'datetime',
            'deleted_at'               => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(EntityUserIntegrator::class, 'entity_user_integrator_id', 'id');
    }

    public function equipments(): HasMany
    {
        return $this->hasMany(EntityIntegratorEquipment::class, 'id', 'integrator_id');
    }
}
