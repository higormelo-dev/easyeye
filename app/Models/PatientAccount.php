<?php

declare(strict_types=1);

namespace App\Models;

use App\Notifications\PatientPasswordReset;
use App\Traits\{Auditable, HasAuditColumns};
use Database\Factories\PatientAccountFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use SensitiveParameter;

/**
 * Conta de acesso do Portal do Paciente — guard "patient" (config/auth.php),
 * tabela e provider PRÓPRIOS. NUNCA vincular ao guard "web"/model User de
 * staff: identidade externa (paciente) não pode se misturar com a ACL
 * entity-scoped de staff.
 *
 * person_id (UNIQUE) = cadastro TITULAR da conta. Cada clínica tem o seu
 * cadastro (People) do paciente; os de outras clínicas entram na conta só
 * quando o PRÓPRIO paciente aceita o convite de cada uma (links(), ver
 * PatientAccountLinkService). Posse de Patient/documento no portal: SEMPRE
 * via linkedPersonIds() — nunca comparar só com person_id.
 *
 * Estende Illuminate\Foundation\Auth\User (mesma base já usada por User e
 * EntityUserIntegrator neste projeto) em vez de compor manualmente os
 * contratos/traits de Authenticatable — ela já entrega Authenticatable,
 * Authorizable, CanResetPassword e MustVerifyEmail prontos, e CanResetPassword
 * é exigido pelo Password::broker('patients') usado no fluxo de "esqueci
 * minha senha" (item 5 do escopo).
 */
class PatientAccount extends Authenticatable implements MustVerifyEmail
{
    use Auditable;

    /** @use HasFactory<PatientAccountFactory> */
    use HasAuditColumns;

    use HasFactory;
    use HasUuids;
    use Notifiable;
    use SoftDeletes;

    protected $primaryKey = 'id';

    /** @var list<string>|null */
    private ?array $linkedPersonIdsCache = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'person_id',
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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at'  => 'datetime',
            'password'           => 'hashed',
            'two_factor_enabled' => 'boolean',
            'active'             => 'boolean',
            'created_at'         => 'datetime',
            'updated_at'         => 'datetime',
            'deleted_at'         => 'datetime',
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(People::class, 'person_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(PatientAccountLink::class, 'patient_account_id');
    }

    /**
     * Cadastros (People) desta conta: o titular + os vinculados pelo paciente.
     * Memorizado por instância (várias checagens de posse por request).
     *
     * @return list<string>
     */
    public function linkedPersonIds(): array
    {
        return $this->linkedPersonIdsCache ??= $this->links()
            ->pluck('person_id')
            ->push($this->person_id)
            ->filter()
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** O cadastro pertence a esta conta? */
    public function ownsPerson(?string $personId): bool
    {
        return $personId !== null && in_array((string) $personId, $this->linkedPersonIds(), true);
    }

    /**
     * BUGFIX (achado da revisão de segurança da área do paciente): sem este
     * override, CanResetPassword::sendPasswordResetNotification() dispara a
     * notificação PADRÃO do Laravel, que monta o link via
     * route('password.reset', ...) — nome que já pertence à tela de reset
     * do STAFF. Todo paciente recebia link pra tela errada e o reset falhava
     * sempre. Ver App\Notifications\PatientPasswordReset.
     */
    public function sendPasswordResetNotification(#[SensitiveParameter] $token): void
    {
        $this->notify(new PatientPasswordReset($token));
    }
}
