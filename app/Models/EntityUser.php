<?php

namespace App\Models;

use App\Presenters\EntityUserPresenter;
use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, BelongsToMany};
use Laracasts\Presenter\PresentableTrait;

class EntityUser extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasUuids;
    use PresentableTrait;
    use SoftDeletes;

    protected $primaryKey = 'id';

    protected $presenter = EntityUserPresenter::class;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'entity_id',
        'user_id',
        'invited_by',
        'code',
        'rule',
        'is_owner',
        'active',
        'joined_at',
    ];

    /**
     * Generated code for the entity_id field.
     */
    protected static function booted(): void
    {
        static::creating(function (self $entityUser) {
            if (blank($entityUser->code)) {
                $prefix = $entityUser->entity->is_client ? 'EU' : 'EUP';

                $lastEntityUser = static::withoutGlobalScopes()
                    ->where('code', 'like', $prefix . '-%')
                    ->orderBy('code', 'desc')
                    ->first();

                if ($lastEntityUser) {
                    $lastNumber = (int) substr($lastEntityUser->code, strlen($prefix) + 1);
                    $newNumber  = $lastNumber + 1;
                } else {
                    $newNumber = 1;
                }

                $entityUser->code = sprintf('%s-%010d', $prefix, $newNumber);
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
            'is_owner'   => 'boolean',
            'active'     => 'boolean',
            'joined_at'  => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id', 'id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by', 'id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'id', 'entity_user_id');
    }

    /**
     * Perfis customizados (RBAC granular ADITIVO) atribuídos a esta
     * membership. Camada opt-in por cima da rule fixa (entity_users.rule) —
     * ver App\Traits\HasEntityRoles::hasPermissionInEntity().
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'entity_user_role')
            ->using(EntityUserRole::class)
            ->withTimestamps();
    }
}
