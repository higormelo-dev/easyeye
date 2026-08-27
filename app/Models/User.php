<?php

namespace App\Models;

use App\Traits\{Auditable, HasAuditColumns, HasEntityRoles};
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\{BelongsToMany, HasMany, HasOne};
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    use Auditable;

    /** @use HasFactory<UserFactory> */
    use HasAuditColumns;

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
        'phone',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        // 2FA: nunca exposto em JSON. Apenas TwoFactorService deve ler.
        'two_factor_secret',
        'two_factor_recovery_codes',
        // OTP de verificação de WhatsApp: hash nunca exposto em JSON.
        'phone_verification_code',
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
            // 2FA: secrets criptografados com APP_KEY. Recovery codes
            // são armazenados como JSON criptografado (o conteúdo já é
            // hashed individualmente — defesa em profundidade).
            'two_factor_secret'         => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted',
            'two_factor_confirmed_at'   => 'datetime',
            // Verificação de WhatsApp do responsável (OTP via Z-API)
            'phone_verified_at'             => 'datetime',
            'phone_verification_expires_at' => 'datetime',
        ];
    }

    /**
     * 2FA está habilitado e CONFIRMADO (usuário digitou código válido após setup).
     * Setup incompleto (two_factor_secret presente mas confirmed_at NULL)
     * NÃO conta como 2FA habilitado — atacante poderia colocar um secret
     * dummy para tentar bypass.
     *
     * Usa `getRawOriginal()` para NÃO acionar o cast `encrypted` durante a
     * checagem de presença. Se o secret estiver corrompido (APP_KEY mudou
     * entre ambientes, valor inserido fora da camada de cast, etc.), o
     * cast lançaria DecryptException e quebraria o middleware antes mesmo
     * de o usuário conseguir abrir a tela de setup para resolver.
     *
     * Quando o secret precisa ser DE FATO decifrado (verificação de código),
     * o TwoFactorService faz isso com try/catch — invalidando 2FA
     * silenciosamente em caso de falha de decifração.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->getRawOriginal('two_factor_secret') !== null
            && $this->getRawOriginal('two_factor_confirmed_at') !== null;
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

    public function preference(): HasOne
    {
        return $this->hasOne(UserPreference::class);
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
