<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
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
            'created_at'        => 'datetime',
            'updated_at'        => 'datetime',
            'deleted_at'        => 'datetime',
        ];
    }

    public function entityUsers(): HasMany
    {
        return $this->hasMany(EntityUser::class, 'user_id', 'id');
    }
}
