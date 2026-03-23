<?php

namespace App\Models;

use App\Traits\{Auditable, HasAuditColumns, HasEntityRoles};
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasAuditColumns;
    use Auditable;
    use HasEntityRoles;
    use HasFactory;
    use HasUuids;
    use Notifiable;
    use SoftDeletes;

    protected $primaryKey = 'id';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'locale',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Roles of clients
     *
     * 'admin'     => 'Administrador',
     * 'financial' => 'Financeiro',
     * 'doctor'    => 'Médico',
     * 'secretary' => 'Secretária',
     * 'user'      => 'Usuário Comum',
     */
    public static array $rolesOfClients = [
        'admin'     => 'Administrador',
        'financial' => 'Financeiro',
        'doctor'    => 'Médico',
        'secretary' => 'Secretária',
        'user'      => 'Usuário Comum',
    ];

    /**
     * Roles of the management system
     *
     * 'admin'     => 'Administrador',
     * 'financial' => 'Financeiro',
     * 'support'   => 'Suporte',
     * 'user'      => 'Usuário Comum',
     */
    public static array $rolesOfManager = [
        'admin'     => 'Administrador',
        'financial' => 'Financeiro',
        'support'   => 'Suporte',
        'user'      => 'Usuário Comum',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'active'            => 'boolean',
            'created_at'        => 'datetime',
            'updated_at'        => 'datetime',
            'deleted_at'        => 'datetime',
        ];
    }

    public function entities(): BelongsToMany
    {
        return $this->belongsToMany(Entity::class, 'entity_users', 'user_id', 'entity_id')
            ->withPivot(['id', 'code', 'rule', 'is_owner', 'active', 'invited_by', 'joined_at'])
            ->withTimestamps()
            ->wherePivotNull('deleted_at');
    }

    public function entityUsers(): HasMany
    {
        return $this->hasMany(EntityUser::class, 'user_id', 'id');
    }

    protected function name(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value) => $value !== null
                ? mb_convert_case($value, MB_CASE_UPPER, 'UTF-8')
                : null,
        );
    }
}
